<?php
// Native Filament discovery/access/route generation with in-memory synthetic rows. No installed app.
declare(strict_types=1);
require_once dirname(__DIR__).'/paymenter-test-app.php';

$repo = dirname(__DIR__, 2);
$release = paymenterTestApp();
$loader = require $release.'/vendor/autoload.php';
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Enom\\', $repo.'/Enom/', true);
$app = new Illuminate\Foundation\Application($release);
$cache = sys_get_temp_dir().'/enom-admin-hub-'.bin2hex(random_bytes(8));
mkdir($cache, 0700); mkdir($cache.'/filament-panels', 0700);
mkdir($cache.'/filament-panels/components', 0700); mkdir($cache.'/filament-panels/components/page', 0700);
// Replace only the enclosing panel chrome. The candidate hub and native Blade remain real.
file_put_contents($cache.'/filament-panels/components/page/index.blade.php', '<main>{{ $slot }}</main>');
$app->instance('config', new Illuminate\Config\Repository(['app' => ['namespace' => 'App\\'],
    'view' => ['paths' => [$cache, $release.'/themes/default/views'], 'compiled' => $cache],
    'database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]]]));
$events = new Illuminate\Events\Dispatcher($app);
$app->instance('events', $events);
$app->instance('files', new Illuminate\Filesystem\Filesystem);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->register(Illuminate\View\ViewServiceProvider::class);
// Native Filament Blade macros/colours. Skip only Livewire hook installation, not the rendered controls.
$app->instance('livewire', new class { public function componentHook($hook): void {} });
$support = $app->register(Filament\Support\SupportServiceProvider::class);
$support->boot();
$app['view']->addNamespace('enom', $repo.'/Enom/resources/views');
$app['view']->addNamespace('filament-panels', [$cache.'/filament-panels', $release.'/vendor/filament/filament/resources/views']);
$app['view']->addNamespace('filament', $release.'/vendor/filament/support/resources/views');
$capsule = new Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setEventDispatcher($events); $capsule->setAsGlobal(); $capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$schema = $capsule->schema();
foreach (['extensions', 'products'] as $table) {
    $schema->create($table, function ($row) use ($table): void {
        $row->integer('id')->primary(); $row->string('name'); $row->boolean('enabled')->default(true);
        $row->string('extension')->nullable(); $row->integer('server_id')->nullable(); $row->boolean('hidden')->default(true);
        $row->string('type')->nullable(); $row->timestamp('deleted_at')->nullable();
        $row->string('private_marker')->nullable();
    });
}
$schema->create('enom_domains', function ($row): void {
    $row->integer('id')->primary(); $row->string('domain'); $row->string('status');
    $row->integer('service_id'); $row->integer('user_id'); $row->integer('server_id');
    $row->date('provider_expires_at')->nullable(); $row->string('snapshot')->nullable();
    $row->string('sponsorship')->nullable(); $row->string('observation')->nullable();
});
$schema->create('plans', function ($row): void {
    $row->integer('id')->primary(); $row->integer('priceable_id'); $row->string('priceable_type');
    $row->string('type'); $row->integer('billing_period'); $row->string('billing_unit');
});
$schema->create('prices', function ($row): void {
    $row->integer('id')->primary(); $row->integer('plan_id'); $row->string('currency_code');
    $row->decimal('price', 10, 2); $row->decimal('setup_fee', 10, 2);
});
$schema->create('invoices', function ($row): void {
    $row->integer('id')->primary(); $row->integer('user_id'); $row->string('status'); $row->date('due_at')->nullable();
});
$schema->create('invoice_items', function ($row): void {
    $row->integer('id')->primary(); $row->integer('invoice_id'); $row->string('reference_type')->nullable();
    $row->integer('reference_id')->nullable();
});
$schema->create('enom_operations', function ($row): void {
    foreach (['id', 'domain_id', 'service_id', 'invoice_id', 'invoice_item_id'] as $field) $row->integer($field)->nullable();
    foreach (['action', 'state', 'safe_code', 'cost', 'policy', 'payload'] as $field) $row->string($field)->nullable();
});
$capsule->table('extensions')->insert([
    ['id' => 1001, 'name' => 'Enom TEST', 'extension' => 'Enom', 'type' => 'server', 'private_marker' => 'PRIVATE-SERVER-SENTINEL'],
    ['id' => 1002, 'name' => 'Other provider', 'extension' => 'Plesk', 'type' => 'server', 'private_marker' => 'PRIVATE-OTHER-SENTINEL'],
]);
$capsule->table('products')->insert(['id' => 4001, 'name' => 'Current .com prices', 'server_id' => 1001, 'private_marker' => 'PRIVATE-PRODUCT-SENTINEL']);
$capsule->table('plans')->insert(['id' => 5001, 'priceable_id' => 4001, 'priceable_type' => App\Models\Product::class,
    'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
$capsule->table('prices')->insert(['id' => 5001, 'plan_id' => 5001, 'currency_code' => 'USD', 'price' => '15.00', 'setup_fee' => '0.00']);
$capsule->table('enom_domains')->insert(['id' => 2001, 'domain' => 'synthetic-customer.com', 'status' => 'registration_pending',
    'service_id' => 6001, 'user_id' => 9001, 'server_id' => 1001, 'snapshot' => 'PRIVATE-DOMAIN-SENTINEL',
    'sponsorship' => 'PRIVATE-SPONSOR-SENTINEL', 'observation' => 'PRIVATE-OBSERVATION-SENTINEL']);
$capsule->table('enom_operations')->insert(['id' => 3001, 'domain_id' => 2001, 'service_id' => 6001, 'invoice_id' => 7001,
    'invoice_item_id' => 8001, 'action' => 'register', 'state' => 'awaiting_payment', 'cost' => 'PRIVATE-COST-SENTINEL',
    'policy' => 'PRIVATE-POLICY-SENTINEL', 'payload' => 'PRIVATE-PAYLOAD-SENTINEL']);
$capsule->table('invoices')->insert(['id' => 7001, 'user_id' => 9001, 'status' => 'pending']);
$capsule->table('invoice_items')->insert(['id' => 8001, 'invoice_id' => 7001,
    'reference_type' => App\Models\Service::class, 'reference_id' => 6001]);
$router = new Illuminate\Routing\Router($events, $app);
$app->instance('router', $router);
$request = Illuminate\Http\Request::create('https://enom-hub.invalid/admin/enom-domains');
$app->instance('request', $request);
$url = new Illuminate\Routing\UrlGenerator($router->getRoutes(), $request);
$app->instance('url', $url);
$auth = new class {
    public ?App\Models\User $current = null;
    public function user(): ?App\Models\User { return $this->current; }
    public function check(): bool { return $this->current !== null; }
};
Illuminate\Support\Facades\Auth::swap($auth);
Filament\Support\Facades\FilamentView::swap(new Filament\Support\View\ViewManager);
Filament\Support\Facades\FilamentView::spa();
$app['config']->set('app.url', 'https://enom-hub.invalid');
$manager = new Filament\FilamentManager;
Filament\Facades\Filament::swap($manager);
$registry = new Filament\PanelRegistry;
$app->instance(Filament\PanelRegistry::class, $registry);
$panel = Filament\Panel::make()->id('admin')->path('admin')->default();
$registry->panels['admin'] = $panel;
$manager->setCurrentPanel($panel);
$class = Paymenter\Extensions\Servers\Enom\Admin\Pages\Domains::class;
$panel->discoverPages($repo.'/Enom/Admin/Pages', 'Paymenter\\Extensions\\Servers\\Enom\\Admin\\Pages');
$panel->resources([App\Admin\Resources\ServerResource::class, App\Admin\Resources\ProductResource::class]);
$router->group(['prefix' => 'admin', 'as' => 'filament.admin.', 'middleware' => ['auth']], function () use ($class, $panel): void {
    $class::registerRoutes($panel);
    App\Admin\Resources\ServerResource::registerRoutes($panel);
    App\Admin\Resources\ProductResource::registerRoutes($panel);
});
foreach (['enom.admin.index' => '/admin/enom', 'enom.admin.help' => '/admin/enom/help/{topic?}',
    'enom.admin.domain' => '/admin/enom/domains/{domain}'] as $name => $path) {
    $router->get($path, fn () => null)->name($name);
}
$router->getRoutes()->refreshNameLookups();
$checks = 0;
function checkHub(bool $ok, string $message): void {
    global $checks; if (!$ok) throw new RuntimeException($message); $checks++;
}
function deniedHub(callable $call, string $message): void {
    try { $call(); } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        checkHub($error->getStatusCode() === 403, $message); return;
    }
    throw new RuntimeException($message);
}
function safeHubQueries(array $queries): void {
    foreach ($queries as $query) {
        $sql = strtolower($query['query']);
        checkHub(!preg_match('/^select\s+\*|\b(settings|snapshot|sponsorship|observation|cost|policy|payload|private_marker|uid|token)\b/', $sql),
            'Hub queried a full/private model or credential relation: '.$sql);
        checkHub(preg_match('/^select\b/', $sql) === 1, 'Viewing hub caused a database write.');
    }
}
try {
    checkHub(in_array($class, $panel->getPages(), true), 'Native extension discovery did not register Domains.');
    checkHub($class::getUrl(panel: 'admin') === 'https://enom-hub.invalid/admin/enom-domains', 'Provider-specific hub URL collides with legacy review or reserves generic domains.');
    checkHub($class::getNavigationLabel() === 'Enom' && $class::getNavigationGroup() === 'Domains', 'Provider entry or Domains navigation group missing.');
    $page = new $class;
    checkHub((string) $page->getTitle() === 'Enom domains', 'Hub title pretends to aggregate other registrars.');
    $method = new ReflectionMethod($class, 'getViewData');
    $connection = $capsule->getConnection(); $connection->enableQueryLog();
    foreach ([null, []] as $permissions) {
        $auth->current = $permissions === null ? null : new class extends App\Models\User {
            public function hasPermission($permission): bool { return false; }
        };
        $connection->flushQueryLog();
        checkHub(!$class::canAccess(), 'Anonymous/customer can access hub.');
        deniedHub(fn () => $page->mountCanAuthorizeAccess(), 'Native initial mount does not deny access.');
        deniedHub(fn () => $page->hydrateCanAuthorizeAccess(), 'Native Livewire hydration does not deny access.');
        deniedHub(fn () => $method->invoke($page), 'View data was queried before access denial.');
        checkHub($connection->getQueryLog() === [], 'Denied access queried hub rows.');
        $before = count($panel->getNavigationItems()); $class::registerNavigationItems();
        checkHub(count($panel->getNavigationItems()) === $before, 'Unauthorized Domains navigation registered.');
    }
    $staff = new class extends App\Models\User {
        public array $permissions = ['admin.enom.manage'];
        public function hasPermission($permission): bool { return in_array($permission, $this->permissions, true); }
    };
    $auth->current = $staff;
    checkHub($class::canAccess(), 'Manage permission does not grant access.');
    $page->mountCanAuthorizeAccess(); $page->hydrateCanAuthorizeAccess();
    $class::registerNavigationItems();
    checkHub(count($panel->getNavigationItems()) === 1 && $panel->getNavigationItems()[0]->getUrl() === $class::getUrl(), 'Authorized Domains entry missing or misdirected.');
    foreach ([['admin.enom.manage'], ['admin.enom.manage', 'admin.servers.update'],
        ['admin.enom.manage', 'admin.products.update'],
        ['admin.enom.manage', 'admin.servers.viewAny', 'admin.servers.update', 'admin.products.update']] as $permissions) {
        $staff->permissions = $permissions; $connection->flushQueryLog();
        $data = $method->invoke($page);
        safeHubQueries($connection->getQueryLog());
        checkHub(!preg_match('/PRIVATE-|"(?:uid|token|cost|payload|policy|snapshot|sponsorship|observation)"/', json_encode($data)), 'Private values entered hub view data.');
        $html = $app['view']->make($page->getView(), $data)->render();
        $doc = new DOMDocument; @$doc->loadHTML($html); $xpath = new DOMXPath($doc);
        checkHub($xpath->query('//form')->length === 0 && !str_contains($html, 'PRIVATE-'), 'Hub contains a mutation form or private sentinel.');
        $hrefs = [];
        foreach ($xpath->query('//a[@href]') as $link) $hrefs[] = $link->getAttribute('href');
        checkHub(in_array(route('enom.admin.index'), $hrefs, true), 'Hub cannot reach existing domain request review.');
        checkHub(in_array(route('enom.admin.help', ['topic' => 'setup']), $hrefs, true), 'Hub cannot reach existing setup Help.');
        $serverUrl = App\Admin\Resources\ServerResource::getUrl('edit', ['record' => 1001]);
        $productUrl = App\Admin\Resources\ProductResource::getUrl('edit', ['record' => 4001]);
        checkHub(in_array($serverUrl, $hrefs, true) === in_array('admin.servers.update', $permissions, true), 'Enom settings link escaped native update permission.');
        checkHub(in_array($productUrl, $hrefs, true) === in_array('admin.products.update', $permissions, true), 'Current product prices link escaped native update permission.');
        checkHub(!in_array(App\Admin\Resources\ServerResource::getUrl('edit', ['record' => 1002]), $hrefs, true), 'Hub linked another provider as Enom.');
        checkHub($data['domainCount'] === 1 && count($data['domains']) === 1
            && $data['domains'][0]['status'] === Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::AWAITING_PAYMENT,
            'Hub lost exact-bound awaiting-payment progress.');
        checkHub(in_array(route('enom.admin.domain', 2001), $hrefs, true), 'Hub lost provider-specific domain review destination.');
        checkHub(Filament\Support\Facades\FilamentView::hasSpaMode(route('enom.admin.index')) === false
            && Filament\Support\Facades\FilamentView::hasSpaMode(route('enom.admin.domain', 2001)) === false,
            'Legacy full-page review URLs remain subject to SPA interception.');
        checkHub(!$xpath->query('//a[@href="'.route('enom.admin.index').'"]')->item(0)->hasAttribute('wire:navigate'),
            'Legacy review link acquired SPA navigation.');
        if (in_array('admin.products.update', $permissions, true)) {
            checkHub(Filament\Support\Facades\FilamentView::hasSpaMode($productUrl), 'Native product editor was incorrectly excluded from SPA.');
        }
    }
    $capsule->table('invoices')->where('id', 7001)->update(['user_id' => 999]);
    $connection->flushQueryLog(); $data = $method->invoke($page); safeHubQueries($connection->getQueryLog());
    checkHub($data['domains'][0]['status'] !== Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::AWAITING_PAYMENT,
        'Foreign invoice owner produced trusted payment progress.');
    echo json_encode(['complete' => true, 'checks' => $checks, 'provider_calls' => 0, 
        'limits' => 'Native discovery, route generation, permission hooks and Blade with synthetic memory rows. Panel chrome/HTTP session/browser acceptance remain separate.'], JSON_THROW_ON_ERROR)."\n";
} finally { (new Illuminate\Filesystem\Filesystem)->deleteDirectory($cache); }
