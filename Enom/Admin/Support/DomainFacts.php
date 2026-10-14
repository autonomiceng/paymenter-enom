<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Support;

use App\Models\Invoice;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainAdministration;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\Pricing;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;
use Throwable;

/**
 * Read-only view model for the staff domain page, built once per request.
 * Uses the cached registrar snapshot (allowlisted by DomainManager::safeSnapshot); never calls Enom.
 */
final class DomainFacts
{
    /** @var array<int, array> */
    private static array $cache = [];

    public static function forget(int $domainId): void { unset(self::$cache[$domainId]); }

    public static function for(Domain $domain): array
    {
        return self::$cache[(int) $domain->id] ??= self::build($domain);
    }

    private static function build(Domain $domain): array
    {
        Relations::register();
        $service = $domain->service;
        $client = $domain->relationLoaded('client') ? $domain->getRelation('client') : $domain->client()->first();
        $serverId = (int) $domain->server_id;
        $snapshot = DomainManager::safeSnapshot(self::quiet(fn () => $domain->snapshot) ?? []);
        $pays = Labels::whoPays($domain);
        $renewal = self::quiet(fn () => Pricing::renewalPrice($domain));
        $customer = self::quiet(fn () => DomainAdministration::customer($domain)) ?? [];

        $registered = Operation::query()->where('domain_id', $domain->id)->whereIn('action', ['register', 'transfer'])
            ->where('state', 'succeeded')->orderBy('id')->value('completed_at');

        $attention = Operation::query()->select(['id', 'action', 'state', 'cost', 'created_at'])->where('domain_id', $domain->id)
            ->whereIn('state', Labels::ATTENTION_STATES)->orderByDesc('id')->limit(5)->get();

        $nextInvoice = null;
        if ($pays['mode'] !== 'complimentary' && $service && !$service->cancellation) {
            $nextInvoice = $customer['calendar']['issue_at'] ?? null;
            if (!$nextInvoice && !$domain->billing_managed) { $nextInvoice = $service->expires_at; }
        }

        return [
            'client' => $client,
            'client_complimentary' => Labels::clientComplimentary($client),
            'service' => $service,
            'environment' => RuntimeGuard::isTest($serverId) ? 'test' : 'live',
            'default_nameservers' => RuntimeGuard::defaultNameservers($serverId),
            'snapshot' => $snapshot,
            'contacts_same' => self::contactsSame($snapshot['contacts']),
            'pays' => $pays,
            'renewal_price' => $renewal,
            'currency' => $service?->currency_code ?? 'USD',
            'renews' => $service !== null && !$service->cancellation,
            'next_invoice' => $nextInvoice,
            'registered' => $registered,
            'attention' => $attention,
            'paused' => DomainOperations::writesPaused($domain),
            'unresolved' => $domain->operations()->whereIn('state', DomainOperations::UNRESOLVED_STATES)->exists(),
            'invoices' => self::invoices($domain),
            'capabilities' => array_filter(self::quiet(fn () => $domain->capabilities) ?? [], fn ($on) => $on === true),
            'last_checked' => Labels::lastChecked($domain),
            'observation_problem' => $customer['observation_problem'] ?? null,
        ];
    }

    /** Latest invoices for the domain: native service invoices plus invoices bound to its registrar requests. */
    private static function invoices(Domain $domain): array
    {
        $ids = Operation::query()->where('domain_id', $domain->id)->whereNotNull('invoice_id')->pluck('invoice_id');
        if ($domain->service) {
            $ids = $ids->merge($domain->service->invoices()->pluck('invoices.id'));
        }
        $ids = $ids->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) { return []; }
        return Invoice::query()->whereIn('id', $ids)->where('user_id', $domain->user_id)
            ->orderByDesc('id')->limit(6)->get()->all();
    }

    private static function contactsSame(array $contacts): bool
    {
        $first = $contacts['registrant'] ?? null;
        if (!$first) { return false; }
        foreach (['admin', 'tech', 'auxbilling'] as $role) {
            if (($contacts[$role] ?? null) !== $first) { return false; }
        }
        return true;
    }

    private static function quiet(callable $read): mixed
    {
        try { return $read(); } catch (Throwable) { return null; }
    }
}
