<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Throwable;

/** Customer-safe observation, progress and invoice projections. */
class DomainAdministration
{
    /** An explicit allowlist prevents private observation and cost serialization. */
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
     * Viewer permissions never widen this. Only invoice id, number, status and due date are read, never amounts.
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
        foreach (Invoice::query()->select(['id', 'user_id', 'number', 'status', 'due_at'])
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
            $verified[$requestId] = ['id' => $candidate['invoice_id'], 'number' => $invoice->number,
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
        $entry = function (?string $status, ?array $request, ?int $invoiceId, bool $notSent = false): array {
            $label = DomainPresentation::progress($status, $request);
            $awaiting = $label === DomainPresentation::AWAITING_PAYMENT;
            return ['label' => $label, 'kind' => match ($status) { 'registration_pending' => 'registration', 'transfer_pending' => 'transfer', default => null },
                'awaiting_payment' => $awaiting, 'invoice_id' => $awaiting ? $invoiceId : null, 'not_sent' => $notSent,
                'state' => $request['state'] ?? null, 'safe_code' => in_array($request['safe_code'] ?? null, ['contact_details_required', 'transfer_code_required', 'transfer_code_timeout', 'transfer_not_registered'], true) ? $request['safe_code'] : null];
        };
        $result = []; $pending = []; $owners = []; $refused = []; $servers = [];
        foreach ($domains as $domain) {
            if (!($domainId = self::key(data_get($domain, 'id')))) { continue; }
            $status = is_string(data_get($domain, 'status')) ? data_get($domain, 'status') : null;
            $result[$domainId] = $entry($status, null, null);
            if ($status === 'transfer_rejected') { $refused[] = $domainId; }
            if ($status !== null && isset($actions[$status])) {
                $pending[$domainId] = $status;
                $owners[$domainId] = ['service_id' => (int) data_get($domain, 'service_id'), 'user_id' => (int) data_get($domain, 'user_id')];
                $servers[$domainId] = (int) data_get($domain, 'server_id');
            }
        }
        // A transfer cancelled for a missing code reads differently from one the registrar refused.
        foreach ($refused ? Operation::query()->whereIn('id', Operation::query()->selectRaw('MAX(id)')
            ->whereIn('domain_id', $refused)->where('action', 'transfer')->groupBy('domain_id'))
            ->toBase()->get(['domain_id', 'safe_code']) : [] as $request) {
            if ($request->safe_code === 'transfer_code_timeout') { $result[(int) $request->domain_id]['safe_code'] = $request->safe_code; }
        }
        if (!$pending) { return $result; }
        $latest = [];
        foreach (Operation::query()->select(['id', 'domain_id', 'service_id', 'invoice_id', 'invoice_item_id', 'action', 'state', 'safe_code'])
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
                'safe_code' => $request->safe_code, 'invoiced' => $request->invoice_id !== null, 'invoice_status' => $link['status'] ?? null], $link['id'] ?? null,
                // Processing off: a paid, queued order is saved but not sent.
                $request->state === 'queued' && $servers[$domainId] > 0 && !RuntimeGuard::flag($servers[$domainId], 'operations_enabled'));
        }
        return $result;
    }

    private static function key(mixed $value): ?int
    {
        if (is_int($value)) { return $value > 0 ? $value : null; }
        return is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) ? (int) $value : null;
    }

    private static function clock(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) { return null; }
        try { return CarbonImmutable::parse($value); } catch (Throwable) { return null; }
    }
    private static function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) { return $value->format('Y-m-d'); }
        if (!is_string($value)) { return null; }
        $date = substr($value, 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) { return null; }
        return $date;
    }
}
