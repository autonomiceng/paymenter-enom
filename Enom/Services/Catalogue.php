<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Helpers\NotificationHelper;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Enom\Api\DomainName;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use RuntimeException;
use Throwable;

/** Extension-owned native products and exact-term prices. */
class Catalogue
{
    public const PRODUCT_SETTING = 'enom_catalogue';

    public function __construct(private DomainOperations $operations) {}

    public function orderable(): Collection
    {
        return Tld::query()->where('enabled', true)->whereIn('server_id', Server::where('extension', 'Enom')->pluck('id'))
            ->orderBy('sort_order')->orderBy('tld')->get()->filter(fn (Tld $tld) => $tld->orderable())->values();
    }

    public function resolve(string $input): array
    {
        $tlds = $this->orderable();
        if ($tlds->isEmpty()) { throw new RuntimeException('Domain ordering is not available yet.'); }
        $parts = DomainName::parse($input, $tlds->pluck('tld')->unique()->all());
        if ($tlds->where('tld', $parts['tld'])->count() > 1) { throw new RuntimeException('This ending has more than one enabled seller.'); }
        if (str_starts_with($parts['sld'], 'xn--')) { throw new InvalidArgumentException('International domain names are not available.'); }
        return ['tld' => $tlds->firstWhere('tld', $parts['tld']), 'domain' => $parts['domain'], 'sld' => $parts['sld']];
    }

    public function suffixes(int $serverId): array
    {
        return Tld::where('server_id', $serverId)->pluck('tld')->merge(Domain::where('server_id', $serverId)->pluck('tld'))
            ->map(fn ($tld) => strtolower((string) $tld))->unique()->values()->all();
    }

    public function import(int $serverId): array
    {
        $result = $this->operations->apiForServer($serverId, true)->tldList();
        $names = $result->status === 'success' ? self::tldNames($result) : [];
        if (!$names) { throw new RuntimeException('Enom did not return its list of domain endings.'); }
        $added = 0;
        foreach ($names as $name) {
            $tld = Tld::firstOrCreate(['server_id' => $serverId, 'tld' => $name], ['sort_order' => self::defaultSort($name)]);
            $added += $tld->wasRecentlyCreated ? 1 : 0;
        }
        return ['added' => $added, 'total' => count($names), 'costs' => $this->syncCosts($serverId)];
    }

    public function syncCosts(int $serverId): int
    {
        return $this->sync($serverId)['tlds'];
    }

    /**
     * Synchronous cost sync with a summary for staff: endings read, endings whose costs changed,
     * endings on sale with a missing Enom price, blocked new sales, and renewals still billed below cost.
     */
    public function sync(int $serverId): array
    {
        $api = $this->operations->apiForServer($serverId, true);
        $summary = ['tlds' => 0, 'changed' => [], 'missing' => [], 'held' => [], 'renewals_below_cost' => [], 'failed' => []];
        foreach (Tld::where('server_id', $serverId)->orderBy('id')->get() as $tld) {
            $result = $this->syncTld($tld, $api);
            if ($result === null) {
                $summary['failed'][] = '.'.$tld->tld;
                continue;
            }
            $summary['tlds']++;
            $ending = '.'.$tld->tld;
            if ($result['changed']) { $summary['changed'][] = $ending; }
            if ($tld->price('renew') !== null && str_starts_with((string) ($tld->sale_holds['renew'] ?? ''), 'Price is below cost.')) {
                $summary['renewals_below_cost'][] = $ending;
            }
            if (!$tld->enabled) { continue; }
            if ($result['missing']) { $summary['missing'][] = $ending; }
            $holds = array_intersect_key($tld->sale_holds ?? [], ['register' => true, 'transfer' => true]);
            if (collect($holds)->contains(fn ($hold) => str_starts_with((string) $hold, 'Price is below cost.'))) { $summary['held'][] = $ending; }
        }
        return $summary;
    }

    /**
     * Sync one ending, retaining its last known costs when provider reads fail.
     * @return array{changed: bool, missing: bool}|null
     */
    public function syncTld(Tld $tld, ?EnomApi $api = null): ?array
    {
        try {
            $api ??= $this->operations->apiForServer((int) $tld->server_id, true);
            $terms = [];
            // Provider reads finish before taking any catalogue row lock.
            for ($years = max(1, (int) $tld->min_years); $years <= min(10, (int) $tld->max_years); $years++) {
                $terms[$years] = self::productCost($api->productPrice($tld->tld, 'register', $years));
            }
            $renew = self::productCost($api->productPrice($tld->tld, 'renew'));
            $transfer = app(TransferOrders::class)->allowed($tld) ? self::productCost($api->productPrice($tld->tld, 'transfer')) : null;
        } catch (Throwable $exception) {
            report($exception);
            return null;
        }
        $before = [$tld->cost_register_terms ?? [], $tld->cost_renew, $tld->cost_transfer];
        $this->publishChanges($tld, ['cost_register_terms' => $terms, 'cost_renew' => $renew,
            'cost_transfer' => $transfer, 'costs_synced_at' => now()]);
        $tld->refresh();
        return ['changed' => self::costKey($before) !== self::costKey([$terms, $renew, $transfer]),
            'missing' => in_array(null, $terms, true) || $renew === null];
    }

    /** Staff notification for a sync summary: [title, body, warn]. */
    public static function syncMessage(array $summary): array
    {
        $list = function (array $endings): string {
            $shown = array_slice($endings, 0, 3);
            return implode(', ', $shown).(count($endings) > 3 ? ' and '.(count($endings) - 3).' more' : '');
        };
        $count = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
        $body = [($summary['changed'] ? $count(count($summary['changed']), 'TLD', 'TLDs').' had cost changes' : 'No costs changed').'.'];
        $renewals = $summary['renewals_below_cost'] ?? [];
        if ($summary['held']) { $body[] = 'Below cost, partly or fully off sale: '.$list($summary['held']).'.'; }
        if ($renewals) { $body[] = 'Renewals below cost (still billed): '.$list($renewals).'.'; }
        if (!$summary['held'] && !$renewals) { $body[] = 'Nothing on sale is below cost.'; }
        if ($summary['missing']) { $body[] = 'No Enom price for some terms of '.$list($summary['missing']).'. Those terms aren’t offered until a sync gets a price.'; }
        $failed = $summary['failed'] ?? [];
        if ($failed) { $body[] = "Couldn't read Enom prices for ".$list($failed).'; kept the last known costs.'; }
        return ['Costs synced for '.$count((int) $summary['tlds'], 'TLD', 'TLDs').'.', implode(' ', $body), (bool) ($summary['held'] || $renewals || $summary['missing'] || $failed)];
    }

    private static function costKey(array $costs): string
    {
        [$terms, $renew, $transfer] = $costs;
        $normal = fn ($value) => $value === null ? null : PricingRule::wholesale((string) $value);
        return json_encode([array_map($normal, (array) $terms), $normal($renew), $normal($transfer)]);
    }

    private static function productCost(RegistrarResult $result): ?string
    {
        if ($result->code === 'product_price_unavailable') { return null; }
        if ($result->status !== 'success' || ($cost = PricingRule::wholesale($result->value('productprice.price'))) === null) {
            throw new RuntimeException('Could not read prices from Enom.');
        }
        return $cost;
    }

    public function rule(Tld $tld): array
    {
        return $tld->use_default_rule ? CatalogueRuntime::defaultRule((int) $tld->server_id)
            : PricingRule::validate(['mode' => $tld->pricing_mode, 'markup' => $tld->markup ?? '0', 'round_up' => $tld->round_up,
                'fixed' => ['register' => $tld->fixed_register, 'renew' => $tld->fixed_renew, 'transfer' => $tld->fixed_transfer]]);
    }

    public static function retail(string $cost, string $mode, string $markup, string $roundUp = 'none'): string
    {
        return PricingRule::price($cost, ['mode' => $mode, 'markup' => $markup, 'round_up' => $roundUp, 'fixed' => []], 'register');
    }

    public function productFor(Tld $tld, string $action): ?Product
    {
        if (!in_array($action, ['register', 'transfer'], true)) { throw new InvalidArgumentException('Unknown catalogue action.'); }
        $products = $this->products($tld)->get()->filter(function (Product $product) use ($action) {
            return $this->mapping($product)['action'] === $action;
        });
        if ($products->count() > 1) { throw new RuntimeException('Duplicate domain product mapping.'); }
        return $products->first();
    }

    public function mapping(Product $product, ?Plan $plan = null): ?array
    {
        $settings = $product->settings()->whereIn('key', [self::PRODUCT_SETTING, 'enom_tld_id', 'enom_action'])->get();
        $server = $product->server()->first();
        if ($settings->isEmpty() && $server?->extension !== 'Enom') { return null; }
        $value = fn (string $key) => $settings->where('key', $key)->count() === 1 ? $settings->firstWhere('key', $key)?->value : null;
        $id = $value('enom_tld_id');
        $action = $value('enom_action');
        $tld = is_string($id) && ctype_digit($id) ? Tld::find((int) $id) : null;
        if ($settings->count() !== 3 || $settings->contains(fn ($setting) => $setting->type !== 'string' || $setting->encrypted)
            || $value(self::PRODUCT_SETTING) !== '1' || !$tld || $server?->extension !== 'Enom'
            || (int) $tld->server_id !== (int) $product->server_id || !in_array($action, ['register', 'transfer'], true)) {
            throw new RuntimeException('Invalid domain product mapping.');
        }
        if ($plan && ($plan->priceable_type !== $product->getMorphClass() || (int) $plan->priceable_id !== (int) $product->id
            || $plan->type !== 'recurring' || $plan->billing_unit !== 'year' || $plan->billing_period < 1 || $plan->billing_period > 10
            || $action === 'transfer' && $plan->billing_period !== 1)) { throw new RuntimeException('Invalid domain plan mapping.'); }
        return ['tld' => $tld, 'action' => $action, 'years' => $plan ? (int) $plan->billing_period : null];
    }

    public function billingPlan(Product $product): Plan
    {
        $mapping = $this->mapping($product);
        if (!$mapping) { throw new InvalidArgumentException('This is not a domain product.'); }
        $plan = $product->plans()->where('billing_period', 1)->where('billing_unit', 'year')->where('type', 'recurring')->first();
        if (!$plan) {
            $this->publish($mapping['tld']);
            $plan = $product->plans()->where('billing_period', 1)->where('billing_unit', 'year')->where('type', 'recurring')->sole();
        }
        $this->mapping($product, $plan);
        return $plan;
    }

    private function products(Tld $tld)
    {
        return Product::query()->whereHas('settings', fn ($query) => $query->where('key', 'enom_tld_id')->where('value', (string) $tld->id))->orderBy('id');
    }

    public function publish(Tld $tld): void
    {
        $changes = array_intersect_key($tld->getDirty(), array_flip(['enabled', 'register_enabled', 'renew_enabled', 'transfer_enabled',
            'use_default_rule', 'pricing_mode', 'markup', 'round_up', 'fixed_register', 'fixed_renew', 'fixed_transfer',
            'min_years', 'max_years', 'registry_fields', 'sort_order']));
        // Eloquent dirty JSON values are encoded; use the cast values when filling the locked row.
        foreach ($changes as $key => $_) { $changes[$key] = $tld->$key; }
        $this->publishChanges($tld, $changes);
        $tld->refresh();
    }

    private function publishChanges(Tld $tld, array $changes): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                DB::transaction(function () use ($tld, $changes) {
                    $products = $this->products($tld)->lockForUpdate()->get();
                    // Native checkout locks products first; publication follows the same order.
                    $rows = Tld::where('tld', $tld->tld)->orderBy('id')->lockForUpdate()->get();
                    $current = $rows->firstWhere('id', $tld->id);
                    if (!$current || (int) $current->server_id !== (int) $tld->server_id) { throw new RuntimeException('This ending changed. Reload the page.'); }
                    if ($this->products($current)->pluck('id')->all() !== $products->pluck('id')->all()) {
                        throw new RuntimeException('Catalogue products changed during publication.');
                    }
                    $current->fill($changes);
                    if ($current->enabled && $rows->contains(fn (Tld $row) => $row->id !== $current->id && $row->enabled)) {
                        throw new InvalidArgumentException('This ending is already sold through another Enom server.');
                    }
                    if ($current->server?->extension !== 'Enom') { throw new InvalidArgumentException('Choose an Enom server.'); }
                    $this->publishLocked($current, $products);
                }, 3);
                return;
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'Catalogue products changed during publication.' || $attempt === 2) { throw $exception; }
            }
        }
    }

    private function publishLocked(Tld $tld, Collection $products): void
    {
        $oldHolds = $tld->sale_holds ?? [];
        $holds = [];
        $rule = $this->rule($tld);
        $prices = ['register' => [], 'transfer' => []];
        $belowTerms = [];
        foreach (Tld::ACTIONS as $action) {
            $terms = $action === 'register' ? range(max(1, (int) $tld->min_years), min(10, max((int) $tld->min_years, (int) $tld->max_years))) : [1];
            $available = [];
            $below = [];
            foreach ($terms as $years) {
                $cost = $tld->cost($action, $years);
                if ($cost === null) { continue; }
                $price = PricingRule::price($cost, $rule, $action);
                if (!PricingRule::covers($price, $cost)) {
                    $below[] = $years;
                    $holds[$action] = self::belowCost($action, $below);
                    if ($action !== 'renew') { continue; }
                }
                if (PricingRule::units($price, 2) === 0) { $holds[$action] = 'No positive price is available.'; continue; }
                $available[$years] = PricingRule::total($price, $years);
            }
            if ($below) { $belowTerms[$action] = $below; }
            if ($action === 'transfer' && isset($holds[$action])) { $tld->transfer_enabled = false; }
            if (!$available && !isset($holds[$action])) { $holds[$action] = 'No current price is available.'; }
            if ($action === 'renew') {
                // Retain the billing price across missing costs and action toggles; renew_enabled gates billing.
                $tld->renew_price = $available[1] ?? $tld->renew_price;
            }
            else { $prices[$action] = $available; }
        }
        if ($tld->tld === 'de' || str_ends_with($tld->tld, '.de')) { $holds['register'] = 'Registration requires nameservers at purchase.'; $prices['register'] = []; }
        $transferAllowed = app(TransferOrders::class)->allowed($tld);
        if (!$transferAllowed) {
            $holds['transfer'] = TransferOrders::supported($tld) ? 'Not on the transfer list. Turn on Transfer to add it.' : 'Transfers are not supported for this ending.';
            $prices['transfer'] = [];
        }
        if (!$tld->renew_enabled || $tld->renew_price === null) {
            foreach (['register', 'transfer'] as $action) { $holds[$action] ??= 'An annual renewal price is required.'; $prices[$action] = []; }
        }
        $tld->sale_holds = $holds;
        $tld->save();
        foreach (['register', 'transfer'] as $action) {
            $matching = $products->filter(fn (Product $product) => $this->mapping($product)['action'] === $action);
            if ($matching->count() > 1) { throw new RuntimeException('Duplicate domain product mapping.'); }
            $product = $matching->first();
            $supported = $action === 'transfer' ? $transferAllowed : $tld->tld !== 'de' && !str_ends_with($tld->tld, '.de');
            if (!$product && $tld->enabled && $supported) { $product = $this->createProduct($tld, $action); }
            if (!$product) { continue; }
            $this->mapping($product);
            $category = Category::firstOrCreate(['slug' => 'domains'], ['name' => 'Domains', 'description' => 'Domain names.']);
            $slug = self::slug($tld, $action);
            if (Product::where('slug', $slug)->whereKeyNot($product->id)->exists()) { throw new RuntimeException('The product slug '.$slug.' is already in use.'); }
            $product->fill(['category_id' => $category->id, 'slug' => $slug, 'hidden' => true, 'allow_quantity' => 'separated', 'stock' => null, 'per_user_limit' => 0,
                'name' => '.'.$tld->tld.' '.($action === 'register' ? 'registration' : 'transfer'),
                'description' => self::description($tld, $action, array_keys($prices[$action]))])->save();
            DB::table('config_option_products')->where('product_id', $product->id)->delete();
            $product->upgrades()->detach();
            DB::table('product_upgrades')->where('upgrade_id', $product->id)->delete();
            $sale = $tld->enabled && $tld->{$action.'_enabled'} ? $prices[$action] : [];
            $this->publishPlans($product, $action, $tld, $sale);
        }
        // Staff choose transfer availability, so only price and registry holds alert.
        $newHolds = array_diff_key(array_diff_assoc($holds, $oldHolds), $transferAllowed ? [] : ['transfer' => true]);
        if ($newHolds && $tld->enabled) { DB::afterCommit(fn () => $this->alert($tld, $newHolds, $belowTerms, array_keys($prices['register']))); }
    }

    /** Staff hold text; the 'Price is below cost.' prefix is what the TLD filter and sync summary match. */
    private static function belowCost(string $action, array $years): string
    {
        return 'Price is below cost. '.match ($action) {
            'renew' => 'Existing domains still renew.',
            'transfer' => 'Transfers are off.',
            default => 'Not offered: '.self::terms($years).'.',
        };
    }

    /** Customer text under the checkout heading; rewritten on every publish so the renewal price stays current. */
    private static function description(Tld $tld, string $action, array $years): string
    {
        $renewPrice = $tld->price('renew');
        $renew = $renewPrice !== null ? ' It renews at $'.$renewPrice.'/yr' : '';
        if ($action === 'transfer') {
            return 'Move a .'.$tld->tld.' domain you own to us. The transfer adds 1 year to its expiry. After checkout, you’ll enter '
                .'the transfer code from your current registrar. If the transfer is refused, you get the full amount back as account credit.'
                .($renew ? $renew.'.' : '');
        }
        if (!$years) { return 'Register a .'.$tld->tld.' domain.'.($renew ? $renew.'.' : ''); }
        [$min, $max] = [min($years), max($years)];
        $span = $min === $max ? $min.' '.($min === 1 ? 'year' : 'years') : $min.' to '.$max.' years';
        return 'Register a .'.$tld->tld.' domain for '.$span.'.'.($renew ? $renew.($min === $max ? '.' : ' after the first term.') : '');
    }

    private static function slug(Tld $tld, string $action): string
    {
        $base = str_replace('.', '-', $tld->tld).'-'.($action === 'register' ? 'registration' : 'transfer');
        // The first published seller retains the short URLs, even while disabled.
        // Retained products establish that identity independently of action switches or edited slugs.
        $first = Product::query()->whereHas('settings', fn ($query) => $query->where('key', self::PRODUCT_SETTING)->where('value', '1'))
            ->whereHas('settings', fn ($query) => $query->where('key', 'enom_tld_id')
                ->whereIn('value', Tld::where('tld', $tld->tld)->pluck('id')->map(fn ($id) => (string) $id)))
            ->orderBy('id')->first();
        return !$first || (int) $first->server_id === (int) $tld->server_id ? $base : $base.'-s'.$tld->server_id;
    }

    private function createProduct(Tld $tld, string $action): Product
    {
        $category = Category::firstOrCreate(['slug' => 'domains'], ['name' => 'Domains', 'description' => 'Domain names.']);
        $slug = self::slug($tld, $action);
        if (Product::where('slug', $slug)->exists()) { throw new RuntimeException('The product slug '.$slug.' is already in use.'); }
        $product = Product::create(['category_id' => $category->id, 'server_id' => $tld->server_id, 'name' => '.'.$tld->tld.' '.$action,
            'slug' => $slug, 'hidden' => true, 'allow_quantity' => 'separated', 'stock' => null, 'per_user_limit' => 0]);
        foreach ([self::PRODUCT_SETTING => '1', 'enom_tld_id' => (string) $tld->id, 'enom_action' => $action] as $key => $value) {
            $product->settings()->create(['key' => $key, 'value' => $value, 'type' => 'string', 'encrypted' => false]);
        }
        return $product;
    }

    private function publishPlans(Product $product, string $action, Tld $tld, array $prices): void
    {
        // Before the first exact-term sync, unknown actions keep native prices but still need billing identities.
        // Sale reads hold these prices; a successful sync supplies costs, including explicit unavailability.
        $preservePrices = $tld->cost_register_terms === null && $tld->cost($action) === null;
        $years = $action === 'register' ? range(max(1, (int) $tld->min_years), min(10, max((int) $tld->min_years, (int) $tld->max_years))) : [1];
        $years = array_unique([1, ...$years]);
        foreach ($years as $term) {
            $plans = $product->plans()->where('billing_unit', 'year')->where('billing_period', $term)->get();
            if ($plans->count() > 1) { throw new RuntimeException('Duplicate domain billing plan.'); }
            $plan = $plans->first() ?? $product->plans()->create(['name' => $term.' year'.($term === 1 ? '' : 's'), 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => $term]);
            $plan->fill(['type' => 'recurring', 'name' => $term === 1 && $action === 'register' && $tld->min_years > 1 ? 'Managed annual renewal' : $term.' year'.($term === 1 ? '' : 's'),
                'sort' => isset($prices[$term]) ? $term : 100 + $term])->save();
            if ($preservePrices) { continue; }
            if (isset($prices[$term])) {
                $plan->prices()->where('currency_code', '!=', 'USD')->delete();
                $plan->prices()->updateOrCreate(['currency_code' => 'USD'], ['price' => $prices[$term], 'setup_fee' => '0.00']);
            } else { $plan->prices()->delete(); }
        }
        if ($preservePrices) { return; }
        // Keep historical identities but remove every price that is no longer offered.
        foreach ($product->plans()->get() as $plan) {
            if (!isset($prices[$plan->billing_period]) || $plan->type !== 'recurring' || $plan->billing_unit !== 'year') {
                $plan->prices()->delete();
                $plan->update(['sort' => 100 + (int) $plan->billing_period]);
            }
        }
    }

    private function alert(Tld $tld, array $holds, array $belowTerms, array $offered): void
    {
        $ending = $tld->tld;
        $url = self::pricingUrl($tld);
        $messages = [];
        foreach ($holds as $action => $reason) {
            $messages[] = isset($belowTerms[$action]) ? self::belowCostAlert($ending, $action, $belowTerms[$action], $offered)
                : ['Review .'.$ending.' pricing', ['register' => 'Registration', 'renew' => 'Renewal', 'transfer' => 'Transfer'][$action].': '.$reason];
        }
        foreach (User::whereHas('role')->get() as $user) {
            if (!$user->hasPermission('admin.enom.manage') || !$user->hasPermission('admin.enom.costs')) { continue; }
            foreach ($messages as [$title, $body]) {
                try {
                    \App\Models\Notification::create(['user_id' => $user->id, 'title' => $title, 'body' => $body, 'url' => $url, 'show_in_app' => true, 'show_as_push' => true]);
                    NotificationHelper::sendSystemEmailNotification($title, $body.($url ? '<p><a href="'.e($url).'">Review pricing</a></p>' : ''), email: $user->email);
                } catch (Throwable $exception) { report($exception); }
            }
        }
    }

    /** The TLD list with this ending's pricing open, for "Review pricing" in alerts. */
    public static function pricingUrl(Tld $tld): ?string
    {
        try {
            return TldResource::getUrl('index', ['tableAction' => 'edit', 'tableActionRecord' => $tld->getKey()], panel: 'admin');
        } catch (Throwable) { return null; }
    }

    /** Margin-guard alert text: [title, body]. */
    public static function belowCostAlert(string $ending, string $action, array $years, array $offered): array
    {
        $raise = 'Customers can’t order it until you raise the price or switch to a cost-based rule. Domains already registered still renew.';
        return match (true) {
            $action === 'renew' => ['.'.$ending.' renews below cost',
                'Enom’s renewal cost is above your renewal price. Renewals continue so customers don’t lose domains. Raise the renewal price.'],
            $action === 'transfer' => ['Transfer for .'.$ending.' turned off: price below cost', 'Enom’s transfer cost is above your price. '.$raise],
            $offered === [] => ['Registration for .'.$ending.' turned off: price below cost', 'Enom’s registration cost is above your price for every term. '.$raise],
            default => ['.'.$ending.' registration for '.self::terms($years).' removed: price below cost',
                'Enom’s cost for those terms is above your price. '.self::terms($offered).' '.(count($offered) === 1 ? 'is' : 'are').' still on sale.'],
        };
    }

    /** "1 year", "7–10 years", "1, 3 years". */
    private static function terms(array $years): string
    {
        sort($years);
        $run = count($years) > 1 && end($years) - reset($years) === count($years) - 1;
        $text = $run ? reset($years).'–'.end($years) : implode(', ', $years);
        return $text.' '.($years === [1] ? 'year' : 'years');
    }

    public function enable(Tld $tld): void
    {
        if ($tld->tld === 'de' || str_ends_with($tld->tld, '.de')) { throw new InvalidArgumentException('This ending requires nameservers at purchase.'); }
        if (Tld::where('tld', $tld->tld)->where('enabled', true)->whereKeyNot($tld->id)->exists()) {
            throw new InvalidArgumentException('This ending is already sold through another Enom server.');
        }
        $api = $this->operations->apiForServer((int) $tld->server_id, true);
        $details = $api->tldInfo($tld->tld);
        $min = $details->value('tlds.tld.registration.minimum');
        $max = $details->value('tlds.tld.registration.maximum');
        if ($details->status !== 'success' || strtolower((string) $details->value('tlds.tld.tld')) !== $tld->tld
            || !is_string($min) || !preg_match('/^(?:[1-9]|10)$/D', $min) || !is_string($max) || !preg_match('/^(?:[1-9]|10)$/D', $max) || (int) $max < (int) $min) {
            throw new RuntimeException('Could not read registration terms from Enom.');
        }
        $attributes = $api->tldExtendedAttributes($tld->tld);
        if ($attributes->status !== 'success') { throw new RuntimeException('Could not check registry requirements with Enom.'); }
        $fields = RegistryFields::normalize($attributes);
        if (!RegistryFields::supported($fields)) { throw new InvalidArgumentException('This ending needs registry details the checkout cannot collect.'); }
        $tld->fill(['min_years' => (int) $min, 'max_years' => (int) $max, 'registry_fields' => $fields, 'enabled' => true]);
        $this->publish($tld);
    }

    public function attributesFor(Tld $tld): array { return $tld->registry_fields ?? []; }

    /** An optional collection must already have passed orderable(). */
    public function publicPrices(?Collection $endings = null): array
    {
        return ($endings ?? $this->orderable())->map(function (Tld $tld) {
            $terms = $tld->terms();
            return ['tld' => $tld->tld, 'register' => $tld->price('register'), 'renew' => $tld->price('renew'),
                'transfer' => $tld->price('transfer'), 'min_years' => $terms ? min($terms) : null,
                'max_years' => $terms ? max($terms) : null];
        })->all();
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

    private static function defaultSort(string $tld): int
    {
        $popular = ['com', 'net', 'org', 'io', 'co', 'ai', 'app', 'dev', 'info', 'biz'];
        $index = array_search($tld, $popular, true);
        return $index === false ? 100 : $index + 1;
    }
}
