<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use Closure;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;
use Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Enom\Jobs\ReconcileOperation;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\OrderIntent;
use RuntimeException;
use Throwable;

class DomainOperations
{
    public const UNRESOLVED_STATES = ['running', 'pending', 'uncertain'];
    public const COST_POLL_LIMIT = 5;

    public static function writesPaused(Domain $domain): bool
    {
        return $domain->billing_managed && ($domain->renewal_policy['staff_disposition_hold'] ?? false) === true;
    }

    public function __construct(private ?EnomApi $api = null, private ?Closure $guard = null) {}

    public function apiForServer(int $serverId, bool $readOnly = false): EnomApi
    {
        $config = $this->providerConfiguration($serverId, $readOnly);
        return $this->api ?? new EnomApi(new RegistrarClient($config['uid'], $config['token'], null,
            function () use ($serverId, $readOnly, $config) {
                $current = $this->providerConfiguration($serverId, $readOnly);
                if (!hash_equals((string) $config['uid'], (string) $current['uid'])
                    || !hash_equals((string) $config['token'], (string) $current['token'])) {
                    throw new RuntimeException('Registrar account configuration changed.');
                }
                return $current;
            }, $readOnly));
    }

    public function providerConfiguration(int $serverId, bool $readOnly = false): array
    {
        return $this->guard ? ($this->guard)($serverId, $readOnly) : RuntimeGuard::assertProvider($serverId, $readOnly);
    }

    public function bindInvoiceItem(InvoiceItem $item): ?Operation
    {
        // This listener runs inside native cron. Never throw and never lock a domain row.
        try {
            if ($item->reference_type !== Service::class) { return null; }
            $service = $item->reference;
            if (!$service || $service->product?->server?->extension !== 'Enom') { return null; }
            $domain = Domain::where('service_id', $service->id)->first();
            if (!$domain || (int) $domain->user_id !== (int) $service->user_id) { return null; }
            $key = 'invoice-item:'.$item->id;
            if ($existing = Operation::where('operation_key', $key)->first()) { return $existing; }
            if ($item->invoice->status !== Invoice::STATUS_PENDING) { return null; }
            $intent = OrderIntent::where('service_id', $service->id)->first();
            $initial = $service->status === Service::STATUS_PENDING && $intent && !$intent->invoice_item_id;
            $renewal = $service->status === Service::STATUS_ACTIVE && $domain->status === 'active' && $domain->provider_expires_at;
            $action = $initial ? $intent->action : 'renew';
            $valid = ($initial || $renewal) && $this->validService($service, $domain)
                && (int) $item->quantity === 1 && $item->invoice->user_id == $domain->user_id
                && $item->invoice->currency_code === $service->currency_code
                && $this->cents($item->price) === $this->cents($initial ? $intent->amount : $service->price);
            // Managed renewals have their own unreferenced native obligations.
            // A stray stock-linked renewal item cannot authorize registrar work.
            if ($domain->billing_managed && !$initial) { $valid = false; }
            $policy = $domain->billing_managed ? array_replace($domain->renewal_policy ?? [], [
                'billing_managed' => true, 'dispatch_marker_version' => 1, 'domain' => $domain->domain, 'user_id' => (int) $domain->user_id,
                'service_id' => (int) $service->id, 'server_id' => (int) $domain->server_id,
                'authority_generation' => $domain->authority_generation, 'confirmed_revision' => $domain->confirmed_revision,
                'currency' => $service->currency_code, 'term' => 1, 'retail_minor' => $this->cents($item->price),
            ]) : null;
            $operation = Operation::firstOrCreate(['operation_key' => $key], [
                'domain_id' => $domain->id, 'service_id' => $service->id,
                'invoice_id' => $item->invoice_id, 'invoice_item_id' => $item->id,
                'action' => $action, 'term' => 1, 'funding_basis' => 'paid_invoice',
                'state' => $valid ? 'awaiting_payment' : 'held', 'safe_code' => $valid ? null : 'binding_invalid',
                'amount' => $item->price, 'currency' => $item->invoice->currency_code,
                'base_expires_at' => $domain->provider_expires_at,
                'expected_expires_at' => $domain->provider_expires_at?->addYearNoOverflow(),
                'payload' => $initial ? ($intent->payload ?? []) : [],
                'policy' => $policy,
            ]);
            if ($initial) {
                $intent->update(['invoice_id' => $item->invoice_id, 'invoice_item_id' => $item->id]);
            }
            return $operation;
        } catch (Throwable) {
            // Intentionally no exception report: it may contain private request/model data.
            return null;
        }
    }

    public function acceptPaidInvoice(Invoice $invoice): array
    {
        try {
            $ids = [];
            foreach (Operation::where('invoice_id', $invoice->id)->where('state', 'succeeded')->get() as $done) {
                if (in_array($done->action, ['register', 'transfer', 'renew'], true) && $invoice->fresh()->status === Invoice::STATUS_PAID
                    && $this->cents($invoice->fresh()->remaining) === 0 && $this->funded($done)) {
                    $domain = $done->domain;
                    if ($domain->provider_expires_at && !$domain->billing_managed && $domain->service?->status === Service::STATUS_ACTIVE) {
                        $service = $domain->service;
                        $service->expires_at = $domain->provider_expires_at->subDays(RuntimeGuard::leadDays((int) $domain->server_id))->format('Y-m-d');
                        $service->save();
                    }
                }
            }
            foreach (Operation::where('invoice_id', $invoice->id)->where('state', 'awaiting_payment')->get() as $operation) {
                if (!$this->funded($operation)) { continue; }
                if (Operation::whereKey($operation->id)->where('state', 'awaiting_payment')->update(['state' => 'queued']) === 1) {
                    ExecuteOperation::dispatch($operation->id)->afterCommit();
                    $ids[] = $operation->id;
                }
            }
            return $ids;
        } catch (Throwable) { return []; }
    }

    public function enqueueService(Service $service): array
    {
        $ids = [];
        try {
            foreach (Operation::where('service_id', $service->id)->whereIn('state', ['awaiting_payment', 'queued'])->get() as $operation) {
                if (!$this->funded($operation)) { continue; }
                Operation::whereKey($operation->id)->where('state', 'awaiting_payment')->update(['state' => 'queued']);
                ExecuteOperation::dispatch($operation->id)->afterCommit();
                $ids[] = $operation->id;
            }
        } catch (Throwable) {}
        return $ids;
    }

    public function validService(Service $service, Domain $domain): bool
    {
        return (int) $service->user_id === (int) $domain->user_id && $service->product?->server?->extension === 'Enom'
            && (int) $service->product->server_id === (int) $domain->server_id
            && ($this->cents($service->price) > 0 || $domain->billing_managed && $this->cents($service->price) === 0)
            && (int) $service->quantity === 1 && $service->coupon_id === null
            && $service->plan?->type === 'recurring' && $service->plan->billing_unit === 'year' && (int) $service->plan->billing_period === 1;
    }

    public function funded(Operation $operation): bool
    {
        $domain = $operation->domain;
        $service = $domain?->service;
        if ($domain && self::writesPaused($domain)) { return false; }
        if (!$domain || !$service || !$this->validService($service, $domain) || (int) $operation->service_id !== (int) $service->id
            || $service->currency_code !== $operation->currency || (int) $operation->term !== 1) { return false; }
        $policy = $operation->policy ?? [];
        if (!empty($policy['billing_managed']) && (!$domain->billing_managed
            || (int) ($policy['authority_generation'] ?? -1) !== $domain->authority_generation
            || (int) ($policy['confirmed_revision'] ?? -1) !== $domain->confirmed_revision
            || ($policy['currency'] ?? null) !== $operation->currency
            || (int) ($policy['term'] ?? 0) !== (int) $operation->term)) { return false; }
        if (!empty($policy['billing_managed'])) {
            if (($policy['domain'] ?? null) !== $domain->domain || ($policy['user_id'] ?? (int) $domain->user_id) !== (int) $domain->user_id
                || ($policy['service_id'] ?? null) !== (int) $service->id || ($policy['server_id'] ?? null) !== (int) $domain->server_id
                || isset($policy['provider_domain_id']) && $policy['provider_domain_id'] !== $domain->provider_domain_id) { return false; }
            if (isset($policy['account_fingerprint']) || isset($policy['provider_account_identity'])) {
                try {
                    $configuration = $this->guard ? ($this->guard)((int) $domain->server_id, true) : RuntimeGuard::configuration((int) $domain->server_id);
                    $uid = $configuration['uid'] ?? null;
                    if (!is_string($uid) || $uid === ''
                        || isset($policy['account_fingerprint']) && $policy['account_fingerprint'] !== hash('sha256', 'sandbox:'.strtolower($uid))
                        || isset($policy['provider_account_identity']) && $policy['provider_account_identity'] !== hash('sha256', strtolower(trim($uid)))) { return false; }
                } catch (Throwable) { return false; }
            }
        }
        if ($operation->funding_basis === 'non_billable_management') {
            return $operation->authorized_by == $domain->user_id && $domain->status === 'active';
        }
        if ($operation->funding_basis === 'operator_funded') {
            $staff = User::find($operation->authorized_by);
            return $staff && $staff->hasPermission('admin.enom.manage') && filled($operation->funding_reason) && $this->cents($operation->amount) > 0;
        }
        if ($operation->funding_basis === 'sponsored') {
            $sponsorship = $domain->sponsorship ?? [];
            $approval = $policy['sponsorship'] ?? [];
            $staff = User::find($approval['approved_by'] ?? 0);
            $executor = User::find($operation->authorized_by ?? 0);
            return $domain->billing_managed && $service->status === Service::STATUS_ACTIVE
                && $this->cents($service->price) === 0 && $this->cents($operation->amount) === 0
                && $operation->invoice_id === null && $operation->invoice_item_id === null
                && !empty($sponsorship['enabled']) && $approval === $sponsorship
                && $staff && $staff->hasPermission('admin.enom.fund')
                && $executor && $executor->hasPermission('admin.enom.fund')
                && (!empty($approval['standing']) || !empty($policy['single_cycle_authorized'])
                    && ($policy['sponsorship_cycle'] ?? null) === $operation->base_expires_at?->format('Y-m-d')
                    && ($approval['base_expires_at'] ?? null) === $operation->base_expires_at?->format('Y-m-d'))
                && filled($approval['reason'] ?? null) && ($approval['currency'] ?? null) === $operation->currency
                && (int) ($approval['term'] ?? 0) === (int) $operation->term
                && is_int($approval['limit_minor'] ?? null) && $approval['limit_minor'] > 0
                && !$service->cancellation()->exists();
        }
        $invoice = Invoice::with('items', 'transactions')->find($operation->invoice_id);
        $item = $invoice?->items->firstWhere('id', $operation->invoice_item_id);
        if (!$invoice || !$item || $invoice->status !== Invoice::STATUS_PAID || $invoice->user_id != $domain->user_id
            || $invoice->currency_code !== $operation->currency || $service->currency_code !== $operation->currency
            || $this->cents($invoice->remaining) !== 0 || (int) $item->quantity !== 1
            || $this->cents($item->price) !== $this->cents($operation->amount) || $this->cents($operation->amount) <= 0) { return false; }
        if (str_starts_with($operation->action, 'privacy_')) {
            if ($item->reference_id !== null || $item->reference_type !== null) { return false; }
        } elseif (!empty($policy['billing_managed']) && $operation->action === 'renew') {
            if ($item->reference_type !== null || $item->reference_id !== null
                || $service->status !== Service::STATUS_ACTIVE
                || (int) ($policy['retail_minor'] ?? -1) !== $this->cents($operation->amount)) { return false; }
        } elseif ($item->reference_type !== Service::class || $item->reference_id != $service->id) { return false; }
        if ($operation->action === 'renew' && empty($policy['billing_managed'])
            && $this->cents($service->price) !== $this->cents($operation->amount)) { return false; }
        $total = 0;
        foreach ($invoice->transactions as $transaction) {
            if ($transaction->status !== InvoiceTransactionStatus::Succeeded || (!$transaction->gateway_id && !$transaction->is_credit_transaction)
                || $this->cents($transaction->amount) <= 0) { continue; }
            $total += $this->cents($transaction->amount);
        }
        return $total === $this->cents($invoice->total);
    }

    public function execute(int $operationId): Operation
    {
        $operation = Operation::findOrFail($operationId);
        if (!in_array($operation->state, ['queued', 'running'], true)) { return $operation; }
        // Configuration failure is pre-send: no claim and no provider call.
        $api = $this->apiForServer((int) $operation->domain->server_id);
        $claimed = DB::transaction(function () use ($operationId) {
            $operation = Operation::findOrFail($operationId);
            $domain = Domain::whereKey($operation->domain_id)->lockForUpdate()->firstOrFail();
            $operation = Operation::whereKey($operationId)->lockForUpdate()->firstOrFail();
            if ($operation->state === 'running') {
                if (!$operation->lease_expires_at || $operation->lease_expires_at->isPast()) {
                    $this->classifyInterruptedLocked($operation, $domain, 'stale_claim');
                }
                return null;
            }
            if ($operation->state !== 'queued') { return null; }
            if (self::writesPaused($domain)) {
                $operation->update(['state' => 'held', 'safe_code' => 'staff_disposition_review_required']);
                return null;
            }
            if (Operation::where('domain_id', $domain->id)->where('id', '!=', $operationId)->whereIn('state', ['running', 'pending', 'uncertain'])->exists()) { return null; }
            if (!$this->funded($operation) || !$this->executionLifecycleValid($operation)) {
                $operation->update(['state' => 'held', 'safe_code' => 'funding_or_preference_invalid']);
                return null;
            }
            if (in_array($operation->action, ['renew', 'transfer'], true) && $operation->action === 'renew'
                && (!$domain->provider_expires_at || !$operation->base_expires_at || !$domain->provider_expires_at->equalTo($operation->base_expires_at))) {
                $operation->update(['state' => 'held', 'safe_code' => 'expiry_changed']);
                return null;
            }
            $policy = $operation->policy ?? [];
            if (($policy['dispatch_marker_version'] ?? null) === 1) { $policy['dispatch_claim_token'] = bin2hex(random_bytes(16)); }
            $operation->update(['policy' => $policy, 'state' => 'running', 'started_at' => now(), 'lease_expires_at' => now()->addSeconds(ExecuteOperation::CLAIM_LEASE_SECONDS),
                'expected_expires_at' => $operation->action === 'privacy_purchase' ? now()->addYearNoOverflow()->format('Y-m-d') : $operation->expected_expires_at,
                'request_fingerprint' => hash('sha256', json_encode([$domain->domain, $operation->action, $operation->term, $operation->operation_key], JSON_THROW_ON_ERROR))]);
            return $operation;
        });
        if (!$claimed) { return $operation->fresh(); }
        // No database transaction or domain row lock spans a provider request.
        try {
            $domain = $claimed->domain;
            if (in_array($claimed->action, ['register', 'transfer'], true)) {
                $result = $api->search($domain->domain);
                $check = EnomApi::normalize('search', $result);
                if ($result->status !== 'success' || ($check['is_premium'] ?? null) !== false || ($check['is_platinum'] ?? null) !== false || ($check['is_eap'] ?? null) !== false
                    || ($claimed->action === 'register' && empty($check['available']))
                    || ($claimed->action === 'transfer' && (!empty($check['available']) || empty($check['transferable'])))) {
                    return $this->finish($claimed, 'rejected', 'domain_unavailable_or_premium');
                }
                $node = $result->value('domains.domain');
                if (is_array($node) && array_is_list($node)) { $node = count($node) === 1 ? $node[0] : null; }
                $amount = is_array($node) && ($node['name'] ?? null) === $domain->domain
                    ? RegistrarMoney::decimal($check['prices'][$claimed->action === 'register' ? 'registration' : 'transfer'] ?? null) : null;
                $currency = is_array($node) ? RegistrarMoney::checkPriceCurrency($node['prices'] ?? null) : null;
                $updated = $this->updateClaimEvidence($claimed, ['quote' => [
                    'state' => $amount !== null && $currency !== null ? 'advisory' : 'unconfirmed', 'source' => 'Check',
                    'domain' => $domain->domain, 'action' => $claimed->action, 'term' => (int) $claimed->term,
                    'amount' => $amount, 'currency' => $currency, 'complete' => false,
                    'observed_at' => now()->toIso8601String(), 'reason' => 'preflight_price_not_final_charge',
                ]]);
                if (!$updated) { return $claimed->fresh(); }
                $claimed = $updated;
            }
            if ($claimed->action === 'renew' && $domain->billing_managed) {
                $admission = (new RenewalAdmission($this))->check($claimed, $api);
                $updated = $this->updateClaimEvidence($claimed, ['quote' => $admission['quote']],
                    $admission['allowed'] ? ['provider_command' => $admission['command']] : []);
                if (!$updated) { return $claimed->fresh(); }
                $claimed = $updated;
                if (!$admission['allowed']) { return $this->finish($claimed, 'held', $admission['code']); }
            } elseif ($claimed->action === 'renew') {
                $pre = $api->expiry($domain->domain);
                $expiry = $pre->confirmedExpiry();
                if ($pre->status !== 'success' || !$expiry || $expiry->format('Y-m-d') !== $claimed->base_expires_at->format('Y-m-d')) {
                    return $this->finish($claimed, 'held', 'provider_expiry_changed');
                }
                $info = $api->renewalInfo($domain->domain);
                if ($info->status !== 'success') { return $this->finish($claimed, 'held', 'renewal_unavailable'); }
            }
            if (in_array($claimed->action, ['privacy_purchase', 'privacy_renew'], true)) {
                $privacyResult = $api->privacy($domain->domain);
                $privacy = EnomApi::normalize('privacy', $privacyResult);
                if (($privacy['status'] ?? null) !== 'success' || ($privacy['allowed'] ?? null) !== true
                    || ($privacy['exists'] ?? null) !== ($claimed->action === 'privacy_renew')) {
                    return $this->finish($claimed, 'held', 'privacy_state_changed');
                }
                if ($claimed->action === 'privacy_renew') {
                    $base = $privacyResult->confirmedPrivacyExpiry();
                    if (!$base || !$claimed->base_expires_at || $base->format('Y-m-d') !== $claimed->base_expires_at->format('Y-m-d')) {
                        return $this->finish($claimed, 'held', 'privacy_expiry_changed');
                    }
                }
            }
            if (in_array($claimed->action, ['hosts', 'srv'], true)) {
                $preimage = $api->snapshot($domain->domain, [$claimed->action]);
                if (($preimage['read_status'][$claimed->action] ?? null) !== 'success'
                    || !hash_equals(DomainManager::digest(DomainManager::safeSnapshot($preimage)[$claimed->action]), $claimed->payload['preimage_digest'] ?? '')) {
                    return $this->finish($claimed, 'held', 'dns_preimage_changed');
                }
            }
            // Re-check immediately at dispatch as well as on each transport request.
            $this->apiForServer((int) $domain->server_id);
            $sealed = $this->sealDispatch($claimed);
            if (!$sealed) { return $claimed->fresh(); }
            $claimed = $sealed;
            $domain = $claimed->domain;
            $result = $this->send($api, $domain, $claimed);
            $command = $claimed->policy['provider_command'] ?? ($claimed->action === 'register' ? 'Purchase' : 'Extend');
            $orderId = $result->value($claimed->action === 'transfer' ? 'transferorderid'
                : ($command === 'UpdateExpiredDomains' ? 'reactivatedomainname.orderid' : 'orderid'));
            if (is_scalar($orderId) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', (string) $orderId)) {
                $claimed->update(['provider_order_id' => (string) $orderId]);
            }
            if (in_array($claimed->action, ['register', 'renew'], true)) {
                $completedOrder = null;
                $execution = ['command' => $command, 'status' => $result->status, 'orderid' => $claimed->provider_order_id];
                if ($command === 'UpdateExpiredDomains') {
                    $execution['reactivatedomainname'] = ['orderid' => $claimed->provider_order_id,
                        'status' => $result->value('reactivatedomainname.status') === 'true' ? 'true' : null];
                }
                // Capture only bounded identity/acknowledgement, never the provider envelope.
                $claimed->update(['cost' => array_replace($claimed->cost ?? [], ['execution' => $execution])]);
            }
            if ($result->status === 'rejected') { return $this->finish($claimed, 'rejected', $this->rejectionCode($result->code)); }
            if ($result->status === 'pending') { return $this->finish($claimed, 'pending', 'provider_pending'); }
            if ($result->status !== 'success') { return $this->finish($claimed, 'uncertain', 'provider_unknown'); }
            if (in_array($claimed->action, ['register', 'renew'], true)) {
                $responseExpiry = $result->confirmedExpiry();
                $read = $api->expiry($domain->domain);
                $readExpiry = $read->confirmedExpiry();
                $expiryMatches = $responseExpiry && $readExpiry
                    && $readExpiry->format('Y-m-d') === $responseExpiry->format('Y-m-d');
                if ($claimed->action === 'register' && $claimed->provider_order_id && $responseExpiry
                    && $read->status === 'success' && $readExpiry && !$expiryMatches) {
                    // If Purchase and the dedicated getter disagree, require the exact completed order to corroborate the getter.
                    $order = $api->order($claimed->provider_order_id);
                    $completedOrder = $order;
                    $normalized = EnomApi::normalize('order', $order);
                    $orderExpiry = $order->confirmedExpiry();
                    $expiryMatches = $order->status === 'success' && $order->value('errcount') === '0'
                        && ($normalized['provider_order_id'] ?? null) === $claimed->provider_order_id
                        && ($normalized['domain'] ?? null) === $domain->domain && ($normalized['completed'] ?? false) === true
                        && ($normalized['term'] ?? null) === 1 && $orderExpiry
                        && $orderExpiry->format('Y-m-d') === $readExpiry->format('Y-m-d');
                }
                if (!$claimed->provider_order_id || !$responseExpiry || $read->status !== 'success' || !$readExpiry
                    || !$expiryMatches
                    || ($claimed->expected_expires_at && $readExpiry->format('Y-m-d') !== $claimed->expected_expires_at->format('Y-m-d'))) {
                    return $this->finish($claimed, 'uncertain', 'expiry_unconfirmed');
                }
                $anchor = $this->initialAnchor($claimed, $api, $readExpiry);
                if ($domain->billing_managed && $claimed->action === 'register' && !$anchor) {
                    return $this->finish($claimed, 'uncertain', 'provider_anchor_unconfirmed');
                }
                $this->recordActualCost($claimed, $api, $completedOrder);
                return $this->confirm($claimed->fresh(), $readExpiry, $anchor);
            }
            if (in_array($claimed->action, ['privacy_purchase', 'privacy_renew'], true)) {
                $privacyRead = $api->privacy($domain->domain);
                $snapshot = EnomApi::normalize('privacy', $privacyRead);
                $expiry = $privacyRead->confirmedPrivacyExpiry();
                if ($privacyRead->status !== 'success' || ($snapshot['exists'] ?? null) !== true || !$claimed->provider_order_id || !$expiry
                    || !$this->privacyExpiryMatches($claimed, $expiry)) {
                    return $this->finish($claimed, 'uncertain', 'privacy_expiry_unconfirmed');
                }
                $claimed->confirmed_expires_at = $expiry->format('Y-m-d');
                $claimed->save();
                $this->recordSnapshot($domain, ['privacy' => $snapshot, 'read_status' => ['privacy' => $privacyRead->status]]);
            } elseif ($claimed->action === 'epp_sync') {
                return $this->finish($claimed, 'succeeded', 'epp_sync_acknowledged');
            } else {
                // Readback confirmation is shared with the customer management module.
                $kind = match ($claimed->action) { 'contacts' => 'contacts', 'lock' => 'lock', 'privacy_enable', 'privacy_disable' => 'privacy', 'dnssec_add', 'dnssec_delete' => 'dnssec', 'provider_renewal_disable' => 'renewal', default => $claimed->action };
                $snapshot = $api->snapshot($domain->domain, [$kind]);
                if (!$this->managementMatches($claimed, $snapshot)) { return $this->finish($claimed, 'uncertain', 'management_unconfirmed'); }
                $this->recordSnapshot($domain, $snapshot);
            }
            return $this->finish($claimed, 'succeeded', 'confirmed');
        } catch (Throwable) {
            return $this->interruptedClaim($claimed, 'execution_interrupted');
        }
    }

    private function send(EnomApi $api, Domain $domain, Operation $operation)
    {
        $payload = $operation->payload ?? [];
        return match ($operation->action) {
            'register' => $api->register($domain->domain, $payload['contacts'] ?? [], $payload['nameservers'] ?? [], 1),
            'transfer' => $api->transfer($domain->domain, $payload['contacts'] ?? [], $payload['epp'] ?? ''),
            'renew' => ($operation->policy['provider_command'] ?? null) === 'UpdateExpiredDomains'
                ? $api->expiredRenew($domain->domain, 1)
                : ($domain->billing_managed ? $api->existingRenew($domain->domain, 1) : $api->renew($domain->domain, 1)),
            'privacy_purchase' => $api->purchasePrivacy($domain->domain, 1),
            'privacy_renew' => $api->renewPrivacy($domain->domain, 1),
            'nameservers' => $api->setNameservers($domain->domain, $payload['nameservers']),
            'contacts' => $api->updateContact($domain->domain, $payload['contact_type'], $payload['contact']),
            'lock' => $api->setLock($domain->domain, $payload['locked']),
            'epp_sync' => $api->synchronizeEpp($domain->domain),
            'privacy_enable' => $api->enablePrivacy($domain->domain, true),
            'privacy_disable' => $api->enablePrivacy($domain->domain, false),
            'hosts' => $api->setHosts($domain->domain, $payload['records']),
            'srv' => $api->setSrv($domain->domain, $payload['records']),
            'dnssec_add' => $api->addDnssec($domain->domain, $payload['ds']),
            'dnssec_delete' => $api->deleteDnssec($domain->domain, $payload['ds']),
            'provider_renewal_disable' => $api->disableProviderRenewal($domain->domain),
            default => throw new RuntimeException('Unsupported registrar action.'),
        };
    }

    private function executionLifecycleValid(Operation $operation): bool
    {
        $domain = $operation->domain;
        $service = $domain?->service;
        if (!$service) { return false; }
        if (in_array($operation->action, ['register', 'transfer'], true)) {
            return in_array($service->status, [Service::STATUS_PENDING, Service::STATUS_ACTIVE], true)
                && $domain->status === ($operation->action === 'register' ? 'registration_pending' : 'transfer_pending')
                && !$service->cancellation()->exists();
        }
        return $service->status === Service::STATUS_ACTIVE
            && ($operation->action !== 'renew' || !$service->cancellation()->exists());
    }

    private function managementMatches(Operation $operation, array $snapshot): bool
    {
        $payload = $operation->payload ?? [];
        $kind = match ($operation->action) { 'lock' => 'lock', 'privacy_enable', 'privacy_disable' => 'privacy', 'dnssec_add', 'dnssec_delete' => 'dnssec', 'provider_renewal_disable' => 'renewal', default => $operation->action };
        if (($snapshot['read_status'][$kind] ?? null) !== 'success') { return false; }
        return match ($operation->action) {
            'nameservers' => $this->nameserversMatch($payload['nameservers'] ?? null, $snapshot['nameservers'] ?? null),
            'lock' => isset($snapshot['locked']) && $snapshot['locked'] === ($payload['locked'] ?? null),
            'privacy_enable' => ($snapshot['privacy']['enabled'] ?? null) === true,
            'privacy_disable' => ($snapshot['privacy']['enabled'] ?? null) === false,
            'contacts' => isset($snapshot['contacts'][$payload['contact_type'] ?? '']) && $this->contactsMatch($payload['contact'] ?? [], $snapshot['contacts'][$payload['contact_type']]),
            'hosts', 'srv' => self::canonicalRecords($operation->action, DomainManager::safeSnapshot($snapshot)[$operation->action]) === self::canonicalRecords($operation->action, $payload['records'] ?? []),
            'dnssec_add' => in_array(self::canonicalRecords('dnssec', [$payload['ds'] ?? []])[0] ?? null, self::canonicalRecords('dnssec', $snapshot['dnssec'] ?? []), true),
            'dnssec_delete' => !in_array(self::canonicalRecords('dnssec', [$payload['ds'] ?? []])[0] ?? null, self::canonicalRecords('dnssec', $snapshot['dnssec'] ?? []), true),
            'provider_renewal_disable' => ($snapshot['renewal']['auto_renew'] ?? null) === false,
            // EPP synchronization has no supported plaintext/readback contract.
            default => false,
        };
    }

    private function nameserversMatch(mixed $requested, mixed $actual): bool
    {
        $requestedSet = $this->canonicalNameservers($requested);
        $actualSet = $this->canonicalNameservers($actual);
        return $requestedSet !== null && $actualSet !== null && $requestedSet === $actualSet;
    }

    private function canonicalNameservers(mixed $values): ?array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) < 2 || count($values) > 12) { return null; }
        $names = [];
        foreach ($values as $value) {
            if (!is_string($value)) { return null; }
            $name = strtolower(str_ends_with($value, '.') ? substr($value, 0, -1) : $value);
            if (strlen($name) > 253 || !str_contains($name, '.') || filter_var($name, FILTER_VALIDATE_IP)
                || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $name)
                || in_array($name, $names, true)) { return null; }
            $names[] = $name;
        }
        sort($names, SORT_STRING);
        return $names;
    }

    public function reconcile(int $operationId): Operation
    {
        $operation = Operation::findOrFail($operationId);
        if ($operation->state === 'succeeded' && isset($operation->cost['execution'])
            && ($operation->cost['actual']['state'] ?? null) !== 'confirmed') {
            $this->recordActualCost($operation, $this->apiForServer((int) $operation->domain->server_id, true));
            return $operation->fresh();
        }
        if ($operation->state === 'running') {
            if ($operation->lease_expires_at?->isFuture()) { return $operation; }
            $operation = $this->interruptedClaim($operation, 'stale_claim', true);
        }
        if (!in_array($operation->state, ['uncertain', 'pending'], true)) { return $operation; }
        $domain = $operation->domain;
        if (!$domain || !$domain->service || !$this->validService($domain->service, $domain) || !$this->funded($operation)) { return $operation; }
        $api = $this->apiForServer((int) $domain->server_id, true);
        // Known extension order identity is mandatory. Existing account ownership alone proves nothing.
        if (in_array($operation->action, ['register', 'renew', 'transfer'], true)) {
            if (!$operation->provider_order_id) { return $operation; }
            $order = $operation->action === 'transfer' ? $api->transferOrder($operation->provider_order_id) : $api->order($operation->provider_order_id);
            $normalized = EnomApi::normalize($operation->action === 'transfer' ? 'transfer' : 'order', $order);
            $transferHeader = $order->value('transferorder');
            $transferDetail = $order->value('transferorder.transferorderdetail');
            if ($operation->action === 'transfer' && $order->status === 'rejected'
                && $order->value('errcount') === '0'
                && is_array($transferHeader) && !array_is_list($transferHeader)
                && ($transferHeader['statusid'] ?? null) === '2'
                && is_array($transferDetail) && !array_is_list($transferDetail)
                && is_string($transferDetail['sld'] ?? null) && is_string($transferDetail['tld'] ?? null)
                && strtolower($transferDetail['sld'].'.'.$transferDetail['tld']) === $domain->domain
                && ($transferHeader['transferorderid'] ?? null) === $operation->provider_order_id
                // Require a terminal failed detail as well as the cancelled header. Contradictory evidence stays unresolved.
                && in_array($order->value('transferorder.transferorderdetail.statusid'), ['2', '4', '6', '7', '8', '15', '16', '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '30', '31', '32', '33', '34', '36', '37', '45', '101'], true)) {
                return DB::transaction(function () use ($operation, $domain, $order) {
                    $currentDomain = Domain::whereKey($domain->id)->lockForUpdate()->firstOrFail();
                    $current = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                    if (!in_array($current->state, ['pending', 'uncertain'], true)
                        || $current->action !== 'transfer' || $current->provider_order_id !== $operation->provider_order_id
                        || (int) $current->domain_id !== (int) $domain->id
                        || $currentDomain->domain !== $domain->domain || !$this->funded($current)) { return $current; }

                    return $this->finish($current, 'rejected', $this->rejectionCode($order->code));
                });
            }
            if ($order->status !== 'success' || ($normalized['domain'] ?? null) !== $domain->domain || empty($normalized['completed'])
                || ($normalized['provider_order_id'] ?? null) !== $operation->provider_order_id
                || ($operation->action !== 'transfer' && ($normalized['term'] ?? null) !== 1)) { return $operation; }
            $expiryResult = $api->expiry($domain->domain);
            $expiry = $expiryResult->confirmedExpiry();
            if ($expiryResult->status !== 'success' || !$expiry || ($operation->expected_expires_at && $expiry->format('Y-m-d') !== $operation->expected_expires_at->format('Y-m-d'))) { return $operation; }
            $anchor = $this->initialAnchor($operation, $api, $expiry);
            if ($domain->billing_managed && in_array($operation->action, ['register', 'transfer'], true) && !$anchor) { return $operation; }
            $this->recordActualCost($operation, $api, $operation->action !== 'transfer' ? $order : null);
            return $this->confirm($operation->fresh(), $expiry, $anchor);
        }
        if (in_array($operation->action, ['privacy_purchase', 'privacy_renew'], true)) {
            if (!$operation->provider_order_id || !$operation->expected_expires_at) { return $operation; }
            $orderResult = $api->order($operation->provider_order_id);
            $order = EnomApi::normalize('order', $orderResult);
            if ($orderResult->status !== 'success' || ($order['provider_order_id'] ?? null) !== $operation->provider_order_id
                || ($order['domain'] ?? null) !== $domain->domain || empty($order['completed']) || ($order['term'] ?? null) !== 1) { return $operation; }
            $read = $api->privacy($domain->domain);
            $expiry = $read->confirmedPrivacyExpiry();
            $privacy = EnomApi::normalize('privacy', $read);
            if ($read->status !== 'success' || ($privacy['exists'] ?? null) !== true || !$expiry
                || !$this->privacyExpiryMatches($operation, $expiry)) { return $operation; }
            $this->recordSnapshot($domain, ['privacy' => $privacy, 'read_status' => ['privacy' => $read->status]]);
            $operation->confirmed_expires_at = $expiry->format('Y-m-d');
            $operation->save();
            return $this->finish($operation, 'succeeded', 'privacy_expiry_confirmed');
        }
        $kind = match ($operation->action) { 'contacts' => 'contacts', 'lock' => 'lock', 'privacy_enable', 'privacy_disable' => 'privacy', 'dnssec_add', 'dnssec_delete' => 'dnssec', 'provider_renewal_disable' => 'renewal', default => $operation->action };
        if ($operation->action === 'epp_sync') { return $operation; }
        $snapshot = $api->snapshot($domain->domain, [$kind]);
        if ($this->managementMatches($operation, $snapshot)) {
            $this->recordSnapshot($domain, $snapshot);
            return $this->finish($operation, 'succeeded', 'readback_confirmed');
        }
        return $operation;
    }

    private function initialAnchor(Operation $operation, EnomApi $api, \DateTimeImmutable $expiry): ?string
    {
        if (!$operation->domain->billing_managed || !in_array($operation->action, ['register', 'transfer'], true)) { return null; }
        $configuration = $this->providerConfiguration((int) $operation->domain->server_id, true);
        $account = RenewalAdmission::accountFingerprint($configuration);
        if (($operation->policy['account_fingerprint'] ?? null) !== $account) { return null; }
        $result = $api->info($operation->domain->domain);
        $info = EnomApi::normalize('info', $result);
        $id = $info['domain_name_id'] ?? null;
        return ($info['status'] ?? null) === 'success'
            && ($info['domain'] ?? null) === $operation->domain->domain
            && is_string($info['belongs_to'] ?? null) && strcasecmp($info['belongs_to'], $configuration['uid']) === 0
            && is_string($id) && preg_match('/^[1-9][0-9]{0,19}$/D', $id)
            && $result->confirmedExpiry()?->format('Y-m-d') === $expiry->format('Y-m-d') ? $id : null;
    }

    /** A cost read never proves registrar renewal and never changes payment history. */
    private function recordActualCost(Operation $operation, EnomApi $api, ?RegistrarResult $order = null): void
    {
        if (!in_array($operation->action, ['register', 'renew'], true) || !$operation->provider_order_id
            || !isset($operation->cost['execution'])) { return; }
        $cost = $operation->cost ?? [];
        if (($cost['actual']['state'] ?? null) === 'confirmed'
            || in_array($cost['actual']['reason'] ?? null, ['renewal_item_contract_unaccepted', 'cost_poll_limit_reached'], true)) { return; }
        $identity = $cost['execution'];
        try {
            $configuration = $this->providerConfiguration((int) $operation->domain->server_id, true);
            if (isset($operation->policy['account_fingerprint'])
                && $operation->policy['account_fingerprint'] !== RenewalAdmission::accountFingerprint($configuration)) { return; }
            $reserved = DB::transaction(function () use ($operation, $identity) {
                Domain::whereKey($operation->domain_id)->lockForUpdate()->firstOrFail();
                $current = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                $cost = $current->cost ?? [];
                if (($cost['execution'] ?? null) !== $identity || ($cost['actual']['state'] ?? null) === 'confirmed') { return false; }
                $attempts = $cost['actual_attempts'] ?? 0;
                if (!is_int($attempts) || $attempts < 0 || $attempts >= self::COST_POLL_LIMIT) { return false; }
                $cost['actual_attempts'] = $attempts + 1;
                $current->update(['cost' => $cost]);
                return true;
            });
            if (!$reserved) { return; }
            $order ??= $api->order($operation->provider_order_id);
            $actual = RegistrarMoney::actualCost($order, ['provider_order_id' => $operation->provider_order_id,
                'domain' => $operation->domain->domain, 'action' => $operation->action, 'term' => (int) $operation->term,
                'provider_command' => $identity['command']], new RegistrarResult($identity['status'], null, $identity));
        } catch (Throwable) {
            $actual = ['state' => 'unconfirmed', 'source' => 'GetOrderDetail', 'amount' => null, 'currency' => null];
        }
        if ($operation->action === 'renew' && $actual['state'] === 'unconfirmed' && ($identity['command'] ?? null) !== 'Extend') {
            $actual['reason'] = 'renewal_item_contract_unaccepted';
        }
        DB::transaction(function () use ($operation, $identity, $actual) {
            $domain = Domain::whereKey($operation->domain_id)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($current->provider_order_id !== $operation->provider_order_id || $domain->domain !== $operation->domain->domain
                || ($current->cost['execution'] ?? null) !== $identity || (int) $current->service_id !== (int) $domain->service_id) { return; }
            $cost = $current->cost ?? [];
            if (($cost['actual']['state'] ?? null) === 'confirmed') { return; }
            $cost['actual'] = $actual + ['observed_at' => now()->toIso8601String()];
            if (($actual['state'] ?? null) !== 'confirmed' && ($cost['actual_attempts'] ?? 0) >= self::COST_POLL_LIMIT) {
                $cost['actual']['reason'] = 'cost_poll_limit_reached';
            }
            $current->update(['cost' => $cost]);
            $limit = $current->funding_basis === 'sponsored' ? ($current->policy['sponsorship']['limit_minor'] ?? null)
                : (($cost['quote']['currency'] ?? null) === $actual['currency'] && RegistrarMoney::decimal($cost['quote']['amount'] ?? null) !== null
                    ? $this->cents($cost['quote']['amount']) : null);
            if ($actual['state'] === 'confirmed' && is_int($limit) && $actual['currency'] === $current->currency
                && $this->cents($actual['amount']) > $limit) {
                $policy = $domain->renewal_policy ?? [];
                $policy['automatic_spend_paused'] = true;
                $observation = $domain->observation ?? [];
                $observation['cost_health'] = ['state' => 'actual_cost_exceeded', 'operation_id' => $current->id,
                    'observed_at' => now()->toIso8601String()];
                $domain->update(['renewal_policy' => $policy, 'observation' => $observation]);
            }
        });
    }

    private function confirm(Operation $operation, \DateTimeImmutable $expiry, ?string $anchor = null): Operation
    {
        return DB::transaction(function () use ($operation, $expiry, $anchor) {
            $domain = Domain::whereKey($operation->domain_id)->lockForUpdate()->firstOrFail();
            $operation = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (!in_array($operation->state, ['running', 'pending', 'uncertain'], true)) { return $operation; }
            if (!$this->funded($operation) || $operation->action === 'renew' && $domain->service?->cancellation()->exists()) {
                return $this->finish($operation, 'uncertain', 'confirmation_ownership_or_funding_changed');
            }
            $service = $domain->service;
            $expected = in_array($operation->action, ['register', 'transfer'], true)
                ? [Service::STATUS_PENDING, Service::STATUS_ACTIVE] : [Service::STATUS_ACTIVE];
            if (!$service || !in_array($service->status, $expected, true)) {
                return $this->finish($operation, 'uncertain', 'confirmation_lifecycle_changed');
            }
            $domain->update(['status' => 'active', 'provider_expires_at' => $expiry->format('Y-m-d'),
                'provider_domain_id' => $anchor ?? $domain->provider_domain_id,
                'capabilities' => array_merge($domain->capabilities ?? [], ['renew' => true]),
                'provider_order_id' => $operation->provider_order_id, 'last_synced_at' => now(), 'drift_detected_at' => null,
                'confirmed_revision' => $domain->confirmed_revision + 1]);
            $service->status = Service::STATUS_ACTIVE;
            $service->expires_at = $domain->billing_managed ? null : \Carbon\Carbon::instance($expiry)->subDays(RuntimeGuard::leadDays((int) $domain->server_id))->format('Y-m-d');
            $service->save();
            OrderIntent::where('service_id', $service->id)->update(['payload' => null]);
            $operation->confirmed_expires_at = $expiry->format('Y-m-d');
            return $this->finish($operation, 'succeeded', 'expiry_confirmed');
        });
    }

    private function rejectionCode(?string $code): string
    {
        if ($code === 'guard_refused') { return 'guard_refused'; }
        return $code !== null && preg_match('/^[0-9]{1,6}$/D', $code) === 1 ? 'provider_rejected:'.$code : 'provider_rejected';
    }

    /** New managed requests persist this contract at creation; old rows remain conservative. */
    private function definitelyUnsent(Operation $operation, Domain $domain): bool
    {
        return $domain->billing_managed && ($operation->policy['dispatch_marker_version'] ?? null) === 1
            && !array_key_exists('dispatch_attempted_at', $operation->policy ?? [])
            && !array_key_exists('execution', $operation->cost ?? []) && $operation->provider_order_id === null;
    }

    private function sameClaim(Operation $current, Operation $claimed): bool
    {
        return $current->state === $claimed->getRawOriginal('state')
            && $current->getRawOriginal('lease_expires_at') === $claimed->getRawOriginal('lease_expires_at')
            && $current->getRawOriginal('started_at') === $claimed->getRawOriginal('started_at')
            && ($current->policy['dispatch_claim_token'] ?? null) === ($claimed->policy['dispatch_claim_token'] ?? null);
    }

    private function classifyInterruptedLocked(Operation $operation, Domain $domain, string $code): Operation
    {
        $unsent = $this->definitelyUnsent($operation, $domain);
        $operation->update(['state' => $unsent ? 'held' : 'uncertain',
            'safe_code' => $unsent ? 'pre_send_interrupted' : $code, 'lease_expires_at' => null]);
        return $operation;
    }

    private function interruptedClaim(Operation $claimed, string $code, bool $expiredOnly = false): Operation
    {
        return DB::transaction(function () use ($claimed, $code, $expiredOnly) {
            $domain = Domain::whereKey($claimed->domain_id)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'running' || !$this->sameClaim($current, $claimed)
                || $expiredOnly && $current->lease_expires_at?->isFuture()) { return $current; }
            return $this->classifyInterruptedLocked($current, $domain, $code);
        });
    }

    private function updateClaimEvidence(Operation $claimed, array $cost, array $policy = []): ?Operation
    {
        return DB::transaction(function () use ($claimed, $cost, $policy) {
            Domain::whereKey($claimed->domain_id)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'running' || !$this->sameClaim($current, $claimed)) { return null; }
            $current->update(['cost' => array_replace($current->cost ?? [], $cost),
                'policy' => array_replace($current->policy ?? [], $policy)]);
            return $current;
        });
    }

    /** Commit a unique marker after the final checks, with no provider request inside the lock. */
    private function sealDispatch(Operation $claimed): ?Operation
    {
        return DB::transaction(function () use ($claimed) {
            $domain = Domain::whereKey($claimed->domain_id)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
            $current->setRelation('domain', $domain);
            if ($current->state !== 'running' || !$this->sameClaim($current, $claimed)
                || array_key_exists('dispatch_attempted_at', $current->policy ?? [])
                || array_key_exists('execution', $current->cost ?? []) || $current->provider_order_id !== null) { return null; }
            if (!$current->lease_expires_at || $current->lease_expires_at->lt(now()->addSeconds(RegistrarClient::REQUEST_TIMEOUT_SECONDS))) {
                $this->finish($current, 'held', 'dispatch_lease_insufficient');
                return null;
            }
            if (self::writesPaused($domain) || !$this->funded($current) || !$this->executionLifecycleValid($current)
                || $current->action === 'renew' && ($domain->service->cancellation()->exists()
                    || !$domain->provider_expires_at || !$current->base_expires_at
                    || !$domain->provider_expires_at->equalTo($current->base_expires_at))) {
                $this->finish($current, 'held', 'dispatch_authority_changed');
                return null;
            }
            $policy = $current->policy ?? [];
            $policy['dispatch_attempted_at'] = now()->toIso8601String();
            $current->setAttribute('policy', $policy);
            $changed = Operation::whereKey($current->id)->where('state', 'running')
                ->where('lease_expires_at', $claimed->getRawOriginal('lease_expires_at'))
                ->where('lease_expires_at', '>=', now()->addSeconds(RegistrarClient::REQUEST_TIMEOUT_SECONDS))
                ->where('started_at', $claimed->getRawOriginal('started_at'))
                ->where('policy', $current->getRawOriginal('policy'))
                ->update(['policy' => $current->getAttributes()['policy']]);
            return $changed === 1 ? $current->fresh() : null;
        });
    }

    private function finish(Operation $operation, string $state, string $code): Operation
    {
        return DB::transaction(function () use ($operation, $state, $code) {
            Domain::whereKey($operation->domain_id)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (!$this->sameClaim($current, $operation)
                || !in_array($current->state, ['running', 'pending', 'uncertain'], true)) { return $current; }
            $updates = ['state' => $state, 'safe_code' => $code, 'lease_expires_at' => null];
            if ($operation->isDirty('confirmed_expires_at')) { $updates['confirmed_expires_at'] = $operation->confirmed_expires_at; }
            if (in_array($state, ['succeeded', 'rejected'], true)) {
                $updates['completed_at'] = now();
                $updates['payload'] = null;
                if (in_array($operation->action, ['register', 'transfer'], true)) { OrderIntent::where('service_id', $operation->service_id)->update(['payload' => null]); }
            }
            $current->update($updates);
            if ($state === 'rejected' && in_array($current->action, ['register', 'transfer'], true)) {
                Domain::whereKey($current->domain_id)->where('service_id', $current->service_id)
                    ->where('status', $current->action === 'register' ? 'registration_pending' : 'transfer_pending')
                    ->whereNull('provider_order_id')
                    ->update(['status' => $current->action === 'register' ? 'registration_rejected' : 'transfer_rejected']);
            }
            return $current->fresh();
        });
    }

    public function sweep(): array
    {
        $summary = ['queued' => 0, 'uncertain' => 0, 'drift' => 0];
        $cursor = (int) Cache::get('enom:sweep:operations', 0);
        $query = fn ($cursor) => Operation::whereIn('state', ['awaiting_payment', 'queued', 'running', 'pending', 'uncertain'])->where('id', '>', $cursor)->orderBy('id')->limit(50)->get();
        $batch = $query($cursor);
        if ($batch->isEmpty() && $cursor > 0) { $batch = $query(0); }
        foreach ($batch as $operation) {
            try {
                if (!$operation->domain || !$this->scheduledProviderEnabled($operation->domain,
                    in_array($operation->state, ['running', 'pending', 'uncertain'], true))) { continue; }
                if ($operation->state === 'running' && (!$operation->lease_expires_at || $operation->lease_expires_at->isPast())) {
                    $current = $this->interruptedClaim($operation, 'stale_claim', true);
                    if ($current->state === 'uncertain') { $summary['uncertain']++; }
                } elseif ($operation->state === 'awaiting_payment' && $operation->invoice_id) {
                    $invoice = Invoice::find($operation->invoice_id);
                    if ($invoice) { $summary['queued'] += count($this->acceptPaidInvoice($invoice)); }
                } elseif ($operation->state === 'queued') {
                    ExecuteOperation::dispatch($operation->id)->afterCommit();
                    $summary['queued']++;
                } elseif (in_array($operation->state, ['pending', 'uncertain'], true)) { ReconcileOperation::dispatch($operation->id)->afterCommit(); }
            } catch (Throwable) {}
        }
        Cache::put('enom:sweep:operations', (int) ($batch->last()?->id ?? 0), now()->addDay());
        $costCursor = (int) Cache::get('enom:sweep:costs', 0);
        $costQuery = fn ($cursor) => Operation::where('state', 'succeeded')->whereIn('action', ['register', 'renew'])
            ->whereNotNull('cost')->where('id', '>', $cursor)->orderBy('id')->limit(25)->get();
        $costBatch = $costQuery($costCursor);
        if ($costBatch->isEmpty() && $costCursor > 0) { $costBatch = $costQuery(0); }
        foreach ($costBatch as $operation) {
            if (!$operation->domain || !$this->scheduledProviderEnabled($operation->domain, true)) { continue; }
            $cost = $operation->cost ?? [];
            if (!isset($cost['execution']) || ($cost['actual']['state'] ?? null) === 'confirmed'
                || in_array($cost['actual']['reason'] ?? null, ['renewal_item_contract_unaccepted', 'cost_poll_limit_reached'], true)
                || !is_int($cost['actual_attempts'] ?? 0) || ($cost['actual_attempts'] ?? 0) >= self::COST_POLL_LIMIT) { continue; }
            $at = $cost['actual']['observed_at'] ?? null;
            if (is_string($at) && \Carbon\CarbonImmutable::parse($at)->greaterThan(now()->subDay())) { continue; }
            ReconcileOperation::dispatch($operation->id)->afterCommit();
        }
        Cache::put('enom:sweep:costs', (int) ($costBatch->last()?->id ?? 0), now()->addDay());
        $domainCursor = (int) Cache::get('enom:sweep:domains', 0);
        $domainQuery = fn ($cursor) => Domain::whereNotNull('provider_expires_at')->where('id', '>', $cursor)->orderBy('id')->limit(100)->get();
        $domains = $domainQuery($domainCursor);
        if ($domains->isEmpty() && $domainCursor > 0) { $domains = $domainQuery(0); }
        foreach ($domains as $domain) {
            try {
            if ($domain->billing_managed) { continue; }
            if (!$this->scheduledProviderEnabled($domain)) { continue; }
            $expected = $domain->provider_expires_at->subDays(RuntimeGuard::leadDays((int) $domain->server_id));
            if (!$domain->service || !$this->validService($domain->service, $domain) || !$domain->service->expires_at?->equalTo($expected)) {
                $domain->update(['drift_detected_at' => $domain->drift_detected_at ?? now()]);
                $summary['drift']++;
            }
            } catch (Throwable) {}
        }
        Cache::put('enom:sweep:domains', (int) ($domains->last()?->id ?? 0), now()->addDay());
        return $summary;
    }

    private function scheduledProviderEnabled(Domain $domain, bool $readOnly = false): bool
    {
        $server = $domain->service?->product?->server;
        if (!$server?->enabled || $server->extension !== 'Enom' || (int) $server->id !== (int) $domain->server_id) { return false; }
        $settings = $server->settings->pluck('value', 'key');
        return filter_var($readOnly && $settings->has('reads_enabled') ? $settings->get('reads_enabled')
            : $settings->get('operations_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function approveFunding(int $operationId, User $staff, string $reason): Operation
    {
        abort_unless($staff->hasPermission('admin.enom.manage'), 404);
        if (strlen(trim($reason)) < 5 || strlen($reason) > 500) { throw new RuntimeException('A funding reason is required.'); }
        $domainId = Operation::whereKey($operationId)->firstOrFail(['domain_id'])->domain_id;
        return DB::transaction(function () use ($operationId, $domainId, $staff, $reason) {
            // Serialize every funding/write admission with execute and enqueueManagement.
            $domain = Domain::whereKey($domainId)->lockForUpdate()->firstOrFail();
            $operation = Operation::whereKey($operationId)->where('domain_id', $domain->id)->lockForUpdate()->firstOrFail();
            if (!in_array($operation->state, ['awaiting_payment', 'held'], true) || $operation->started_at || $this->cents($operation->amount) <= 0) {
                throw new RuntimeException('This operation cannot receive a new funding authorization.');
            }
            if (self::hasUnresolvedPeer($domain, $operation->id)) {
                throw new InputValidationException(['enom' => 'Funding is unavailable while another registrar request is unresolved. Review its outcome first.']);
            }
            if ($operation->invoice_id || $operation->safe_code === 'binding_invalid'
                || $operation->base_expires_at && !$operation->base_expires_at->equalTo($domain->provider_expires_at)) {
                throw new InputValidationException(['enom' => 'A customer invoice or changed registration cannot be overridden with funding. Review sponsorship and the invoice disposition separately.']);
            }
            $operation->update(['funding_basis' => 'operator_funded', 'authorized_by' => $staff->id, 'funding_reason' => trim($reason), 'state' => 'queued']);
            ExecuteOperation::dispatch($operation->id)->afterCommit();
            return $operation;
        });
    }

    public static function hasUnresolvedPeer(Domain $domain, int $operationId): bool
    {
        return $domain->operations()->where('id', '!=', $operationId)->whereIn('state', self::UNRESOLVED_STATES)->exists();
    }

    public static function hasRenewalBlocker(Domain $domain, ?int $exceptId = null): bool
    {
        return $domain->operations()->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where(function ($query) {
                $query->whereIn('state', self::UNRESOLVED_STATES)->orWhere(function ($query) {
                    $query->where('action', 'renew')->whereIn('state', ['awaiting_payment', 'queued', 'held']);
                });
            })->exists();
    }

    public function canReadmitHeld(Operation $operation, ?User $actor = null): bool
    {
        try {
            $operation = $operation->fresh() ?? $operation;
            if ($actor && (!$actor->hasPermission('admin.enom.manage')
                || $operation->funding_basis === 'sponsored' && !$actor->hasPermission('admin.enom.fund'))) { return false; }
            return $operation->state === 'held' && $operation->action === 'renew' && $operation->domain?->billing_managed
                && in_array($operation->funding_basis, ['paid_invoice', 'sponsored'], true)
                && $operation->provider_order_id === null && !isset($operation->cost['execution'])
                && !isset($operation->policy['dispatch_attempted_at'])
                && count($operation->policy['readmissions'] ?? []) < 10
                && !($operation->domain->renewal_policy['automatic_spend_paused'] ?? false)
                && $this->funded($operation) && $this->executionLifecycleValid($operation)
                && $operation->base_expires_at && $operation->domain->provider_expires_at
                && $operation->base_expires_at->equalTo($operation->domain->provider_expires_at)
                && !self::hasRenewalBlocker($operation->domain, $operation->id);
        } catch (Throwable) { return false; }
    }

    /** Re-admit the same never-sent obligation. No new invoice or payment. */

    /** Audited observation may resolve provider facts after commercial authority changed. */
    public function canReviewProviderFact(Operation $operation): bool
    {
        $operation = $operation->fresh() ?? $operation;
        return $operation->action === 'renew' && in_array($operation->state, ['uncertain', 'pending'], true)
            && $operation->domain?->billing_managed && ($operation->policy['billing_managed'] ?? false) === true
            && (isset($operation->policy['dispatch_attempted_at']) || isset($operation->cost['execution']))
            && ($operation->policy['domain'] ?? null) === $operation->domain->domain
            && ($operation->policy['server_id'] ?? null) === (int) $operation->domain->server_id
            && (int) $operation->term === 1 && ($operation->policy['term'] ?? null) === 1
            && is_string($operation->policy['provider_domain_id'] ?? null)
            && preg_match('/^[1-9][0-9]{0,19}$/D', $operation->policy['provider_domain_id']) === 1;
    }

    private function reviewBinding(Operation $operation): array
    {
        $domain = $operation->domain;
        return ['operation_id' => $operation->id, 'domain_id' => $operation->domain_id, 'state' => $operation->state, 'action' => $operation->action,
            'order_id' => $operation->provider_order_id, 'service_id' => $operation->service_id,
            'base' => $operation->base_expires_at?->format('Y-m-d'), 'expected' => $operation->expected_expires_at?->format('Y-m-d'),
            'policy_domain' => $operation->policy['domain'] ?? null, 'policy_server' => $operation->policy['server_id'] ?? null,
            'account' => $operation->policy['account_fingerprint'] ?? null, 'anchor' => $operation->policy['provider_domain_id'] ?? null,
            'dispatch' => $operation->policy['dispatch_attempted_at'] ?? null, 'execution' => $operation->cost['execution'] ?? null,
            'domain' => $domain->domain, 'server_id' => $domain->server_id, 'user_id' => $domain->user_id,
            'current_service_id' => $domain->service_id, 'authority_generation' => $domain->authority_generation,
            'confirmed_revision' => $domain->confirmed_revision, 'confirmed_expiry' => $domain->provider_expires_at?->format('Y-m-d')];
    }

    public function observeProviderFact(int $operationId, User $staff): Operation
    {
        abort_unless($staff->hasPermission('admin.enom.manage'), 403);
        $operation = Operation::findOrFail($operationId);
        if (!$this->canReviewProviderFact($operation)) { throw new InputValidationException(['enom' => 'Only a managed post-send renewal awaiting review can record provider facts.']); }
        $capture = $this->reviewBinding($operation);
        $history = $operation->policy['provider_fact_history'] ?? [];
        if (!is_array($history) || count($history) >= 10) { throw new InputValidationException(['enom' => 'Provider-fact observation limit reached. Review retained evidence separately.']); }
        $configuration = $this->providerConfiguration((int) $operation->domain->server_id, true);
        $account = RenewalAdmission::accountFingerprint($configuration);
        if (!is_string($capture['account']) || !hash_equals($capture['account'], $account)) { throw new InputValidationException(['enom' => 'The original authenticated provider account must match before recording a fact.']); }
        $api = $this->apiForServer((int) $operation->domain->server_id, true);
        $infoResult = $api->info($operation->domain->domain);
        $info = EnomApi::normalize('info', $infoResult);
        if ($infoResult->status !== 'success' || ($info['domain'] ?? null) !== $capture['domain']
            || ($info['domain_name_id'] ?? null) !== $capture['anchor']
            || !is_string($info['belongs_to'] ?? null) || strcasecmp($info['belongs_to'], $configuration['uid']) !== 0) {
            throw new InputValidationException(['enom' => 'Authenticated provider account and exact domain identity require review.']);
        }
        $expiryResult = $api->expiry($capture['domain']);
        $expiry = $expiryResult->confirmedExpiry();
        if ($expiryResult->status !== 'success' || !$expiry || !$infoResult->confirmedExpiry()
            || $infoResult->confirmedExpiry()->format('Y-m-d') !== $expiry->format('Y-m-d')) {
            throw new InputValidationException(['enom' => 'Provider identity and expiry observations must agree.']);
        }
        $knownOrder = $operation->provider_order_id !== null;
        $completed = false;
        if ($knownOrder) {
            $orderResult = $api->order($operation->provider_order_id);
            $order = EnomApi::normalize('order', $orderResult);
            $completed = $orderResult->status === 'success' && ($order['domain'] ?? null) === $capture['domain']
                && ($order['provider_order_id'] ?? null) === $capture['order_id'] && ($order['term'] ?? null) === 1
                && ($order['completed'] ?? false) === true && $expiry->format('Y-m-d') === $capture['expected'];
        }
        $fact = ['version' => 1, 'observed_at' => now()->toIso8601String(), 'recorded_by' => (int) $staff->id,
            'account_fingerprint' => $account, 'domain' => $capture['domain'], 'provider_domain_id' => $capture['anchor'],
            'expires_at' => $expiry->format('Y-m-d'), 'known_order' => $knownOrder, 'order_completed' => $completed,
            'binding_digest' => hash('sha256', json_encode($capture, JSON_THROW_ON_ERROR))];
        $fact['digest'] = hash('sha256', json_encode($fact, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($operationId, $capture, $account, $fact, $history) {
            Domain::whereKey($capture['domain_id'])->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($operationId)->lockForUpdate()->firstOrFail();
            if (!$this->canReviewProviderFact($current) || $this->reviewBinding($current) !== $capture
                || !hash_equals($account, RenewalAdmission::accountFingerprint($this->providerConfiguration((int) $current->domain->server_id, true)))) {
                throw new InputValidationException(['enom' => 'The account or obligation changed during observation. Refresh its facts again.']);
            }
            $policy = $current->policy ?? [];
            $previous = $policy['provider_fact_history'] ?? [];
            if ($previous !== $history) { throw new InputValidationException(['enom' => 'A newer provider observation was recorded. Review the latest facts first.']); }
            $previous[] = $fact;
            $policy['provider_fact_history'] = $previous;
            $policy['provider_fact'] = $fact;
            $current->update(['policy' => $policy]);
            return $current->fresh();
        });
    }

    public function disposeProviderFact(int $operationId, User $staff, string $digest, string $outcome, string $financial, string $reason): Operation
    {
        abort_unless($staff->hasPermission('admin.enom.manage'), 403);
        if (!in_array($outcome, ['provider_completed', 'unattributed_provider_fact'], true)
            || $financial !== 'manual_review_required' || strlen(trim($reason)) < 10 || strlen($reason) > 500
            || preg_match('/[\x00-\x1f\x7f]/', $reason)) { throw new InputValidationException(['reason' => 'Record the explicit provider outcome, retained financial review and a 10 to 500 byte reason.']); }
        $domainId = Operation::findOrFail($operationId)->domain_id;
        return DB::transaction(function () use ($operationId, $domainId, $staff, $digest, $outcome, $financial, $reason) {
            $domain = Domain::whereKey($domainId)->lockForUpdate()->firstOrFail();
            $current = Operation::whereKey($operationId)->lockForUpdate()->firstOrFail(); $current->setRelation('domain', $domain);
            $fact = $current->policy['provider_fact'] ?? [];
            try { $at = \Carbon\CarbonImmutable::parse($fact['observed_at'] ?? ''); }
            catch (Throwable) { $at = null; }
            $account = RenewalAdmission::accountFingerprint($this->providerConfiguration((int) $domain->server_id, true));
            if (!$this->canReviewProviderFact($current) || !is_string($fact['digest'] ?? null) || !hash_equals($fact['digest'], $digest)
                || !$at || $at->isFuture() || $at->lt(now()->subMinutes(15))
                || ($fact['binding_digest'] ?? null) !== hash('sha256', json_encode($this->reviewBinding($current), JSON_THROW_ON_ERROR))
                || ($fact['account_fingerprint'] ?? null) !== $account
                || !is_bool($fact['known_order'] ?? null)
                || ($fact['known_order'] ?? null) === true && ($outcome !== 'provider_completed' || ($fact['order_completed'] ?? false) !== true)
                || ($fact['known_order'] ?? null) === false && $outcome !== 'unattributed_provider_fact') {
                throw new InputValidationException(['enom' => 'Fresh matching provider facts and the original known-order proof are required. No request was resent.']);
            }
            $policy = $current->policy ?? [];
            $policy['staff_disposition'] = ['staff_id' => (int) $staff->id, 'at' => now()->toIso8601String(), 'reason' => trim($reason),
                'outcome' => $outcome, 'financial' => $financial, 'fact_digest' => $digest, 'previous_state' => $current->state, 'previous_code' => $current->safe_code];
            $domainPolicy = $domain->renewal_policy ?? [];
            $domainPolicy['enabled'] = false; $domainPolicy['automatic_spend_paused'] = true; $domainPolicy['staff_disposition_hold'] = true;
            $domain->update(['renewal_policy' => $domainPolicy]);
            $current->update(['policy' => $policy, 'state' => 'disposed', 'safe_code' => 'staff_provider_fact_disposed', 'lease_expires_at' => null, 'completed_at' => now()]);
            return $current->fresh();
        });
    }

    public function providerReviewData(Operation $operation): array
    {
        $operation = $operation->fresh() ?? $operation;
        $fact = $operation->policy['provider_fact'] ?? [];
        $disposition = $operation->policy['staff_disposition'] ?? [];
        return ['eligible' => $this->canReviewProviderFact($operation), 'fact' => array_intersect_key($fact,
            array_flip(['observed_at', 'expires_at', 'known_order', 'order_completed', 'digest'])),
            'disposition' => array_intersect_key($disposition, array_flip(['at', 'staff_id', 'reason', 'outcome', 'financial']))];
    }

    /** Current read-only report. Never treats late transaction activity as renewal authority. */
    public function retiredInvoicePayments(): array
    {
        $rows = [];
        Operation::where('action', 'renew')->where('safe_code', 'renewal_cancelled_unpaid')->whereNotNull('invoice_id')
            ->orderBy('id')->chunkById(100, function ($operations) use (&$rows) {
                foreach ($operations as $operation) {
                    $invoice = Invoice::find($operation->invoice_id);
                    if (!$invoice || !$invoice->transactions()->exists()) { continue; }
                    $rows[] = ['operation_id' => $operation->id, 'invoice_id' => $invoice->id, 'domain' => $operation->domain?->domain,
                        'invoice_status' => $invoice->status, 'transaction_count' => $invoice->transactions()->count(),
                        'transaction_digest' => hash('sha256', json_encode($invoice->transactions()->orderBy('id')->get(['id', 'amount', 'status', 'fee', 'is_credit_transaction', 'updated_at'])->toArray(), JSON_THROW_ON_ERROR)),
                        'review' => 'Manual financial review required; this retired request does not authorize renewal.'];
                }
            });
        return $rows;
    }

    /** Daily persisted report of retired-invoice activity; provider/network/payment-free. */
    public function reportRetiredInvoicePayments(): int
    {
        $count = 0;
        foreach ($this->retiredInvoicePayments() as $row) {
            DB::transaction(function () use ($row) {
                $domainId = Operation::findOrFail($row['operation_id'])->domain_id;
                Domain::whereKey($domainId)->lockForUpdate()->firstOrFail();
                $operation = Operation::whereKey($row['operation_id'])->lockForUpdate()->firstOrFail();
                if ($operation->safe_code !== 'renewal_cancelled_unpaid' || $operation->invoice_id !== $row['invoice_id']) { return; }
                $policy = $operation->policy ?? [];
                $report = array_intersect_key($row, array_flip(['invoice_id', 'transaction_count', 'invoice_status', 'transaction_digest']));
                if (($policy['retired_payment_review']['report'] ?? null) !== $report) {
                    $policy['retired_payment_review'] = ['report' => $report, 'detected_at' => now()->toIso8601String()];
                    $operation->update(['policy' => $policy]);
                }
            });
            $count++;
        }
        return $count;
    }

    public function readmitHeld(int $operationId, User $staff, string $reason): Operation
    {
        abort_unless($staff->hasPermission('admin.enom.manage'), 404);
        $reason = trim($reason);
        if (strlen($reason) < 5 || strlen($reason) > 500 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new InputValidationException(['reason' => 'Enter a review reason between 5 and 500 bytes.']);
        }
        $domainId = Operation::findOrFail($operationId)->domain_id;
        return DB::transaction(function () use ($operationId, $domainId, $staff, $reason) {
            $domain = Domain::whereKey($domainId)->lockForUpdate()->firstOrFail();
            $operation = Operation::whereKey($operationId)->lockForUpdate()->firstOrFail();
            $operation->setRelation('domain', $domain);
            if (!$this->canReadmitHeld($operation, $staff)) {
                throw new InputValidationException(['enom' => 'Only a fully funded, verified unsent renewal can resume. Review sent, uncertain or changed obligations separately.']);
            }
            // Guard configuration is checked before queueing; admission still repeats fresh provider reads.
            $this->providerConfiguration((int) $domain->server_id);
            $policy = $operation->policy ?? [];
            $reviews = is_array($policy['readmissions'] ?? null) ? $policy['readmissions'] : [];
            if (count($reviews) >= 10) { throw new InputValidationException(['enom' => 'The bounded review-attempt limit was reached. Resolve this obligation separately.']); }
            $reviews[] = ['staff_id' => (int) $staff->id, 'reason' => $reason, 'at' => now()->toIso8601String(), 'previous_code' => $operation->safe_code];
            $policy['readmissions'] = $reviews;
            $operation->update(['state' => 'queued', 'safe_code' => null, 'lease_expires_at' => null, 'policy' => $policy]);
            ExecuteOperation::dispatch($operation->id)->afterCommit();
            return $operation->fresh();
        });
    }

    /** Caller holds the domain lock. Retain every paid or transaction-bearing obligation. */
    public function retireUnpaidRenewals(Domain $domain): void
    {
        $service = $domain->service;
        if (!$domain->billing_managed || !$service || (int) $service->user_id !== (int) $domain->user_id
            || $service->product?->server?->extension !== 'Enom' || (int) $service->product->server_id !== (int) $domain->server_id) { return; }
        foreach ($domain->operations()->where('action', 'renew')->where('funding_basis', 'paid_invoice')
            ->whereIn('state', ['awaiting_payment', 'held', 'queued'])->lockForUpdate()->get() as $operation) {
            if ((int) $operation->service_id !== (int) $service->id || $operation->currency !== $service->currency_code) { continue; }
            $uid = $service->product?->server?->settings->firstWhere('key', 'uid')?->value;
            if (isset($operation->policy['account_fingerprint']) && (!is_string($uid) || $uid === ''
                || $operation->policy['account_fingerprint'] !== hash('sha256', 'sandbox:'.strtolower($uid)))) { continue; }
            if (isset($operation->policy['provider_account_identity']) && (!is_string($uid) || $uid === ''
                || $operation->policy['provider_account_identity'] !== hash('sha256', strtolower(trim($uid))))) { continue; }
            if ($operation->provider_order_id || isset($operation->policy['dispatch_attempted_at']) || isset($operation->cost['execution'])) { continue; }
            $invoice = Invoice::whereKey($operation->invoice_id)->lockForUpdate()->first();
            $item = $invoice?->items()->whereKey($operation->invoice_item_id)->first();
            if (!$invoice || !$item || $invoice->status !== Invoice::STATUS_PENDING || $invoice->transactions()->exists()
                || (int) $invoice->user_id !== (int) $domain->user_id || $invoice->currency_code !== $operation->currency
                || $invoice->items()->count() !== 1 || $item->reference_id !== null || $item->reference_type !== null
                || (int) $item->quantity !== 1 || $this->cents($item->price) !== $this->cents($operation->amount)) { continue; }
            $invoice->status = Invoice::STATUS_CANCELLED;
            $invoice->save();
            $operation->update(['state' => 'rejected', 'safe_code' => 'renewal_cancelled_unpaid', 'lease_expires_at' => null,
                'completed_at' => now(), 'payload' => null]);
        }
    }

    public function enqueueManagement(User $actor, int $domainId, string $action, array $payload, string $nonce): Operation
    {
        $allowed = ['nameservers', 'contacts', 'lock', 'epp_sync', 'privacy_enable', 'privacy_disable', 'hosts', 'srv', 'dnssec_add', 'dnssec_delete', 'provider_renewal_disable'];
        if (!in_array($action, $allowed, true) || !preg_match('/^[a-f0-9]{32,64}$/D', $nonce)) { throw new RuntimeException('Invalid domain request.'); }
        $key = 'management:'.$domainId.':'.$action.':'.hash('sha256', $nonce);
        return DB::transaction(function () use ($actor, $domainId, $action, $payload, $key) {
            $domain = Domain::where('user_id', $actor->id)->whereKey($domainId)->lockForUpdate()->firstOrFail();
            if (self::writesPaused($domain)) { throw new InvalidArgumentException('This domain requires staff disposition and binding review before further registrar changes.'); }
            abort_unless($domain->service && $domain->service->user_id == $actor->id, 404);
            if ($existing = Operation::where('operation_key', $key)->where('domain_id', $domainId)->first()) { return $existing; }
            if ($domain->operations()->whereIn('state', self::UNRESOLVED_STATES)->exists()) {
                throw new InvalidArgumentException('An existing registrar request must complete or be reviewed before a new change.');
            }
            $this->apiForServer((int) $domain->server_id); // Guard only; constructing a client never performs HTTP.
            if ($domain->status !== 'active' || !$this->validService($domain->service, $domain)) { throw new RuntimeException('Domain management is unavailable.'); }
            if (($domain->capabilities[$action] ?? false) !== true) { throw new RuntimeException('This registrar action is unavailable.'); }
            if ($action !== 'provider_renewal_disable') { $payload = DomainManager::validatePayload($action, $payload + ['confirmed' => true]); }
            $operation = Operation::create(['operation_key' => $key,
                'domain_id' => $domain->id, 'service_id' => $domain->service_id, 'action' => $action, 'term' => 1,
                'funding_basis' => 'non_billable_management', 'authorized_by' => $actor->id,
                'state' => 'queued', 'currency' => $domain->service->currency_code, 'amount' => 0, 'payload' => $payload,
                'policy' => $domain->billing_managed ? ['dispatch_marker_version' => 1] : null,
            ]);
            ExecuteOperation::dispatch($operation->id)->afterCommit();

            return $operation;
        });
    }

    public function ownedDomain(User $actor, int $domainId): Domain
    {
        $domain = Domain::where('user_id', $actor->id)->findOrFail($domainId);
        abort_unless($domain->service && $domain->service->user_id == $actor->id, 404);
        return $domain;
    }

    public function refreshSnapshot(User $actor, int $domainId): Domain
    {
        $domain = $this->ownedDomain($actor, $domainId);
        // Refresh one bounded set per request; callers can choose cached pages while provider is unavailable.
        $snapshot = $this->apiForServer((int) $domain->server_id, true)->snapshot($domain->domain);
        $status = $snapshot['read_status'] ?? [];
        $capabilities = ['renew' => $domain->status === 'active', 'epp_sync' => $domain->status === 'active'];
        foreach (['nameservers', 'contacts', 'hosts', 'srv'] as $action) { $capabilities[$action] = ($status[$action] ?? null) === 'success'; }
        $capabilities['lock'] = ($status['lock'] ?? null) === 'success' && is_bool($snapshot['locked'] ?? null);
        $capabilities += $this->stateCapabilities($domain, $snapshot, ['privacy', 'dnssec'], $actor);
        $domain->update(['snapshot' => $snapshot, 'capabilities' => $capabilities, 'last_synced_at' => now()]);
        return $domain;
    }

    public function toggleRenewalPreference(User $actor, int $domainId, bool $enabled): Domain
    {
        $domain = $this->ownedDomain($actor, $domainId);
        return DB::transaction(function () use ($domain, $enabled) {
            $domain = Domain::whereKey($domain->id)->lockForUpdate()->firstOrFail();
            if (!$enabled) {
                if ($domain->billing_managed) { $this->retireUnpaidRenewals($domain); }
                ServiceCancellation::firstOrCreate(['service_id' => $domain->service_id], ['type' => 'end_of_period', 'reason' => 'Domain renewal disabled by owner.']);
            } else {
                if ($domain->status !== 'active' || !$domain->provider_expires_at?->isFuture()
                    || Operation::where('domain_id', $domain->id)->whereIn('state', ['running', 'uncertain', 'pending'])->exists()) {
                    throw new RuntimeException('Review domain expiry before enabling renewal.');
                }
                ServiceCancellation::where('service_id', $domain->service_id)->where('type', 'end_of_period')->delete();
            }
            return $domain;
        });
    }

    private function cents($amount): int { return (int) round((float) $amount * 100); }

    /** Registry records are unordered tuples. XML numbers and form integers have the same meaning. */
    public static function canonicalRecords(string $kind, array $records): array
    {
        $fields = match ($kind) {
            'hosts' => ['name', 'type', 'address', 'mx_pref'],
            'srv' => ['service', 'protocol', 'target', 'priority', 'weight', 'port'],
            'dnssec' => ['algorithm', 'digest', 'digest_type', 'key_tag'],
            default => throw new RuntimeException('Invalid registrar record kind.'),
        };
        $result = [];
        foreach ($records as $record) {
            $tuple = [];
            foreach ($fields as $field) {
                // Enom accepts mail priority only for MX records. Other record priorities are irrelevant.
                if ($kind === 'hosts' && $field === 'mx_pref' && strtoupper($record['type'] ?? '') !== 'MX') {
                    continue;
                }
                if (!array_key_exists($field, $record)) { throw new RuntimeException('Incomplete registrar readback.'); }
                $value = $record[$field];
                if (in_array($field, ['mx_pref', 'priority', 'weight', 'port', 'algorithm', 'digest_type', 'key_tag'], true)) {
                    if (filter_var($value, FILTER_VALIDATE_INT) === false) { throw new RuntimeException('Invalid registrar numeric readback.'); }
                    $value = (int) $value;
                } elseif (!is_string($value)) { throw new RuntimeException('Invalid registrar record readback.'); }
                if ($field === 'digest' || $field === 'type') { $value = strtoupper($value); }
                if ($field === 'protocol') { $value = strtolower($value); }
                $tuple[$field] = $value;
            }
            ksort($tuple);
            $result[] = $tuple;
        }
        usort($result, fn ($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        return $result;
    }

    private function contactsMatch(array $requested, array $actual): bool
    {
        foreach ($requested as $key => $value) { if (($actual[$key] ?? '') !== $value) { return false; } }
        return true;
    }

    private function privacyExpiryMatches(Operation $operation, \DateTimeImmutable $expiry): bool
    {
        if (!$operation->expected_expires_at) { return false; }
        $actual = \Carbon\CarbonImmutable::instance($expiry)->startOfDay();
        if ($operation->action === 'privacy_purchase') {
            // Provider date-only/Pacific timestamps can cross the UTC calendar boundary.
            return abs($operation->expected_expires_at->diffInDays($actual, false)) <= 1;
        }
        return $actual->format('Y-m-d') === $operation->expected_expires_at->format('Y-m-d');
    }

    /** Reuse verified readback without fetching unrelated provider sections. */
    private function recordSnapshot(Domain $domain, array $partial): void
    {
        $snapshot = $this->mergedSnapshot($domain, $partial);
        $updates = $this->stateCapabilities($domain, $snapshot, array_keys($partial['read_status'] ?? []));
        $domain->update(['snapshot' => $snapshot,
            'capabilities' => array_merge($domain->capabilities ?? [], $updates), 'last_synced_at' => now()]);
    }

    /** Derive only state-dependent capabilities for the sections actually read. */
    private function stateCapabilities(Domain $domain, array $snapshot, array $sections, ?User $actor = null): array
    {
        $capabilities = [];
        $status = $snapshot['read_status'] ?? [];
        if (in_array('privacy', $sections, true)) {
            $privacy = ($status['privacy'] ?? null) === 'success' && ($snapshot['privacy']['allowed'] ?? false) === true;
            foreach (['privacy_enable', 'privacy_disable'] as $action) { $capabilities[$action] = $privacy && ($snapshot['privacy']['exists'] ?? false) === true; }
            $privacyPrice = $domain->service->product->settings()->where('key', 'enom_privacy_price')->first()?->value;
            $priced = is_numeric($privacyPrice) && (float) $privacyPrice > 0;
            $capabilities['privacy_purchase'] = $priced && $privacy && ($snapshot['privacy']['exists'] ?? null) === false;
            $capabilities['privacy_renew'] = $priced && $privacy && ($snapshot['privacy']['exists'] ?? null) === true && !empty($snapshot['privacy']['expires_at']);
        }
        if (in_array('dnssec', $sections, true)) {
            $actor ??= User::find($domain->user_id);
            $verifiedDnssec = ($status['dnssec'] ?? null) === 'success' && ($snapshot['dnssec_shape_verified'] ?? false) === true
                && is_array($snapshot['dnssec'] ?? null);
            $controlledDnssecDemo = $actor?->email === 'demo@example.com' && $domain->status === 'active'
                && $domain->provider_order_id && preg_match('/^demo-domain-[a-f0-9]{16}\\.com$/D', $domain->domain) === 1;
            $capabilities['dnssec_add'] = $controlledDnssecDemo && $verifiedDnssec;
            $capabilities['dnssec_delete'] = $controlledDnssecDemo && $verifiedDnssec && $snapshot['dnssec'] !== [];
        }

        return $capabilities;
    }

    private function mergedSnapshot(Domain $domain, array $partial): array
    {
        $snapshot = $domain->snapshot ?? [];
        foreach (array_keys($partial['read_status'] ?? []) as $kind) {
            $key = $kind === 'lock' ? 'locked' : $kind;
            if (array_key_exists($key, $partial)) { $snapshot[$key] = $partial[$key]; }
            $snapshot['read_status'][$kind] = $partial['read_status'][$kind];
            if ($kind === 'dnssec') { $snapshot['dnssec_shape_verified'] = $partial['dnssec_shape_verified'] ?? false; }
            if ($kind === 'contacts' && isset($partial['contact_status'])) { $snapshot['contact_status'] = $partial['contact_status']; }
        }
        return $snapshot;
    }
}
