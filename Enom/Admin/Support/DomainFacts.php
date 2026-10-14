<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Support;

use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;
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
            // Only roles the registry keeps count; for most gTLDs that is the registrant alone.
            'contacts_same' => self::contactsSame(array_intersect_key($snapshot['contacts'], array_flip($snapshot['contact_roles'] ?? ['registrant', 'admin', 'tech', 'auxbilling']))),
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
            'glue' => self::glue($snapshot, self::quiet(fn () => $domain->snapshot) ?? []),
            'registry' => self::registry($domain, $snapshot),
            'renewal' => self::renewalOptions($domain, $client),
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

    /**
     * Child nameservers: [{host, ip}]. Uses the allowlisted snapshot when it carries them,
     * otherwise reads only host and ip strings from the stored snapshot.
     * @return list<array{host: string, ip: string}>
     */
    private static function glue(array $safe, mixed $raw): array
    {
        $records = array_key_exists('glue', $safe) ? $safe['glue'] : (is_array($raw) ? ($raw['glue'] ?? []) : []);
        $glue = [];
        foreach (is_array($records) ? array_slice($records, 0, 50) : [] as $record) {
            $host = is_array($record) ? ($record['host'] ?? null) : null;
            $ip = is_array($record) ? ($record['ip'] ?? null) : null;
            if (is_string($host) && $host !== '' && strlen($host) <= 253 && is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                $glue[] = ['host' => strtolower(rtrim($host, '.')), 'ip' => $ip];
            }
        }
        usort($glue, fn ($a, $b) => strnatcmp($a['host'], $b['host']));
        return $glue;
    }

    /**
     * Registry details sent with the order (for example .us nexus), labelled from the ending's schema.
     * @return list<string> "Label: value"
     */
    private static function registry(Domain $domain, array $snapshot): array
    {
        $values = $snapshot['registry'] ?? null;
        if (!is_array($values) || $values === []) {
            $values = self::quiet(function () use ($domain) {
                $operation = Operation::query()->where('domain_id', $domain->id)->whereIn('action', ['register', 'transfer'])
                    ->orderByDesc('id')->first(['id', 'payload']);
                return $operation?->payload['registry'] ?? null;
            });
        }
        if (!is_array($values) || $values === []) { return []; }
        $tld = Tld::query()->where('server_id', $domain->server_id)->where('tld', $domain->tld)->first();
        $fields = $tld ? collect(TldResource::registryFields($tld))->keyBy('name') : collect();
        $lines = [];
        foreach (array_slice($values, 0, 20, true) as $name => $value) {
            if (!is_string($name) || !is_scalar($value) || (string) $value === '') { continue; }
            $field = $fields->get($name);
            $label = $field['label'] ?? $name;
            $shown = (string) $value;
            foreach ($field['options'] ?? [] as $option) {
                if (is_array($option) && (string) ($option['value'] ?? '') === $shown && is_string($option['label'] ?? null) && $option['label'] !== '') {
                    $shown = $option['label'];
                    break;
                }
            }
            $lines[] = mb_strimwidth($label, 0, 80, '…').': '.mb_strimwidth($shown, 0, 120, '…');
        }
        return $lines;
    }

    /**
     * Years a staff "Renew now" can add: 1–10, and never past 10 years from today (registry rule).
     * Prefers StaffDomains::renewalYears, then DomainOrders::renewalOptions, then the same rule computed here.
     * @return array{years: list<int>, price_per_year: ?string, multi: bool}
     */
    private static function renewalOptions(Domain $domain, ?User $client): array
    {
        $multi = self::quiet(fn () => method_exists(Staff::STAFF_SERVICE, 'renewNow')
            && (new \ReflectionMethod(Staff::STAFF_SERVICE, 'renewNow'))->getNumberOfParameters() >= 3) === true;
        $options = $client && method_exists(DomainOrders::class, 'renewalOptions')
            ? self::quiet(fn () => app(DomainOrders::class)->renewalOptions($client, (int) $domain->id)) : null;
        // Staff terms come first: client options cap domains outside Paymenter billing at one year.
        $staffYears = Staff::supports('renewalYears') ? self::quiet(fn () => Staff::service('renewalYears')->renewalYears((int) $domain->id)) : null;
        $source = is_array($staffYears) ? $staffYears : ($options['years'] ?? null);
        $years = is_array($source) ? array_values(array_filter($source, fn ($year) => is_int($year) && $year >= 1 && $year <= 10)) : null;
        if ($years === null) {
            $expires = Labels::day($domain->provider_expires_at);
            $limit = CarbonImmutable::today()->addYears(10);
            $years = array_values(array_filter(range(1, 10), fn (int $year) => !$expires || $expires->addYears($year)->lte($limit)));
        }
        if (!$multi) { $years = in_array(1, $years, true) ? [1] : []; }
        $price = $options['price_per_year'] ?? null;
        return ['years' => $years, 'price_per_year' => is_numeric($price) ? (string) $price : null, 'multi' => $multi];
    }

    private static function contactsSame(array $contacts): bool
    {
        // Same comparison as the client page: every field, across the roles the registry keeps.
        return !empty($contacts['registrant']) && \Paymenter\Extensions\Servers\Enom\Services\DomainManager::contactsMatch($contacts);
    }

    private static function quiet(callable $read): mixed
    {
        try { return $read(); } catch (Throwable) { return null; }
    }
}
