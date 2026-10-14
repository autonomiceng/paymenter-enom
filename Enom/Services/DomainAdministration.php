<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Api\DomainName;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Throwable;

/** Explicit per-domain configuration. Observation and approval never purchase a renewal. */
class DomainAdministration
{
    private const BLOCKING_STATES = ['awaiting_payment', 'queued', 'running', 'pending', 'uncertain', 'held'];

    public function __construct(private DomainOperations $operations, private ?DomainBilling $billing = null,
        private ?DomainObservation $observer = null, private ?Closure $readGuard = null) {}

    private function authorize(User $user, string $permission = 'admin.enom.manage'): void
    {
        abort_unless($user->hasPermission('admin.enom.manage') && $user->hasPermission($permission), 403);
    }

    private function billing(): DomainBilling { return $this->billing ?? app(DomainBilling::class); }
    private function observer(): DomainObservation { return $this->observer ?? app(DomainObservation::class); }

    public function get(User $user, int $domainId): array
    {
        $this->authorize($user);
        $columns = ['id', 'domain', 'sld', 'tld', 'service_id', 'server_id', 'user_id', 'status', 'provider_expires_at',
            'provider_domain_id', 'billing_managed', 'renewal_policy', 'observation', 'authority_generation', 'confirmed_revision'];
        $canFund = $user->hasPermission('admin.enom.fund');
        $canCost = $user->hasPermission('admin.enom.costs');
        if ($canFund) { $columns[] = 'sponsorship'; }
        $domain = Domain::select($columns)->findOrFail($domainId);
        $customer = self::customer($domain);
        try { $policy = $this->billing()->policy($domain); }
        catch (Throwable) { $policy = ['enabled' => false, 'issue_days' => 21, 'due_days' => 7, 'credits_auto_use' => false]; }
        if (!$domain->billing_managed) { $policy['enabled'] = false; }
        $reason = $this->enrollmentProblem($domain);
        $data = ['id' => $domain->id, 'domain' => $domain->domain, 'service_id' => $domain->service_id, 'status' => $domain->status,
            'provider_expires_at' => $domain->provider_expires_at?->format('Y-m-d'), 'managed' => $domain->billing_managed,
            'policy' => array_intersect_key($policy, array_flip(['enabled', 'issue_days', 'due_days', 'credits_auto_use'])),
            'eligibility' => ['can_enable' => $reason === null, 'reason' => $reason],
            'permissions' => ['manage' => true, 'fund' => $canFund, 'costs' => $canCost]] + $customer;
        // Display status from the exact-bound request and its owner-verified invoice status. No cost or funding fields.
        $data['progress'] = self::progress([$domain])[(int) $domain->id] ?? null;
        if ($canFund) {
            $approval = $domain->sponsorship ?? [];
            $approvalEnabled = ($approval['enabled'] ?? false) === true;
            $approvalStanding = ($approval['standing'] ?? false) === true;
            $approvalBase = is_string($approval['base_expires_at'] ?? null) ? $approval['base_expires_at'] : null;
            $data['sponsorship'] = ['enabled' => $approvalEnabled, 'standing' => $approvalStanding,
                'limit_amount' => isset($approval['limit_minor']) && is_int($approval['limit_minor']) ? self::decimal($approval['limit_minor']) : null,
                'currency' => $approval['currency'] ?? null, 'term' => $approval['term'] ?? null, 'reason' => $approval['reason'] ?? '',
                // Display only. A single-cycle approval covers only the expiry it was saved for; billing re-checks everything.
                'base_expires_at' => $approvalBase,
                'current_cycle' => $approvalEnabled && ($approvalStanding || $approvalBase !== null
                    && $approvalBase === $domain->provider_expires_at?->format('Y-m-d'))];
        }
        // Staff-only booleans that name the server setting blocking automation. Never credentials or values.
        try {
            $service = $domain->service;
            $server = $service?->product?->server;
            $flag = fn (string $key) => filter_var($server?->settings->firstWhere('key', $key)?->value ?? false, FILTER_VALIDATE_BOOLEAN);
            $reads = $server?->settings->firstWhere('key', 'reads_enabled') ? $flag('reads_enabled') : $flag('operations_enabled');
            $data['server_flags'] = ['server_enabled' => (bool) ($server?->enabled), 'sandbox' => $flag('sandbox'), 'reads_enabled' => $reads,
                'operations_enabled' => $flag('operations_enabled'), 'billing_enabled' => $flag('billing_enabled'),
                'observations_enabled' => $flag('observations_enabled'), 'grace_renewals_enabled' => $flag('grace_renewals_enabled')];
            $data['zero_retail'] = $service !== null && RegistrarMoney::decimal((string) $service->price) === '0.00';
            $data['renewal_cancelled'] = $service !== null && (bool) $service->cancellation;
        } catch (Throwable) { $data['server_flags'] = null; }
        $billing = $domain->observation['billing'] ?? [];
        $data['billing_health'] = ['attempted_at' => self::timestamp($billing['attempted_at'] ?? null),
            'result' => self::safeCode($billing['result'] ?? null), 'deadline_missed' => is_bool($billing['deadline_missed'] ?? null) ? $billing['deadline_missed'] : null];
        if ($canCost) {
            $health = $domain->observation['cost_health'] ?? [];
            $data['automatic_spend_paused'] = ($domain->renewal_policy['automatic_spend_paused'] ?? false) === true;
            $data['cost_health'] = ['state' => self::safeCode($health['state'] ?? null),
                'operation_id' => is_int($health['operation_id'] ?? null) ? $health['operation_id'] : null,
                'observed_at' => self::timestamp($health['observed_at'] ?? null)];
            $quote = $domain->observation['renewal_quote'] ?? [];
            $data['renewal_quote'] = self::money($quote, false);
            // Private financial columns are queried only after the dedicated permission check.
            // Financial request kinds only. Management changes carry no registrar charge and are left out.
            $data['costs'] = Operation::select(['id', 'action', 'state', 'cost'])->where('domain_id', $domain->id)
                ->whereIn('action', DomainPresentation::FINANCIAL_ACTIONS)->latest('id')->limit(30)->get()
                ->map(function (Operation $operation) {
                    $cost = $operation->cost ?? [];
                    $quote = self::money($cost['quote'] ?? null, false);
                    $actual = self::money($cost['actual'] ?? null, true);
                    $attempts = $cost['actual_attempts'] ?? 0;
                    return ['operation_id' => $operation->id, 'action' => $operation->action, 'state' => $operation->state, 'quoted' => $quote,
                        'confirmed' => $actual, 'status' => $actual ? 'confirmed' : 'cost_unconfirmed',
                        'poll_attempts' => is_int($cost['actual_attempts'] ?? null) ? $cost['actual_attempts'] : null,
                        'poll_reason' => self::safeCode($cost['actual']['reason'] ?? null),
                        // Mirrors the existing reconcile cost-read preconditions so the page only offers a check that can run.
                        'checkable' => !$actual && $operation->state === 'succeeded' && in_array($operation->action, ['register', 'renew'], true)
                            && isset($cost['execution']) && is_int($attempts) && $attempts < DomainOperations::COST_POLL_LIMIT
                            && !in_array($cost['actual']['reason'] ?? null, ['renewal_item_contract_unaccepted', 'cost_poll_limit_reached'], true)];
                })->all();
        }
        return $data;
    }

    public function savePolicy(User $user, int $domainId, array $input): void
    {
        $this->authorize($user);
        $errors = [];
        $enabled = self::boolean($input['enabled'] ?? null, 'enabled', $errors);
        $credits = self::boolean($input['credits_auto_use'] ?? null, 'credits_auto_use', $errors);
        $managed = self::boolean($input['billing_managed'] ?? null, 'billing_managed', $errors);
        $issue = self::integer($input['issue_days'] ?? null, 'issue_days', $errors);
        $due = self::integer($input['due_days'] ?? null, 'due_days', $errors);
        if ($issue !== null && ($issue < 2 || $issue > 365 || $due !== null && $issue <= $due)) { $errors['issue_days'] = 'Invoice issue must be 2 to 365 days before expiry and precede payment due.'; }
        if ($due !== null && ($due < 1 || $due > 364)) { $errors['due_days'] = 'Payment due must be 1 to 364 days before expiry.'; }
        if ($errors) { throw new InputValidationException($errors); }
        DB::transaction(function () use ($domainId, $enabled, $credits, $managed, $issue, $due) {
            $domain = Domain::lockForUpdate()->findOrFail($domainId);
            if ($enabled && DomainOperations::writesPaused($domain)) { throw new InputValidationException(['enabled' => 'A staff-disposed registrar outcome requires a separate binding and financial review before automation can resume.']); }
            $service = Service::lockForUpdate()->findOrFail($domain->service_id);
            $domain->setRelation('service', $service);
            $candidate = clone $domain;
            $candidate->billing_managed = true;
            if (!$this->operations->validService($service, $candidate)) {
                throw new InputValidationException(['billing_managed' => 'The native owner, server and yearly service binding require review.']);
            }
            if (!$domain->billing_managed) {
                if (!$managed || !$enabled) { throw new InputValidationException(['billing_managed' => 'Explicitly enroll and enable this verified mapping together.']); }
                if ($reason = $this->enrollmentProblem($domain)) { throw new InputValidationException(['billing_managed' => $reason]); }
                $domain->provider_domain_id = $domain->observation['sources']['info']['value']['domain_name_id'];
                $domain->renewal_policy = array_replace($domain->renewal_policy ?? [], ['account_fingerprint' => $domain->observation['account_fingerprint']]);
                $domain->billing_managed = true;
            } elseif (!$managed) {
                throw new InputValidationException(['billing_managed' => 'Managed registrations retain their billing mode. Disable future invoices to stop this policy.']);
            } elseif ($enabled && ($domain->renewal_policy['enabled'] ?? false) !== true && ($reason = $this->enrollmentProblem($domain))) {
                throw new InputValidationException(['enabled' => $reason]);
            }
            $domain->renewal_policy = array_replace($domain->renewal_policy ?? [], ['version' => 1, 'enabled' => $enabled,
                'issue_days' => $issue, 'due_days' => $due, 'term' => 1, 'credits_auto_use' => $credits]);
            // Save the binding before the native date, within the same locked transaction.
            $domain->save();
            if ($service->expires_at !== null) { $service->expires_at = null; $service->save(); }
        });
    }

    public function saveSponsorship(User $user, int $domainId, array $input): void
    {
        $this->authorize($user, 'admin.enom.fund');
        $errors = [];
        $enabled = self::boolean($input['enabled'] ?? null, 'enabled', $errors);
        if ($enabled === false) {
            DB::transaction(function () use ($user, $domainId) {
                $domain = Domain::lockForUpdate()->findOrFail($domainId);
                // Withdrawal never approves new spending or rewrites an issued obligation.
                // Permit it even while a registrar outcome or native mapping needs review.
                $approval = $domain->sponsorship ?? [];
                $domain->sponsorship = array_replace($approval, ['enabled' => false,
                    'revoked_by' => (int) $user->id, 'revoked_at' => now()->toIso8601String()]);
                $domain->save();
            });
            return;
        }
        $standing = self::boolean($input['standing'] ?? null, 'standing', $errors);
        $term = self::integer($input['term'] ?? null, 'term', $errors);
        if ($term !== 1) { $errors['term'] = 'Only an explicitly approved one-year term is supported.'; }
        $amount = RegistrarMoney::decimal($input['limit_amount'] ?? null);
        if (!$amount || self::minor($amount) <= 0) { $errors['limit_amount'] = 'Enter a positive exact decimal limit with at most two decimal places.'; }
        $currency = $input['currency'] ?? null;
        if ($currency !== 'USD') { $errors['currency'] = 'The supported sponsorship currency is USD.'; }
        $reason = $input['reason'] ?? null;
        $normalized = is_string($reason) ? preg_replace('/\r\n|\r|\n/', ' ', $reason) : '';
        if (!is_string($reason) || strlen(trim($normalized)) < 10 || strlen($reason) > 500 || preg_match('/[\x00-\x1f\x7f]/', $normalized)) {
            $errors['reason'] = 'Enter an individual reason of 10 to 500 characters without control characters.';
        }
        if ($errors) { throw new InputValidationException($errors); }
        DB::transaction(function () use ($user, $domainId, $enabled, $standing, $term, $amount, $currency, $normalized) {
            $domain = Domain::lockForUpdate()->findOrFail($domainId);
            $service = Service::lockForUpdate()->findOrFail($domain->service_id);
            $domain->setRelation('service', $service);
            if (!$domain->billing_managed || !$this->operations->validService($service, $domain)
                || $service->status !== Service::STATUS_ACTIVE || self::minor((string) $service->price) !== 0
                || $service->currency_code !== $currency || $service->expires_at !== null || $service->cancellation()->exists()
                || $domain->operations()->whereIn('state', self::BLOCKING_STATES)->exists()) {
                throw new InputValidationException(['enabled' => 'Sponsorship requires an active managed zero-price domain, matching currency and no outstanding request.']);
            }
            $domain->sponsorship = ['version' => 1, 'enabled' => $enabled, 'standing' => $standing, 'limit_minor' => self::minor($amount),
                'currency' => $currency, 'term' => $term, 'reason' => trim($normalized), 'approved_by' => (int) $user->id,
                'approved_at' => now()->toIso8601String(), 'base_expires_at' => $domain->provider_expires_at?->format('Y-m-d')];
            $domain->save();
        });
    }

    public function refreshObservation(User $user, int $domainId): string
    {
        $this->authorize($user);
        Domain::select('id')->findOrFail($domainId);
        $result = $this->observer()->observe($domainId);
        // A full quote is another read-only path. It carries no spending authority.
        if ($user->hasPermission('admin.enom.costs')) { $this->observer()->observeRenewalQuote($domainId); }
        return $result;
    }

    public function requestSponsoredRenewal(User $user, int $domainId): ?Operation
    {
        $this->authorize($user, 'admin.enom.fund');
        Domain::select('id')->findOrFail($domainId);
        if ($this->observer()->observeRenewalQuote($domainId) !== 'quoted') { return null; }
        return $this->billing()->sponsor($domainId, $user);
    }

    private function enrollmentProblem(Domain $domain): ?string
    {
        $service = $domain->service;
        // The zero-price service becomes valid only with the explicit managed flag.
        $candidate = clone $domain;
        $candidate->billing_managed = true;
        if (!$service || !$this->operations->validService($service, $candidate) || $service->status !== Service::STATUS_ACTIVE
            || $service->cancellation()->exists() || $domain->status !== 'active' || !$domain->provider_expires_at
            || !$domain->provider_expires_at->isFuture()) { return 'A confirmed active one-year service and future registrar expiry are required.'; }
        try { $parts = DomainName::parse($domain->domain, [$domain->tld]); }
        catch (Throwable) { return 'This TLD has no accepted renewal execution contract. Keep its billing under review.'; }
        if ($parts['domain'] !== $domain->domain || $parts['sld'] !== $domain->sld || $parts['tld'] !== $domain->tld) { return 'The canonical domain mapping requires review.'; }
        if ($domain->operations()->whereIn('state', self::BLOCKING_STATES)->exists()) { return 'Resolve outstanding registrar or payment requests before enabling this policy.'; }
        $observation = $domain->observation ?? [];
        if (($observation['status'] ?? null) !== 'fresh') { return 'The latest authenticated registrar observation requires refresh or review.'; }
        $info = $observation['sources']['info'] ?? [];
        $renewal = $observation['sources']['renewal'] ?? [];
        $expiry = $observation['sources']['expiry'] ?? [];
        foreach ([$info, $renewal, $expiry] as $source) {
            $at = self::clock($source['succeeded_at'] ?? null);
            if (($source['status'] ?? null) !== 'fresh' || !$at || $at->isFuture() || $at->lt(now()->subDay())) { return 'Refresh authenticated identity, expiry and registrar auto-renew observations before enrollment.'; }
        }
        $id = $info['value']['domain_name_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)
            || $domain->provider_domain_id !== null && $domain->provider_domain_id !== $id
            || ($info['value']['registration_status'] ?? null) !== 'registered'
            || ($info['value']['purchase_status'] ?? null) !== 'paid'
            || ($renewal['value']['auto_renew'] ?? null) !== false
            || self::day($info['value']['expires_at'] ?? null) !== $domain->provider_expires_at->format('Y-m-d')
            || self::day($expiry['value']['expires_at'] ?? null) !== $domain->provider_expires_at->format('Y-m-d')
            || ($observation['expiry_drift'] ?? null) !== 'none') { return 'Provider identity, confirmed expiry and disabled registrar auto-renew must match.'; }
        try {
            $configuration = $this->readGuard ? ($this->readGuard)((int) $domain->server_id, true) : RuntimeGuard::assertProvider((int) $domain->server_id, true);
            $account = RuntimeGuard::accountFingerprint($configuration);
            $baseline = $domain->renewal_policy['account_fingerprint'] ?? null;
            if (!is_string($configuration['uid'] ?? null) || $configuration['uid'] === ''
                || !is_string($observation['account_fingerprint'] ?? null) || !hash_equals($account, $observation['account_fingerprint'])
                || $baseline !== null && (!is_string($baseline) || !hash_equals($account, $baseline))) { return 'Current authenticated registrar account binding requires review.'; }
        } catch (Throwable) { return 'Current registrar read configuration requires review.'; }
        return null;
    }

    /** An explicit allowlist prevents private observation, sponsorship and cost serialization. */
    public static function customer(Domain $domain): array
    {
        $observation = $domain->observation ?? [];
        $expiry = $observation['sources']['expiry'] ?? [];
        $at = self::clock($expiry['succeeded_at'] ?? null);
        $status = ($observation['status'] ?? null) === 'fresh' && ($expiry['status'] ?? null) === 'fresh' && $at && !$at->isFuture() && $at->gte(now()->subDay()) ? 'fresh' : 'stale';
        if (!$at) { $status = 'unknown'; }
        $problem = $status !== 'fresh' ? 'Registrar observation requires review.' : null;
        if (in_array($observation['expiry_drift'] ?? null, ['earlier', 'later'], true)) { $problem = 'Observed expiry differs from confirmed registrar expiry.'; }
        try { $policy = app(DomainBilling::class)->policy($domain); }
        catch (Throwable) { $policy = ['enabled' => false]; }
        $issue = $policy['issue_days'] ?? null; $due = $policy['due_days'] ?? null;
        $calendar = ['issue_at' => null, 'due_at' => null];
        if ($domain->billing_managed && $domain->provider_expires_at && is_int($issue) && is_int($due) && $issue > $due && $due >= 1 && $issue <= 365) {
            $calendar = ['issue_at' => $domain->provider_expires_at->subDays($issue)->format('Y-m-d'), 'due_at' => $domain->provider_expires_at->subDays($due)->format('Y-m-d')];
        }
        $requests = $domain->operations()->select(['id', 'domain_id', 'service_id', 'invoice_id', 'invoice_item_id', 'state', 'safe_code'])
            ->where('action', 'renew')->where('service_id', $domain->service_id)->whereNotNull('invoice_id')->orderByDesc('id')->get();
        $links = self::linkedInvoices($requests, [(int) $domain->id => ['service_id' => (int) $domain->service_id, 'user_id' => (int) $domain->user_id]]);
        // Each invoice row shows its latest registrar request state, so a paid invoice is never read as a renewed domain.
        $invoices = [];
        foreach ($requests as $request) {
            $link = $links[(int) $request->id] ?? null;
            if (!$link || isset($invoices[$link['id']])) { continue; }
            $invoices[$link['id']] = $link + ['request_state' => $request->state, 'request_outcome' => DomainPresentation::outcome($request->safe_code)];
        }
        krsort($invoices);
        $invoices = array_values(array_slice($invoices, 0, 30, true));
        $service = $domain->service;
        $server = $service?->product?->server;
        $scheduled = $domain->billing_managed && ($policy['enabled'] ?? false) === true
            && !DomainOperations::writesPaused($domain)
            && $service?->status === Service::STATUS_ACTIVE && !$service->cancellation
            && $server?->enabled && $server->extension === 'Enom'
            && filter_var($server->settings->firstWhere('key', 'billing_enabled')?->value ?? false, FILTER_VALIDATE_BOOLEAN)
            && filter_var($server->settings->firstWhere('key', 'operations_enabled')?->value ?? false, FILTER_VALIDATE_BOOLEAN);
        return ['observed_expires_at' => self::day($expiry['value']['expires_at'] ?? null), 'observed_at' => $at?->toIso8601String(),
            'observation_status' => $status, 'observation_problem' => $problem, 'calendar' => $calendar, 'invoices' => $invoices,
            'managed' => (bool) $domain->billing_managed, 'enabled' => (bool) $scheduled];
    }

    /**
     * Invoice display data for requests, keyed by request id. Read only.
     * A request shows its invoice only when the request has the exact domain and service, its invoice item
     * belongs to that invoice and references that service or nothing, and the invoice owner is the domain owner.
     * Viewer permissions never widen this. Only invoice id, status and due date are read, never amounts.
     *
     * @param  iterable  $requests  Rows with id, domain_id, service_id, invoice_id and invoice_item_id.
     * @param  array<int, array{service_id: int, user_id: int}>  $owners  Expected binding, keyed by domain id.
     */
    public static function linkedInvoices(iterable $requests, array $owners): array
    {
        $candidates = [];
        foreach ($requests as $request) {
            $requestId = self::key(data_get($request, 'id'));
            $domainId = self::key(data_get($request, 'domain_id'));
            $owner = $domainId ? ($owners[$domainId] ?? null) : null;
            $invoiceId = self::key(data_get($request, 'invoice_id'));
            $itemId = self::key(data_get($request, 'invoice_item_id'));
            if (!$requestId || !$owner || !$invoiceId || !$itemId || !self::key($owner['service_id'] ?? null) || !self::key($owner['user_id'] ?? null)
                || self::key(data_get($request, 'service_id')) !== (int) $owner['service_id']) { continue; }
            $candidates[$requestId] = ['owner' => $owner, 'invoice_id' => $invoiceId, 'item_id' => $itemId];
        }
        if (!$candidates) { return []; }
        // Base queries return plain rows: no model accessors, computed totals or eager-loaded relations.
        $items = [];
        foreach (InvoiceItem::query()->select(['id', 'invoice_id', 'reference_type', 'reference_id'])
            ->whereIn('id', array_values(array_unique(array_column($candidates, 'item_id'))))->toBase()->get() as $item) {
            $items[(int) $item->id] = $item;
        }
        $invoices = [];
        foreach (Invoice::query()->select(['id', 'user_id', 'status', 'due_at'])
            ->whereIn('id', array_values(array_unique(array_column($candidates, 'invoice_id'))))->toBase()->get() as $invoice) {
            $invoices[(int) $invoice->id] = $invoice;
        }
        $verified = [];
        foreach ($candidates as $requestId => $candidate) {
            $item = $items[$candidate['item_id']] ?? null;
            $invoice = $invoices[$candidate['invoice_id']] ?? null;
            $referenced = $item && $item->reference_type === Service::class && self::key($item->reference_id) === (int) $candidate['owner']['service_id'];
            $unreferenced = $item && $item->reference_type === null && $item->reference_id === null;
            if (!$item || !$invoice || self::key($item->invoice_id) !== $candidate['invoice_id'] || !($referenced || $unreferenced)
                || self::key($invoice->user_id) !== (int) $candidate['owner']['user_id']) { continue; }
            $verified[$requestId] = ['id' => $candidate['invoice_id'],
                'status' => in_array($invoice->status, ['pending', 'paid', 'cancelled'], true) ? $invoice->status : null,
                'due_at' => self::day($invoice->due_at)];
        }
        return $verified;
    }

    /**
     * Customer-safe status for each domain, keyed by domain id. Read only, no provider calls.
     * A pending registration or transfer uses its latest exact-bound request and verified invoice status.
     *
     * @param  iterable  $domains  Rows with id, status, service_id and user_id.
     */
    public static function progress(iterable $domains): array
    {
        $actions = ['registration_pending' => 'register', 'transfer_pending' => 'transfer'];
        $entry = function (?string $status, ?array $request, ?int $invoiceId): array {
            $label = DomainPresentation::progress($status, $request);
            $awaiting = $label === DomainPresentation::AWAITING_PAYMENT;
            return ['label' => $label, 'kind' => match ($status) { 'registration_pending' => 'registration', 'transfer_pending' => 'transfer', default => null },
                'awaiting_payment' => $awaiting, 'invoice_id' => $awaiting ? $invoiceId : null];
        };
        $result = []; $pending = []; $owners = [];
        foreach ($domains as $domain) {
            if (!($domainId = self::key(data_get($domain, 'id')))) { continue; }
            $status = is_string(data_get($domain, 'status')) ? data_get($domain, 'status') : null;
            $result[$domainId] = $entry($status, null, null);
            if ($status !== null && isset($actions[$status])) {
                $pending[$domainId] = $status;
                $owners[$domainId] = ['service_id' => (int) data_get($domain, 'service_id'), 'user_id' => (int) data_get($domain, 'user_id')];
            }
        }
        if (!$pending) { return $result; }
        $latest = [];
        foreach (Operation::query()->select(['id', 'domain_id', 'service_id', 'invoice_id', 'invoice_item_id', 'action', 'state'])
            ->whereIn('domain_id', array_keys($pending))->whereIn('action', array_values($actions))->orderByDesc('id')->toBase()->get() as $request) {
            $domainId = (int) $request->domain_id;
            if (isset($latest[$domainId]) || $request->action !== $actions[$pending[$domainId]]
                || self::key($request->service_id) !== $owners[$domainId]['service_id']) { continue; }
            $latest[$domainId] = $request;
        }
        $links = self::linkedInvoices($latest, $owners);
        foreach ($latest as $domainId => $request) {
            $link = $links[(int) $request->id] ?? null;
            $result[$domainId] = $entry($pending[$domainId], ['state' => is_string($request->state) ? $request->state : null,
                'invoiced' => $request->invoice_id !== null, 'invoice_status' => $link['status'] ?? null], $link['id'] ?? null);
        }
        return $result;
    }

    private static function key(mixed $value): ?int
    {
        if (is_int($value)) { return $value > 0 ? $value : null; }
        return is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) ? (int) $value : null;
    }

    private static function money(mixed $value, bool $actual): ?array
    {
        if (!is_array($value) || $actual && ($value['state'] ?? null) !== 'confirmed') { return null; }
        $amount = RegistrarMoney::decimal($value['amount'] ?? null);
        $currency = $value['currency'] ?? null;
        if (!$amount || !is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) { return null; }
        return ['amount' => $amount, 'currency' => $currency, 'state' => self::safeCode($value['state'] ?? null) ?? 'unconfirmed'];
    }
    private static function boolean(mixed $value, string $field, array &$errors): ?bool
    {
        if (in_array($value, ['1', 1, true], true)) { return true; }
        if (in_array($value, ['0', 0, false], true)) { return false; }
        $errors[$field] = 'Choose an explicit enabled or disabled value.'; return null;
    }
    private static function integer(mixed $value, string $field, array &$errors): ?int
    {
        if (is_int($value) || is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $value)) { return (int) $value; }
        $errors[$field] = 'Enter a whole number of days or years.'; return null;
    }
    private static function minor(string $amount): int { return (int) str_replace('.', '', $amount); }
    private static function decimal(int $minor): string { return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT); }
    private static function safeCode(mixed $value): ?string { return is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) ? $value : null; }
    private static function clock(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) { return null; }
        try { return CarbonImmutable::parse($value); } catch (Throwable) { return null; }
    }
    private static function timestamp(mixed $value): ?string { return self::clock($value)?->toIso8601String(); }
    private static function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) { return $value->format('Y-m-d'); }
        if (!is_string($value)) { return null; }
        $date = substr($value, 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) { return null; }
        return $date;
    }
}
