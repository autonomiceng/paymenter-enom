<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Classes\Price;
use App\Classes\Settings;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Servers\Enom\Jobs\BillDomain;
use Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use RuntimeException;

/** Registrar obligations use native invoices without native service renewal. */
final class DomainBilling
{
    public const LATE_INVOICE_MESSAGE = 'The renewal invoice deadline has passed. Contact support for review before a new invoice can be issued.';

    public function __construct(private DomainOperations $operations) {}

    public function policy(Domain $domain): array
    {
        $settings = $domain->service?->product?->server?->settings->pluck('value', 'key');
        $overrides = $domain->renewal_policy ?? [];
        if (!is_array($overrides)) { throw new RuntimeException('Domain renewal policy requires review.'); }
        $policy = array_replace([
            'version' => 1, 'enabled' => $settings?->get('billing_enabled', false) ?? false,
            'issue_days' => $settings?->get('issue_days', 21) ?? 21,
            'due_days' => $settings?->get('due_days', 7) ?? 7,
            'term' => (int) ($domain->service?->plan?->billing_period ?? 0),
            'credits_auto_use' => $settings?->get('credits_auto_use', false) ?? false,
        ], $overrides);
        foreach (['issue_days', 'due_days', 'term'] as $field) {
            if (filter_var($policy[$field], FILTER_VALIDATE_INT) === false) { throw new RuntimeException('Domain renewal timing requires review.'); }
            $policy[$field] = (int) $policy[$field];
        }
        if ($policy['issue_days'] <= $policy['due_days'] || $policy['due_days'] < 1 || $policy['issue_days'] > 365 || $policy['term'] !== 1) {
            throw new RuntimeException('Supported renewal timing requires issue before due and a one-year term.');
        }
        foreach (['enabled', 'credits_auto_use'] as $field) {
            $boolean = filter_var($policy[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($boolean === null) { throw new RuntimeException('Domain renewal policy requires review.'); }
            $policy[$field] = $boolean;
        }
        return $policy;
    }

    public function calendar(Domain $domain): array
    {
        if (!$domain->provider_expires_at) { throw new RuntimeException('Confirmed registrar expiry is unavailable.'); }
        $policy = $this->policy($domain);
        return ['expiry' => $domain->provider_expires_at->format('Y-m-d'),
            'issue' => $domain->provider_expires_at->subDays($policy['issue_days'])->format('Y-m-d'),
            'due' => $domain->provider_expires_at->subDays($policy['due_days'])->format('Y-m-d')];
    }

    public function invoice(int $domainId, ?User $actor = null, ?string $nonce = null): Invoice
    {
        return DB::transaction(function () use ($domainId, $actor) {
            $domain = Domain::lockForUpdate()->findOrFail($domainId);
            $service = Service::lockForUpdate()->findOrFail($domain->service_id);
            $domain->setRelation('service', $service);
            if ($actor && (int) $actor->id !== (int) $domain->user_id) { throw new RuntimeException('Domain renewal is unavailable.'); }
            $this->assertBinding($domain, $service);
            if (DomainOperations::writesPaused($domain)) { throw new RuntimeException('Domain renewal requires staff review.'); }
            if (!$this->registrarEnabled($domain)) { throw new RuntimeException('Registrar fulfillment is disabled.'); }
            if ($service->status !== Service::STATUS_ACTIVE || $service->cancellation()->exists()) {
                throw new RuntimeException('Domain renewal lifecycle requires review.');
            }
            $calendar = $this->calendar($domain);
            $key = 'renew:'.$domain->id.':'.$calendar['expiry'];
            $cycle = Operation::where('domain_id', $domain->id)->where('action', 'renew')
                ->whereDate('base_expires_at', $calendar['expiry'])
                ->where(fn ($query) => $query->where('operation_key', $key)->orWhere('operation_key', 'like', $key.':replacement:%'));
            if ($existing = (clone $cycle)->orderByDesc('id')->lockForUpdate()->first()) {
                $existingInvoice = $this->existingInvoice($existing, $domain, $service);
                if ($existing->safe_code !== 'renewal_cancelled_unpaid') {
                    if ($existingInvoice->status === Invoice::STATUS_CANCELLED) { throw new RuntimeException('Cancelled renewal billing requires review.'); }
                    return $existingInvoice;
                }
                // Resume creates a new obligation; the retired invoice and
                // operation remain immutable evidence of the prior preference.
                if ($existing->state !== 'rejected' || $existing->funding_basis !== 'paid_invoice'
                    || $existingInvoice->status !== Invoice::STATUS_CANCELLED || $existingInvoice->transactions()->exists()
                    || $existingInvoice->items->count() !== 1 || $existing->provider_order_id
                    || isset($existing->policy['dispatch_attempted_at']) || isset($existing->cost['execution'])
                    || !preg_match('/^'.preg_quote($key, '/').'(?:\:replacement\:[1-9][0-9]*)?$/D', $existing->operation_key)) {
                    throw new RuntimeException('Retired renewal billing requires financial review.');
                }
                $key .= ':replacement:'.((clone $cycle)->count());
            }
            // Grace can settle an issued obligation; it never creates a new
            // overdue customer debt after the confirmed registration has expired.
            if (now()->toDateString() >= $calendar['expiry']) {
                throw new RuntimeException('New renewal billing requires review after registrar expiry.');
            }
            // Keep the agreed calendar. A late first/replacement invoice needs
            // staff review; previously issued obligations were returned above.
            if (now()->toDateString() >= $calendar['due']) { throw new RuntimeException(self::LATE_INVOICE_MESSAGE); }
            $this->assertEligible($domain, $service);
            $policy = $this->policy($domain);
            if (!$policy['enabled']) { throw new RuntimeException('Domain renewal billing is disabled.'); }
            if (!empty($policy['automatic_spend_paused'])) { throw new RuntimeException('Domain renewal spending requires review.'); }
            if (Pricing::isComplimentary($domain)) { throw new RuntimeException('Complimentary domains renew without an invoice.'); }
            // New invoices charge the current catalogue price; issued invoices above keep theirs.
            if (Pricing::mode($domain) === 'standard') { Pricing::syncServicePrice($domain); $service->refresh(); }
            $amount = $this->minor($service->price);
            if ($amount <= 0) { throw new RuntimeException('A zero-price renewal requires explicit sponsorship.'); }
            $lockedPolicy = $this->lockedPolicy($domain, $service, $policy, $calendar);
            $invoice = new Invoice(['user_id' => $domain->user_id, 'currency_code' => $service->currency_code,
                'status' => Invoice::STATUS_PENDING, 'due_at' => $calendar['due']]);
            $invoice->send_create_email = !config('settings.mail_disabled', false);
            $invoice->save();
            $item = $invoice->items()->create(['reference_type' => null, 'reference_id' => null,
                'quantity' => 1, 'price' => $this->decimal($amount),
                'description' => 'Renew '.$domain->domain.' ('.$policy['term'].' year; registrar expiry '.$calendar['expiry'].')']);
            Operation::create(['domain_id' => $domain->id, 'service_id' => $service->id, 'invoice_id' => $invoice->id,
                'invoice_item_id' => $item->id, 'operation_key' => $key, 'action' => 'renew', 'term' => $policy['term'],
                'funding_basis' => 'paid_invoice', 'state' => 'awaiting_payment', 'amount' => $this->decimal($amount),
                'currency' => $service->currency_code, 'base_expires_at' => $calendar['expiry'],
                'expected_expires_at' => $domain->provider_expires_at->addYearNoOverflow()->format('Y-m-d'),
                'policy' => $lockedPolicy, 'payload' => []]);
            if ($policy['credits_auto_use']) { $this->payWithCredits($invoice); }
            // Billing agreements require a separate explicit authorization path.
            return $invoice->fresh();
        });
    }

    private function existingInvoice(Operation $operation, Domain $domain, Service $service): Invoice
    {
        $invoice = Invoice::with('items')->find($operation->invoice_id);
        $item = $invoice?->items->firstWhere('id', $operation->invoice_item_id);
        if (!$invoice || !$item || (int) $operation->domain_id !== (int) $domain->id
            || (int) $operation->service_id !== (int) $service->id || (int) $invoice->user_id !== (int) $domain->user_id
            || $invoice->currency_code !== $operation->currency || $item->reference_type !== null || $item->reference_id !== null
            || (int) $item->quantity !== 1 || $this->minor($item->price) !== $this->minor($operation->amount)) {
            throw new RuntimeException('Existing renewal obligation requires review.');
        }
        return $invoice;
    }

    private function lockedPolicy(Domain $domain, Service $service, array $policy, array $calendar): array
    {
        $price = new Price(['price' => $service->price, 'currency' => $service->currency, 'tax' => Settings::tax($service->user)]);
        unset($policy['sponsorship']);
        $uid = $service->product?->server?->settings->firstWhere('key', 'uid')?->value;
        $account = $policy['account_fingerprint'] ?? (is_string($uid) && $uid !== '' ? RuntimeGuard::serverFingerprint($service->product?->server) : null);
        return array_merge($policy, ['billing_managed' => true, 'dispatch_marker_version' => 1, 'calendar' => $calendar,
            'retail_minor' => $this->minor($service->price), 'original_net_minor' => $this->minor($price->subtotal),
            'currency' => $service->currency_code, 'authority_generation' => (int) $domain->authority_generation,
            'confirmed_revision' => (int) $domain->confirmed_revision,
            'domain' => $domain->domain, 'user_id' => (int) $domain->user_id,
            'account_fingerprint' => $account, 'provider_domain_id' => $domain->provider_domain_id,
            'service_id' => (int) $service->id, 'server_id' => (int) $domain->server_id]);
    }

    private function assertBinding(Domain $domain, Service $service, bool $allowKnownDrift = false): void
    {
        $policy = $domain->renewal_policy ?? [];
        if (!$allowKnownDrift && in_array($domain->observation['expiry_drift'] ?? null, ['earlier', 'later'], true)) {
            throw new RuntimeException('Observed registrar expiry requires review.');
        }
        $uid = $service->product?->server?->settings->firstWhere('key', 'uid')?->value;
        if (isset($policy['account_fingerprint']) && (!is_string($uid) || $uid === ''
            || !hash_equals($policy['account_fingerprint'], RuntimeGuard::serverFingerprint($service->product?->server)))) {
            throw new RuntimeException('Registrar account authority requires review.');
        }
        if (isset($policy['provider_account_identity']) && (!is_string($uid) || $uid === ''
            || !hash_equals($policy['provider_account_identity'], hash('sha256', strtolower(trim($uid)))))) {
            throw new RuntimeException('Registrar account authority requires review.');
        }
        if (!$domain->billing_managed || (int) $domain->user_id !== (int) $service->user_id
            || (int) $domain->server_id !== (int) $service->product?->server_id
            || $service->product?->server?->extension !== 'Enom' || $service->expires_at !== null
            || (int) $service->quantity !== 1 || $service->coupon_id !== null
            || $service->plan?->type !== 'recurring' || $service->plan?->billing_unit !== 'year'
            || (int) $service->plan?->billing_period !== 1 || !$domain->provider_expires_at
            || !preg_match('/^[A-Z]{3}$/D', (string) $service->currency_code)) {
            throw new RuntimeException('Domain service binding requires review.');
        }
    }

    private function assertEligible(Domain $domain, Service $service): void
    {
        if ($service->status !== Service::STATUS_ACTIVE || !in_array($domain->status, ['active', 'expired', 'grace'], true)
            || $service->cancellation()->exists() || DomainOperations::hasRenewalBlocker($domain)) {
            throw new RuntimeException('Domain renewal lifecycle requires review.');
        }
    }

    /** A narrow pre-SQL hook preserves null for explicitly managed Enom services. */
    public function preserveNativeDate(Service $service): void
    {
        if ($service->expires_at === null || !Schema::hasTable('enom_domains')
            || !Schema::hasColumns('enom_domains', ['service_id', 'billing_managed', 'user_id', 'server_id'])) { return; }
        $domain = Domain::where('service_id', $service->id)->first();
        if ($domain?->billing_managed && (int) $domain->user_id === (int) $service->user_id
            && (int) $domain->server_id === (int) $service->product?->server_id
            && $service->product?->server?->extension === 'Enom') {
            $service->expires_at = null;
        }
    }

    private function payWithCredits(Invoice $invoice): void
    {
        $credit = $invoice->user->credits()->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
        $remaining = $this->minor($invoice->fresh()->remaining);
        if ($remaining <= 0 || !$credit || $this->minor($credit->amount) < $remaining) { return; }
        $credit->amount = $this->decimal($this->minor($credit->amount) - $remaining);
        $credit->save();
        ExtensionHelper::addPayment($invoice, null, $this->decimal($remaining), isCreditTransaction: true);
    }

    /** Explicit standing sponsorship never fabricates a customer payment. */
    public function sponsor(int $domainId, ?User $actor = null): ?Operation
    {
        return DB::transaction(function () use ($domainId, $actor) {
            $domain = Domain::lockForUpdate()->findOrFail($domainId);
            $service = Service::lockForUpdate()->findOrFail($domain->service_id);
            $domain->setRelation('service', $service);
            $this->assertBinding($domain, $service);
            if (DomainOperations::writesPaused($domain)) { throw new RuntimeException('Domain renewal requires staff review.'); }
            if (!$this->registrarEnabled($domain)) { throw new RuntimeException('Registrar fulfillment is disabled.'); }
            $calendar = $this->calendar($domain);
            $key = 'sponsored:'.$domain->id.':'.$calendar['expiry'];
            if ($existing = Operation::where('operation_key', $key)->first()) { return $existing; }
            $this->assertEligible($domain, $service);
            $policy = $this->policy($domain);
            $sponsorship = $domain->sponsorship;
            $staff = is_array($sponsorship) ? User::find($sponsorship['approved_by'] ?? null) : null;
            if (!$this->sponsorshipApproved($domain, $service, $policy, $actor)) {
                throw new RuntimeException('Domain sponsorship requires individual approval.');
            }
            // Observation evidence must explicitly establish complete current cost.
            // Base-TLD pricing alone cannot pass this gate.
            $quote = $domain->observation['renewal_quote'] ?? [];
            if (!is_string($quote['observed_at'] ?? null) || $quote['observed_at'] === '') { return null; }
            try { $at = CarbonImmutable::parse($quote['observed_at'] ?? ''); }
            catch (\Throwable) { return null; }
            $quoteAmount = RegistrarMoney::decimal($quote['amount'] ?? null);
            if (!in_array($quote['state'] ?? null, ['quoted', 'confirmed', 'validated'], true) || ($quote['complete'] ?? null) !== true
                || !$quoteAmount || $at->isFuture() || $at->lt(now()->subMinutes(15))
                || ($quote['domain'] ?? null) !== $domain->domain || ($quote['action'] ?? null) !== 'renew'
                || ($quote['term'] ?? null) !== $policy['term'] || ($quote['currency'] ?? null) !== $service->currency_code
                || ($quote['base_expires_at'] ?? null) !== $calendar['expiry']
                || $this->minor($quoteAmount) > $sponsorship['limit_minor']
                || ($domain->provider_expires_at->isPast() && ($quote['grace_eligible'] ?? null) !== true)) { return null; }
            $operation = Operation::create(['domain_id' => $domain->id, 'service_id' => $service->id,
                'operation_key' => $key, 'action' => 'renew', 'term' => $policy['term'], 'funding_basis' => 'sponsored',
                'state' => 'queued', 'amount' => '0.00', 'currency' => $service->currency_code,
                'base_expires_at' => $calendar['expiry'], 'expected_expires_at' => $domain->provider_expires_at->addYearNoOverflow()->format('Y-m-d'),
                'authorized_by' => $actor?->id ?? $staff->id, 'funding_reason' => $sponsorship['reason'],
                'policy' => $this->lockedPolicy($domain, $service, $policy, $calendar) + ['sponsorship' => $sponsorship,
                    'sponsorship_cycle' => $calendar['expiry'], 'single_cycle_authorized' => $actor !== null],
                'cost' => ['quote' => $quote], 'payload' => []]);
            ExecuteOperation::dispatch($operation->id)->afterCommit();
            return $operation;
        });
    }

    /**
     * Queue this cycle's renewal at the reseller's cost. One renewal per expiry cycle,
     * whatever paid for it. Staff may pass themselves as the actor; the daily run passes null.
     */
    public function complimentary(int $domainId, ?User $actor = null): ?Operation
    {
        return DB::transaction(function () use ($domainId, $actor) {
            $domain = Domain::lockForUpdate()->findOrFail($domainId);
            $service = Service::lockForUpdate()->findOrFail($domain->service_id);
            $domain->setRelation('service', $service);
            if (!Pricing::isComplimentary($domain)) { throw new RuntimeException('This domain is not complimentary.'); }
            $this->assertBinding($domain, $service);
            if (DomainOperations::writesPaused($domain)) { throw new RuntimeException('Domain renewal requires staff review.'); }
            if (!$this->registrarEnabled($domain)) { throw new RuntimeException('Processing orders and changes is turned off.'); }
            if ($service->status !== Service::STATUS_ACTIVE || $service->cancellation()->exists()) { throw new RuntimeException('Renewal is turned off for this domain.'); }
            $calendar = $this->calendar($domain);
            $key = 'complimentary:'.$domain->id.':'.$calendar['expiry'];
            if ($existing = Operation::where('operation_key', $key)->first()) { return $existing; }
            $cycle = Operation::where('domain_id', $domain->id)->where('action', 'renew')->whereDate('base_expires_at', $calendar['expiry'])
                ->where(fn ($query) => $query->where('state', '!=', 'rejected')->orWhereNull('state'))->first();
            if ($cycle) { return $cycle->funding_basis === 'complimentary' ? $cycle : null; }
            $this->assertEligible($domain, $service);
            Pricing::syncServicePrice($domain);
            $service->refresh();
            $policy = $this->policy($domain);
            if (!$policy['enabled'] || !empty($policy['automatic_spend_paused'])) { return null; }
            $operation = Operation::create(['domain_id' => $domain->id, 'service_id' => $service->id,
                'operation_key' => $key, 'action' => 'renew', 'term' => $policy['term'], 'funding_basis' => 'complimentary',
                'state' => 'queued', 'amount' => '0.00', 'currency' => $service->currency_code,
                'base_expires_at' => $calendar['expiry'], 'expected_expires_at' => $domain->provider_expires_at->addYearNoOverflow()->format('Y-m-d'),
                'authorized_by' => $actor?->id, 'funding_reason' => $actor ? 'Complimentary renewal requested by staff' : 'Complimentary renewal',
                'policy' => $this->lockedPolicy($domain, $service, $policy, $calendar) + ['complimentary_cap_minor' => Pricing::complimentaryCapMinor((int) $domain->server_id)],
                'payload' => []]);
            ExecuteOperation::dispatch($operation->id)->afterCommit();
            return $operation;
        });
    }

    public function sweep(): array
    {
        $summary = ['queued' => 0];
        Domain::where('billing_managed', true)->orderBy('id')->chunkById(100, function ($domains) use (&$summary) {
            foreach ($domains as $domain) {
                if (!$this->billingLifecycleEnabled($domain)) { continue; }
                if ($domain->service?->cancellation?->type !== 'end_of_period') {
                    try {
                        if (!$this->policy($domain)['enabled'] || !$this->scheduledEnabled($domain)
                            || $domain->service?->status !== Service::STATUS_ACTIVE) { continue; }
                    } catch (RuntimeException) { $this->health((int) $domain->id, 'configuration_review'); continue; }
                }
                BillDomain::dispatch((int) $domain->id);
                $summary['queued']++;
            }
        });
        return $summary;
    }

    public function bill(int $domainId): string
    {
        $domain = Domain::find($domainId);
        if (!$domain?->billing_managed) { return 'ineligible'; }
        // Billing authorizes native invoice/cancellation changes. Registrar
        // operations may stay off while an explicit cancellation completes.
        if (!$this->billingLifecycleEnabled($domain)) { return 'disabled'; }
        if (DomainOperations::writesPaused($domain)) { return 'staff_review'; }
        $review = $this->reviewUnpaidRenewals($domainId);
        if (in_array($review, ['disabled', 'staff_review'], true)) { return $review; }
        if ($domain->service?->cancellation?->type === 'end_of_period') {
            $result = $this->finishCancellation($domainId);
            return in_array($result, ['disabled', 'staff_review'], true) ? $result : $this->health($domainId, $result);
        }
        if ($review !== null) { return $this->health($domainId, $review); }
        try {
            $policy = $this->policy($domain);
            if (!$policy['enabled'] || !$this->scheduledEnabled($domain) || !$domain->provider_expires_at
                || $this->calendar($domain)['issue'] > now()->toDateString()) { return $this->health($domainId, 'not_due'); }
            if (Pricing::isComplimentary($domain)) {
                $this->assertBinding($domain, $domain->service);
                $this->assertEligible($domain, $domain->service);
                return $this->health($domainId, $this->complimentary($domainId) ? 'complimentary' : 'review');
            }
            if ($this->minor($domain->service?->price) === 0) {
                $this->assertBinding($domain, $domain->service);
                if (!$this->sponsorshipApproved($domain, $domain->service, $policy)) { return $this->health($domainId, 'sponsorship_review'); }
                if ($existing = Operation::where('operation_key', 'sponsored:'.$domain->id.':'.$domain->provider_expires_at->format('Y-m-d'))->first()) {
                    return $this->health($domainId, in_array($existing->state, ['queued', 'running', 'pending', 'succeeded'], true) ? 'sponsored' : 'sponsorship_review');
                }
                $this->assertEligible($domain, $domain->service);
                if (app(DomainObservation::class)->observeRenewalQuote($domainId) !== 'quoted') { return $this->health($domainId, 'sponsorship_review'); }
                return $this->health($domainId, $this->sponsor($domainId) ? 'sponsored' : 'sponsorship_review');
            }
            $this->invoice($domainId);
            return $this->health($domainId, 'invoiced');
        } catch (RuntimeException $error) {
            return $this->health($domainId, $error->getMessage() === self::LATE_INVOICE_MESSAGE ? 'invoice_deadline_missed' : 'review');
        }
    }

    private function health(int $domainId, string $result): string
    {
        DB::transaction(function () use ($domainId, $result) {
            $domain = Domain::lockForUpdate()->find($domainId);
            if (!$domain || !$this->billingLifecycleEnabled($domain) || DomainOperations::writesPaused($domain)) { return; }
            $observation = $domain->observation ?? [];
            $observation['billing'] = ['attempted_at' => now()->toIso8601String(), 'result' => $result];
            try {
                $calendar = $this->calendar($domain);
                $observation['billing']['issue_at'] = $calendar['issue'];
                $observation['billing']['deadline_missed'] = $calendar['issue'] < now()->toDateString()
                    && !Operation::where('domain_id', $domainId)->where('action', 'renew')
                        ->whereDate('base_expires_at', $calendar['expiry'])->exists();
            } catch (RuntimeException) { $observation['billing']['deadline_missed'] = null; }
            $domain->update(['observation' => $observation]);
        });
        return $result;
    }

    /** Retire only exact clean unpaid debts whose fulfillment is now unavailable. */
    private function reviewUnpaidRenewals(int $domainId): ?string
    {
        return DB::transaction(function () use ($domainId) {
            $domain = Domain::lockForUpdate()->findOrFail($domainId);
            $service = Service::whereKey($domain->service_id)->lockForUpdate()->first();
            if (!$service) { return 'review'; }
            $domain->setRelation('service', $service);
            if (!$this->billingLifecycleEnabled($domain)) { return 'disabled'; }
            if (DomainOperations::writesPaused($domain)) { return 'staff_review'; }
            // Known drift permits financial review, never weaker ownership,
            // account, native-date or annual-service identity checks.
            try { $this->assertBinding($domain, $service, true); }
            catch (RuntimeException) { return 'review'; }
            $drift = in_array($domain->observation['expiry_drift'] ?? null, ['earlier', 'later'], true);
            $expired = $domain->provider_expires_at && $domain->provider_expires_at->format('Y-m-d') <= now()->toDateString();
            $grace = filter_var($domain->service?->product?->server?->settings->firstWhere('key', 'grace_renewals_enabled')?->value ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$drift && (!$expired || $grace)) { return null; }
            $uid = $service->product->server->settings->firstWhere('key', 'uid')?->value;
            foreach ($domain->operations()->where('action', 'renew')->where('funding_basis', 'paid_invoice')
                ->whereIn('state', ['awaiting_payment', 'held', 'queued'])->whereNotNull('invoice_id')->lockForUpdate()->get() as $operation) {
                $policy = $operation->policy ?? [];
                if ((int) $operation->service_id !== (int) $service->id || $operation->currency !== $service->currency_code
                    || ($policy['domain'] ?? null) !== $domain->domain || ($policy['user_id'] ?? null) !== (int) $domain->user_id
                    || ($policy['service_id'] ?? null) !== (int) $service->id || ($policy['server_id'] ?? null) !== (int) $domain->server_id
                    || isset($policy['account_fingerprint']) && (!is_string($uid) || !is_string($policy['account_fingerprint'])
                        || !hash_equals($policy['account_fingerprint'], RuntimeGuard::serverFingerprint($service->product?->server)))
                    || isset($policy['provider_account_identity']) && (!is_string($uid) || !is_string($policy['provider_account_identity'])
                        || !hash_equals($policy['provider_account_identity'], hash('sha256', strtolower(trim($uid)))))) {
                    return 'review';
                }
            }
            $this->operations->retireUnpaidRenewals($domain);
            return $drift ? 'review' : 'grace_review';
        });
    }

    /** An actionable explicit approval is required before refreshing its quote. */
    private function sponsorshipApproved(Domain $domain, Service $service, array $policy, ?User $actor = null): bool
    {
        $approval = $domain->sponsorship;
        $staff = is_array($approval) ? User::find($approval['approved_by'] ?? null) : null;
        return $policy['enabled'] && empty($policy['automatic_spend_paused']) && $this->minor($service->price) === 0 && is_array($approval)
            && ($approval['enabled'] ?? null) === true
            && ($actor ? $actor->hasPermission('admin.enom.fund') : ($approval['standing'] ?? null) === true)
            && (($approval['standing'] ?? null) === true || ($approval['base_expires_at'] ?? null) === $domain->provider_expires_at?->format('Y-m-d'))
            && $staff?->hasPermission('admin.enom.fund') && is_string($approval['reason'] ?? null)
            && trim($approval['reason']) !== '' && strlen($approval['reason']) <= 500
            && is_int($approval['limit_minor'] ?? null) && $approval['limit_minor'] > 0
            && ($approval['term'] ?? null) === $policy['term']
            && ($approval['currency'] ?? null) === $service->currency_code;
    }

    private function finishCancellation(int $domainId): string
    {
        return DB::transaction(function () use ($domainId) {
            $domain = Domain::lockForUpdate()->findOrFail($domainId);
            $service = Service::lockForUpdate()->findOrFail($domain->service_id);
            $domain->setRelation('service', $service);
            if (!$this->billingLifecycleEnabled($domain)) { return 'disabled'; }
            if (DomainOperations::writesPaused($domain)) { return 'staff_review'; }
            try { $this->assertBinding($domain, $service); }
            catch (RuntimeException) { return 'cancellation_review'; }
            if ($service->cancellation?->type !== 'end_of_period') { return 'cancellation_pending'; }
            $this->operations->retireUnpaidRenewals($domain);
            if (!$domain->provider_expires_at
                || $domain->provider_expires_at->format('Y-m-d') > now()->toDateString()) { return 'cancellation_pending'; }
            if (in_array($domain->observation['expiry_drift'] ?? null, ['earlier', 'later'], true)) { return 'cancellation_review'; }
            // A cancellation is an explicit customer preference, independent of
            // uncertain grace eligibility. Do not mutate registrar/history/money.
            if (Operation::where('domain_id', $domain->id)->whereIn('state', ['queued', 'running', 'pending', 'uncertain'])->exists()) {
                return 'cancellation_review';
            }
            if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) { return 'cancellation_review'; }
            $service->update(['status' => Service::STATUS_CANCELLED]);
            // Paid or part-paid obligations remain for separate financial
            // disposition. Only clean unpaid renewal invoices were retired.
            TerminateJob::dispatch($service)->afterCommit();
            return 'cancelled';
        });
    }

    private function minor(mixed $amount): int
    {
        if (is_float($amount) && is_finite($amount) && abs($amount - round($amount, 2)) < 0.0000001) {
            $amount = number_format($amount, 2, '.', '');
        }
        $decimal = RegistrarMoney::decimal(is_string($amount) ? $amount : (is_int($amount) ? (string) $amount : null));
        if ($decimal === null) { throw new RuntimeException('Domain renewal amount requires review.'); }
        [$whole, $fraction] = explode('.', $decimal);
        return ((int) $whole * 100) + (int) $fraction;
    }

    private function decimal(int $minor): string { return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT); }

    private function billingLifecycleEnabled(Domain $domain): bool
    {
        $server = $domain->service?->product?->server;
        return $server?->enabled && $server->extension === 'Enom'
            && filter_var($server->settings->firstWhere('key', 'billing_enabled')?->value ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function scheduledEnabled(Domain $domain): bool
    {
        return $this->registrarEnabled($domain)
            && filter_var($domain->service?->product?->server?->settings->firstWhere('key', 'billing_enabled')?->value ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function registrarEnabled(Domain $domain): bool
    {
        $server = $domain->service?->product?->server;
        return $server?->enabled && $server->extension === 'Enom'
            && filter_var($server->settings->firstWhere('key', 'operations_enabled')?->value ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
