<?php

namespace Paymenter\Extensions\Servers\Enom\Admin\Pages;

use App\Admin\Resources\ProductResource;
use App\Admin\Resources\ServerResource;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Services\DomainAdministration;
use Paymenter\Extensions\Servers\Enom\Services\DomainPresentation;
use Throwable;

/**
 * Native admin entry for Enom registrations. Read only.
 * It selects safe columns, builds plain arrays for the view and links to the existing Enom staff pages
 * and to the native server and product editors. It never reads settings, credentials, costs or the registrar,
 * and it does not claim domains held at other registrars or names used only by hosting services.
 */
class Domains extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-global-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Domains';

    protected static ?string $navigationLabel = 'Enom';

    protected static ?string $title = 'Enom domains';

    protected static ?string $slug = 'enom-domains';

    protected ?string $subheading = 'Registrations and transfers placed through the Enom sandbox.';

    protected string $view = 'enom::admin-hub';

    public const LATEST_LIMIT = 25;

    public const SERVER_LIMIT = 10;

    public const PRODUCT_LIMIT = 20;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('admin.enom.manage') ?? false;
    }

    protected function getViewData(): array
    {
        // Livewire renders on every request. Refuse before any query.
        abort_unless(static::canAccess(), 403);
        $user = Auth::user();
        $canEditServers = $user->hasPermission('admin.servers.update');
        $canEditProducts = $user->hasPermission('admin.products.update');

        $links = [
            'admin' => route('enom.admin.index'),
            'help' => route('enom.admin.help'),
            'setup' => route('enom.admin.help', 'setup'),
        ];

        $servers = [];
        foreach (Server::query()->select(['id', 'name', 'extension', 'enabled'])->where('extension', 'Enom')
            ->orderBy('id')->limit(self::SERVER_LIMIT)->get() as $server) {
            if ($server->extension !== 'Enom') { continue; }
            $servers[(int) $server->id] = [
                'name' => (string) $server->name,
                'enabled' => (bool) $server->enabled,
                'edit_url' => $canEditServers ? self::editUrl(ServerResource::class, (int) $server->id) : null,
            ];
        }

        $products = [];
        if ($servers) {
            $rows = Product::query()->select(['id', 'name', 'hidden', 'server_id'])
                ->whereIn('server_id', array_keys($servers))->orderBy('id')->limit(self::PRODUCT_LIMIT)->get();
            $prices = self::yearlyUsdPrices($rows->pluck('id')->map(fn ($id) => (int) $id)->all());
            foreach ($rows as $product) {
                // Only products whose server is an actual Enom server get an edit link.
                $bound = isset($servers[(int) $product->server_id]);
                $products[] = [
                    'name' => (string) $product->name,
                    'server' => $servers[(int) $product->server_id]['name'] ?? '',
                    'hidden' => (bool) $product->hidden,
                    'price' => $prices[(int) $product->id] ?? null,
                    'edit_url' => $canEditProducts && $bound ? self::editUrl(ProductResource::class, (int) $product->id) : null,
                ];
            }
        }

        $domains = Domain::query()->select(['id', 'domain', 'status', 'service_id', 'user_id', 'provider_expires_at'])
            ->orderByDesc('id')->limit(self::LATEST_LIMIT)->get();
        // Pending registrations and transfers use their exact-bound request and verified invoice status.
        $progress = DomainAdministration::progress($domains);
        $rows = [];
        foreach ($domains as $domain) {
            $url = route('enom.admin.domain', (int) $domain->id);
            $links['domains'][] = $url;
            $rows[] = [
                'domain' => (string) $domain->domain,
                'status' => $progress[(int) $domain->id]['label'] ?? DomainPresentation::progress($domain->status, null),
                'expires' => DomainPresentation::day($domain->provider_expires_at) ?? 'Not confirmed yet',
                'url' => $url,
            ];
        }

        // The Enom staff pages are ordinary GET pages in the client area layout. Filament links skip SPA loading for them.
        FilamentView::spaUrlExceptions(array_values(array_unique([$links['admin'], $links['help'], $links['setup'], ...($links['domains'] ?? [])])));

        return [
            'links' => $links,
            'servers' => array_values($servers),
            'products' => $products,
            'domains' => $rows,
            'domainCount' => Domain::query()->count(),
            'canEditServers' => $canEditServers,
            'canEditProducts' => $canEditProducts,
        ];
    }

    /** Native edit URL for a record, or null if the panel cannot build it. */
    private static function editUrl(string $resource, int $id): ?string
    {
        try {
            return $resource::getUrl('edit', ['record' => $id]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Before-tax yearly USD plan price per product, from native plan and price rows only.
     * This is the renewal price. Registration and transfer prices are product settings and are not read here.
     *
     * @param  array<int>  $productIds
     * @return array<int, array{amount: string, ready: bool}>
     */
    private static function yearlyUsdPrices(array $productIds): array
    {
        if (!$productIds) { return []; }
        $plans = Plan::query()->select(['id', 'priceable_id', 'type', 'billing_period', 'billing_unit'])
            ->where('priceable_type', (new Product)->getMorphClass())->whereIn('priceable_id', $productIds)
            ->where('type', 'recurring')->where('billing_unit', 'year')->where('billing_period', 1)
            ->orderBy('id')->get();
        $planProduct = [];
        foreach ($plans as $plan) {
            $productId = (int) $plan->priceable_id;
            if (!in_array($productId, $planProduct, true)) { $planProduct[(int) $plan->id] = $productId; }
        }
        if (!$planProduct) { return []; }
        $result = [];
        foreach (Price::query()->select(['plan_id', 'currency_code', 'price', 'setup_fee'])
            ->whereIn('plan_id', array_keys($planProduct))->where('currency_code', 'USD')->get() as $price) {
            $productId = $planProduct[(int) $price->plan_id] ?? null;
            if ($productId === null || isset($result[$productId]) || !is_numeric($price->price)) { continue; }
            $result[$productId] = [
                'amount' => number_format((float) $price->price, 2, '.', ''),
                // Mirrors what a quote needs from this row: a positive price and no setup fee.
                'ready' => (float) $price->price > 0 && (float) ($price->setup_fee ?? 0) === 0.0,
            ];
        }
        return $result;
    }
}
