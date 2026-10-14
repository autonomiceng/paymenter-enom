<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';
// Native Blade/field components and sessions; no app bootstrap, .env, database or network.
$root = dirname(__DIR__, 2);
$release = paymenterTestApp();
require $release.'/vendor/autoload.php';
require $root.'/Enom/Services/DomainManager.php';
require $root.'/Enom/Services/DomainPresentation.php';
require $root.'/Enom/Services/DomainOperations.php';
require $root.'/Enom/Http/Controllers/DomainController.php';
require $root.'/Enom/Http/Controllers/AdminController.php';
$app = new Illuminate\Foundation\Application($release);
$cache = sys_get_temp_dir().'/enom-live-render-'.bin2hex(random_bytes(8));
mkdir($cache, 0700); mkdir($cache.'/layouts', 0700); mkdir($cache.'/components', 0700);
file_put_contents($cache.'/components/ri-loader-5-fill.blade.php', '<span aria-hidden="true"></span>');
// Replace only the enclosing application layout to avoid auth, Vite and app hooks.
file_put_contents($cache.'/layouts/app.blade.php', '<!doctype html><html><body>{{ $slot }}</body></html>');
$native = require $release.'/config/app.php';
$app->instance('config', new Illuminate\Config\Repository(['app' => ['countries' => $native['countries'], 'namespace' => 'App\\'], 'view' => ['paths' => [$cache, $release.'/themes/default/views'], 'compiled' => $cache]]));
$app->instance('events', new Illuminate\Events\Dispatcher($app));
$app->instance('files', new Illuminate\Filesystem\Filesystem);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->register(Illuminate\View\ViewServiceProvider::class);
$app->register(Illuminate\Pagination\PaginationServiceProvider::class);
$app['view']->addNamespace('pagination', $release.'/vendor/laravel/framework/src/Illuminate/Pagination/resources/views');
$app['view']->addNamespace('enom', $root.'/Enom/resources/views');
$routes = new Illuminate\Routing\RouteCollection;
foreach (['enom.admin.index' => '/admin/enom', 'enom.admin.domain' => '/admin/enom/domains/{domain}', 'enom.help' => '/domains/help/{topic?}', 'enom.admin.help' => '/admin/enom/help/{topic?}', 'services.show' => '/services/{service}', 'enom.domains.index' => '/domains', 'enom.domains.quote' => '/domains/search', 'enom.domains.place' => '/domains/order', 'enom.domains.show' => '/domains/{domain}', 'enom.domains.refresh' => '/domains/{domain}/refresh', 'enom.domains.preference' => '/domains/{domain}/renewal-preference', 'enom.domains.renew' => '/domains/{domain}/renew', 'enom.domains.manage' => '/domains/{domain}/manage/{action}', 'enom.domains.privacy' => '/domains/{domain}/privacy/{action}', 'enom.admin.fund' => '/admin/enom/{operation}/fund', 'enom.admin.reconcile' => '/admin/enom/{operation}/reconcile', 'filament.admin.pages.dashboard' => '/admin'] as $name => $path) {
    $routes->add((new Illuminate\Routing\Route('GET', $path, fn () => null))->name($name));
}
$request = Illuminate\Http\Request::create('https://enom-validation.invalid');
$session = new Illuminate\Session\Store('live-render', new Illuminate\Session\ArraySessionHandler(60));
$session->start(); $request->setLaravelSession($session);
$app->instance('request', $request); $app->instance('session', $session);
$app->instance('url', new Illuminate\Routing\UrlGenerator($routes, $request));
$errors = new Illuminate\Support\ViewErrorBag;
$errors->put('default', new Illuminate\Support\MessageBag(['contact.phone' => 'Saved server phone error.', 'contact.country' => 'Saved server country error.']));
$app['view']->share('errors', $errors);
$contact = ['first_name' => 'Demo', 'last_name' => 'Customer', 'address1' => '123 Example Road', 'city' => 'Test', 'postal_code' => '12345', 'country' => 'United States', 'email_address' => 'demo@example.test', 'phone' => '555'];
$session->put('_old_input', ['_enom_form' => 'order:fixture', 'contact' => $contact]);
$key = str_repeat('a', 64);
try {
    $pages = [];
    $pages['order'] = $app['view']->make('enom::order', ['quote' => ['intent_id' => 'fixture', 'action' => 'transfer', 'domain' => 'demo.com', 'currency' => 'USD', 'amount' => '15.00', 'renewal_amount' => '15.00', 'expires_at' => '2099-01-01'], 'orderKey' => $key])->render();
    $validContact = $contact; $validContact['country'] = 'US'; $validContact['phone'] = '(555) 234-5678';
    $session->put('_old_input', ['_enom_form' => 'order:fixture', 'contact' => $validContact]);
    $pages['order-server-valid'] = $app['view']->make('enom::order', ['quote' => ['intent_id' => 'fixture', 'action' => 'register', 'domain' => 'demo.com', 'currency' => 'USD', 'amount' => '15.00', 'renewal_amount' => '15.00', 'expires_at' => '2099-01-01'], 'orderKey' => $key])->render();
    $session->forget('_old_input');
    $pages['index'] = $app['view']->make('enom::index', ['domains' => new Illuminate\Pagination\LengthAwarePaginator([], 0, 20), 'search' => '', 'searchKey' => $key])->render();
    $cached = Paymenter\Extensions\Servers\Enom\Services\DomainManager::safeSnapshot([]);
    $cached['contacts'] = array_fill_keys(['registrant', 'admin', 'tech', 'auxbilling'], $contact + ['organization_name' => 'Example']);
    foreach ($cached['contacts'] as &$row) { $row['country'] = 'US'; $row['phone'] = '(555) 234-5678'; } unset($row);
    $cached['nameservers'] = ['ns1.example.com', 'ns2.example.com'];
    $snapshot = ['id' => 7, 'domain' => 'demo.com', 'status' => 'registered', 'provider_expires_at' => null, 'billing_due_at' => null, 'service_id' => 123, 'last_synced_at' => null, 'renewal_enabled' => true, 'writes_blocked' => false, 'needs_review' => false, 'capabilities' => array_fill_keys(Paymenter\Extensions\Servers\Enom\Services\DomainManager::ACTIONS, true), 'operations' => [], 'cached' => $cached, 'hosts_digest' => str_repeat('b', 64), 'srv_digest' => str_repeat('c', 64)];
    $keys = array_fill_keys([...Paymenter\Extensions\Servers\Enom\Services\DomainManager::ACTIONS, 'refresh', 'renewal_preference', 'renew', 'privacy_purchase', 'privacy_renew'], $key);
    $pages['show'] = $app['view']->make('enom::show', ['snapshot' => $snapshot, 'keys' => $keys])->render();
    $operation = (object) ['id' => 8, 'action' => 'register', 'state' => 'awaiting_payment', 'domain' => (object) ['domain' => 'demo.com'], 'funding_basis' => 'unfunded', 'amount' => '15.00', 'currency' => 'USD', 'invoice_id' => null, 'created_at' => null, 'confirmed_expires_at' => null, 'started_at' => null, 'term' => 1];
    $pages['admin'] = $app['view']->make('enom::admin', ['canFund' => true, 'domainCount' => 1, 'reviewCount' => 0, 'operations' => new Illuminate\Pagination\LengthAwarePaginator([$operation], 1, 20), 'keys' => [8 => ['fund' => $key]], 'fundingBlockers' => []])->render();
    $session->put('_old_input', ['_enom_form' => 'staff:8:fund', 'reason' => 'Previously reviewed funding']);
    $fundingErrors = new Illuminate\Support\ViewErrorBag;
    $fundingErrors->put('default', new Illuminate\Support\MessageBag(['reason' => 'Saved server funding error.']));
    $app['view']->share('errors', $fundingErrors);
    $pages['admin-server'] = $app['view']->make('enom::admin', ['canFund' => true, 'domainCount' => 1, 'reviewCount' => 0, 'operations' => new Illuminate\Pagination\LengthAwarePaginator([$operation], 1, 20), 'keys' => [8 => ['fund' => $key]], 'fundingBlockers' => []])->render();
    echo json_encode($pages, JSON_THROW_ON_ERROR);
} finally {
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($cache);
}
