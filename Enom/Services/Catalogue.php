<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Paymenter\Extensions\Servers\Enom\Api\DomainName;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use RuntimeException;

/** Domain endings for sale, their prices and the native product that bills them. */
class Catalogue
{
    public const PRODUCT_SETTING = 'enom_catalogue';

    public function __construct(private DomainOperations $operations) {}

    /** @return Collection<int, Tld> endings customers can order, across Enom servers */
    public function orderable(): Collection
    {
        return Tld::query()->where('enabled', true)
            ->whereIn('server_id', Server::query()->where('extension', 'Enom')->pluck('id'))
            ->orderBy('sort_order')->orderBy('tld')->get()
            ->filter(fn (Tld $tld) => $tld->orderable())->values();
    }

    /**
     * Resolve a customer's input against the endings for sale.
     *
     * @return array{tld: Tld, domain: string, sld: string}
     */
    public function resolve(string $input): array
    {
        $tlds = $this->orderable();
        if ($tlds->isEmpty()) { throw new RuntimeException('Domain ordering is not available yet.'); }
        $parts = DomainName::parse($input, $tlds->pluck('tld')->unique()->all());
        if ($tlds->where('tld', $parts['tld'])->count() > 1) { throw new RuntimeException('.'.$parts['tld'].' is set up on more than one Enom server. Ask support.'); }
        if (str_starts_with($parts['sld'], 'xn--')) { throw new InvalidArgumentException('International domain names are not available.'); }
        return ['tld' => $tlds->firstWhere('tld', $parts['tld']), 'domain' => $parts['domain'], 'sld' => $parts['sld']];
    }

    /** @return list<string> every ending this server may address, including those of existing registrations */
    public function suffixes(int $serverId): array
    {
        return Tld::query()->where('server_id', $serverId)->pluck('tld')
            ->merge(Domain::query()->where('server_id', $serverId)->distinct()->pluck('tld'))
            ->map(fn ($tld) => strtolower((string) $tld))->unique()->values()->all();
    }

    /**
     * Add every ending Enom offers this account, disabled, then read current costs.
     *
     * @return array{added: int, total: int, costs: int}
     */
    public function import(int $serverId): array
    {
        $api = $this->operations->apiForServer($serverId, true);
        $result = $api->tldList();
        if ($result->status !== 'success') { throw new RuntimeException('Enom did not return its list of domain endings. Check the account ID, API token and IP allowlist.'); }
        $tlds = self::tldNames($result);
        if (!$tlds) { throw new RuntimeException('Enom returned an empty list of domain endings.'); }
        $existing = Tld::query()->where('server_id', $serverId)->pluck('tld')->all();
        $added = 0;
        foreach ($tlds as $tld) {
            if (in_array($tld, $existing, true)) { continue; }
            Tld::create(['server_id' => $serverId, 'tld' => $tld, 'enabled' => false, 'pricing_mode' => 'markup_percent', 'markup' => '30.00',
                'sort_order' => self::defaultSort($tld)]);
            $added++;
        }
        $costs = $this->syncCosts($serverId);
        return ['added' => $added, 'total' => count($tlds), 'costs' => $costs];
    }

    /** Read reseller costs for every ending and recompute markup prices. Returns rows updated. */
    public function syncCosts(int $serverId): int
    {
        $api = $this->operations->apiForServer($serverId, true);
        $result = $api->domainPricing();
        if ($result->status !== 'success') { throw new RuntimeException('Enom did not return domain costs. Try again in a few minutes.'); }
        $costs = self::costs($result);
        if (!$costs) { throw new RuntimeException('Enom returned no domain costs.'); }
        $updated = 0;
        DB::transaction(function () use ($serverId, $costs, &$updated) {
            foreach (Tld::query()->where('server_id', $serverId)->lockForUpdate()->get() as $tld) {
                $row = $costs[$tld->tld] ?? null;
                // An ending missing from this response keeps its last known cost and sync time.
                if ($row === null) { continue; }
                $tld->fill([
                    'cost_register' => $row['register'] ?? null,
                    'cost_renew' => $row['renew'] ?? null,
                    'cost_transfer' => $row['transfer'] ?? null,
                    'costs_synced_at' => now(),
                ]);
                self::applyMarkup($tld);
                $tld->save();
                $updated++;
            }
        });
        return $updated;
    }

    /** For markup modes, set each retail price from its cost. Fixed prices are left alone. */
    public static function applyMarkup(Tld $tld): void
    {
        if (!in_array($tld->pricing_mode, ['markup_amount', 'markup_percent'], true)) { return; }
        foreach (Tld::ACTIONS as $action) {
            $cost = $tld->cost($action);
            $tld->{$action.'_price'} = $cost === null ? null : self::retail($cost, $tld->pricing_mode, (string) ($tld->markup ?? '0'));
        }
    }

    /** Retail price from cost. Percent is a percentage of cost. Rounds half-up to the cent. */
    public static function retail(string $cost, string $mode, string $markup): string
    {
        $costMinor = self::minor($cost);
        $markupMinor = self::minor($markup);
        $minor = match ($mode) {
            'markup_amount' => $costMinor + $markupMinor,
            // $markupMinor is hundredths of a percent here: 25.50% => 2550.
            'markup_percent' => intdiv($costMinor * (10000 + $markupMinor) + 5000, 10000),
            default => throw new InvalidArgumentException('Unknown pricing mode.'),
        };
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Turn an ending on. Refuses endings whose registry needs extra registrant details
     * (such as .us nexus) that the order form does not collect, and endings already
     * sold through another Enom server.
     */
    public function enable(Tld $tld): void
    {
        if (!$tld->price('register') || !$tld->price('renew') || !$tld->price('transfer')) {
            throw new InvalidArgumentException('Set registration, renewal and transfer prices before enabling .'.$tld->tld.'.');
        }
        $lock = Cache::lock('enom:enable-tld:'.$tld->tld, 60);
        if (!$lock->get()) { throw new RuntimeException('.'.$tld->tld.' is being changed by someone else. Try again in a minute.'); }
        try {
            $this->enableLocked($tld);
        } finally {
            $lock->release();
        }
    }

    private function enableLocked(Tld $tld): void
    {
        if (Tld::query()->where('tld', $tld->tld)->where('enabled', true)->where('id', '!=', $tld->id)->exists()) {
            throw new InvalidArgumentException('.'.$tld->tld.' is already sold through another Enom server.');
        }
        $api = $this->operations->apiForServer((int) $tld->server_id, true);
        $details = $api->tldInfo($tld->tld);
        $minimum = $details->value('tlds.tld.registration.minimum');
        $maximum = $details->value('tlds.tld.registration.maximum');
        $identified = strtolower((string) $details->value('tlds.tld.tld')) === $tld->tld;
        if ($details->status !== 'success' || !$identified || !is_numeric($minimum)) {
            throw new RuntimeException('Could not read .'.$tld->tld.' registration terms from Enom. Try again.');
        }
        $min = max(1, (int) $minimum);
        $max = is_numeric($maximum) ? min(10, max($min, (int) $maximum)) : 10;
        if ($min > 10) { throw new InvalidArgumentException('.'.$tld->tld.' needs a registration term longer than 10 years.'); }
        $attributes = $api->tldExtendedAttributes($tld->tld);
        if ($attributes->status !== 'success') { throw new RuntimeException('Could not check .'.$tld->tld.' registry requirements with Enom. Try again.'); }
        try { $fields = RegistryFields::normalize($attributes); } catch (InvalidArgumentException) { $fields = null; }
        if ($fields === null || !RegistryFields::supported($fields)) {
            throw new InvalidArgumentException('.'.$tld->tld.' needs registrant details in a form the order page can\'t collect yet.');
        }
        $tld->min_years = $min;
        $tld->max_years = $max;
        $tld->registry_fields = $fields;
        if (Tld::query()->where('tld', $tld->tld)->where('enabled', true)->where('id', '!=', $tld->id)->exists()) {
            throw new InvalidArgumentException('.'.$tld->tld.' is already sold through another Enom server.');
        }
        $tld->enabled = true;
        $tld->save();
        $this->ensureProduct((int) $tld->server_id);
    }

    /** Registry details the order form collects for this ending. */
    public function attributesFor(Tld $tld): array
    {
        return is_array($tld->registry_fields) ? $tld->registry_fields : [];
    }

    /** @return list<array{tld: string, register: string, renew: string, transfer: string, min_years: int, max_years: int}> pre-tax */
    public function publicPrices(): array
    {
        return $this->orderable()->map(fn (Tld $tld) => ['tld' => $tld->tld, 'register' => $tld->price('register'),
            'renew' => $tld->price('renew'), 'transfer' => $tld->price('transfer'),
            'min_years' => min($tld->terms()), 'max_years' => max($tld->terms())])->values()->all();
    }

    /** The hidden native product that domain services belong to. Created on first use. */
    public function ensureProduct(int $serverId): Product
    {
        $existing = Product::query()->where('server_id', $serverId)->get()
            ->first(fn (Product $product) => $product->settings->firstWhere('key', self::PRODUCT_SETTING)?->value === '1');
        if ($existing) { return $existing; }
        return DB::transaction(function () use ($serverId) {
            $category = Category::query()->where('slug', 'domains')->first()
                ?? Category::create(['slug' => 'domains', 'name' => 'Domains', 'description' => 'Domain names.']);
            $slug = Product::query()->where('category_id', $category->id)->where('slug', 'domain-name')->exists() ? 'domain-name-'.$serverId : 'domain-name';
            $product = Product::create(['category_id' => $category->id, 'name' => 'Domain name', 'slug' => $slug, 'hidden' => true,
                'server_id' => $serverId, 'description' => 'Ordered and managed on the Domains page.']);
            $plan = $product->plans()->create(['name' => 'Yearly', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
            $plan->prices()->create(['currency_code' => 'USD', 'price' => '0.00', 'setup_fee' => '0.00']);
            $product->settings()->updateOrCreate(['key' => self::PRODUCT_SETTING], ['value' => '1', 'type' => 'string', 'encrypted' => false]);
            return $product->fresh();
        });
    }

    /** @return list<string> */
    public static function tldNames(RegistrarResult $result): array
    {
        $names = [];
        $walk = function ($node) use (&$walk, &$names) {
            if (!is_array($node)) { return; }
            foreach ($node as $key => $value) {
                if ($key === 'tld' && is_string($value)) {
                    $tld = strtolower(trim($value));
                    if ($tld !== '' && preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)*$/D', $tld) && !in_array($tld, $names, true)) { $names[] = $tld; }
                } elseif (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($result->value('tldlist'));
        return $names;
    }

    /** @return array<string, array{register: ?string, renew: ?string, transfer: ?string}> */
    public static function costs(RegistrarResult $result): array
    {
        $products = $result->value('pricestructure.product');
        if (is_array($products) && !array_is_list($products)) { $products = [$products]; }
        $costs = [];
        foreach (is_array($products) ? $products : [] as $product) {
            if (!is_array($product) || !is_string($product['tld'] ?? null)) { continue; }
            $tld = strtolower(trim($product['tld']));
            if ($tld === '' || !preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)*$/D', $tld)) { continue; }
            $read = fn (string $price, string $enabled) => strcasecmp((string) ($product[$enabled] ?? ''), 'true') === 0
                ? self::money($product[$price] ?? null) : null;
            $costs[$tld] = [
                'register' => $read('resellerpricereg', 'registerenabled'),
                'renew' => $read('resellerpricerenew', 'renewenabled'),
                'transfer' => $read('resellerpricetran', 'transferenabled'),
            ];
        }
        return $costs;
    }

    public static function requiresExtraDetails(RegistrarResult $result): bool
    {
        $attributes = $result->value('attributes.attribute');
        if ($attributes === null || $attributes === '') { return false; }
        if (is_array($attributes) && !array_is_list($attributes)) { $attributes = [$attributes]; }
        foreach (is_array($attributes) ? $attributes : [] as $attribute) {
            if (is_array($attribute) && (string) ($attribute['required'] ?? '0') === '1') { return true; }
        }
        return false;
    }

    private static function money(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,4})?$/D', trim($value))) { return null; }
        // Enom quotes to the cent; anything finer rounds half-up.
        return number_format(round((float) trim($value) + 1e-9, 2), 2, '.', '');
    }

    private static function minor(string $value): int
    {
        if (!preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', trim($value), $match)) { throw new InvalidArgumentException('Invalid amount.'); }
        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    private static function defaultSort(string $tld): int
    {
        $popular = ['com', 'net', 'org', 'io', 'co', 'ai', 'app', 'dev', 'info', 'biz'];
        $index = array_search($tld, $popular, true);
        return $index === false ? 100 : $index + 1;
    }
}
