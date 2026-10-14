<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';
// Native Blade/field components and sessions; no app bootstrap, .env, database or network.
$root = dirname(__DIR__, 2);
$release = paymenterTestApp();
require $release.'/vendor/autoload.php';
require $root.'/Enom/Services/DomainManager.php';
require $root.'/Enom/Services/DomainPresentation.php';
require $root.'/Enom/Services/DomainOperations.php';
require $root.'/Enom/Http/Controllers/HelpController.php';
require $root.'/Enom/Http/Controllers/DomainController.php';
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
foreach (['enom.admin.index' => '/admin/enom', 'enom.help' => '/domains/help/{topic?}', 'enom.admin.help' => '/admin/enom/help/{topic?}', 'services.show' => '/services/{service}', 'enom.domains.index' => '/domains', 'enom.domains.quote' => '/domains/search', 'enom.domains.place' => '/domains/order', 'enom.domains.show' => '/domains/{domain}', 'enom.domains.refresh' => '/domains/{domain}/refresh', 'enom.domains.preference' => '/domains/{domain}/renewal-preference', 'enom.domains.renew' => '/domains/{domain}/renew', 'enom.domains.manage' => '/domains/{domain}/manage/{action}', 'enom.domains.privacy' => '/domains/{domain}/privacy/{action}', 'enom.admin.fund' => '/admin/enom/{operation}/fund', 'enom.admin.reconcile' => '/admin/enom/{operation}/reconcile', 'filament.admin.pages.dashboard' => '/admin'] as $name => $path) {
    $routes->add((new Illuminate\Routing\Route('GET', $path, fn () => null))->name($name));
}
$request = Illuminate\Http\Request::create('https://enom-validation.invalid');
$session = new Illuminate\Session\Store('live-render', new Illuminate\Session\ArraySessionHandler(60));
$session->start(); $request->setLaravelSession($session);
$app->instance('request', $request); $app->instance('session', $session);
$app->instance('url', new Illuminate\Routing\UrlGenerator($routes, $request));
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag);
try {
    $pages = [];
    foreach (['customer' => Paymenter\Extensions\Servers\Enom\Http\Controllers\HelpController::CUSTOMER_TOPICS,
        'admin' => Paymenter\Extensions\Servers\Enom\Http\Controllers\HelpController::ADMIN_TOPICS] as $audience => $topics) {
        foreach ([null, ...array_keys($topics)] as $topic) {
            $pages[$audience.':'.($topic ?? 'index')] = $app['view']->make('enom::help.'.$audience, compact('topic', 'topics'))->render();
        }
    }
    echo json_encode($pages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($cache);
}
