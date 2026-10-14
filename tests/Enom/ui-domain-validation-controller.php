<?php
// Real native request/session/controller fallback. DomainOrders is an offline quote-call spy.
declare(strict_types=1);
require_once dirname(__DIR__).'/paymenter-test-app.php';

$repo = dirname(__DIR__, 2);
$release = paymenterTestApp();
$loader = require $release.'/vendor/autoload.php';
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Enom\\', $repo.'/Enom/', true);
$app = new Illuminate\Foundation\Application($release);
$app->instance('config', new Illuminate\Config\Repository(['app' => ['namespace' => 'App\\'], 'view' => ['paths' => [], 'compiled' => sys_get_temp_dir()]]));
$app->instance('events', new Illuminate\Events\Dispatcher($app));
$app->instance('files', new Illuminate\Filesystem\Filesystem);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->register(Illuminate\View\ViewServiceProvider::class);
$app['view']->addNamespace('enom', $repo.'/Enom/resources/views');
$routes = new Illuminate\Routing\RouteCollection;
$routes->add((new Illuminate\Routing\Route('GET', '/domains', fn () => null))->name('enom.domains.index'));
$user = new App\Models\User; $user->forceFill(['id' => 501]);
$operations = (new ReflectionClass(Paymenter\Extensions\Servers\Enom\Services\DomainOperations::class))->newInstanceWithoutConstructor();
$orders = new class extends Paymenter\Extensions\Servers\Enom\Services\DomainOrders {
    public array $calls = [];
    public function __construct() {}
    public function quote(App\Models\User $user, string $domain, string $action = 'register', int $term = 1): array {
        $this->calls[] = [$user->id, $domain, $action, $term];
        return ['intent_id' => 'offline-quote', 'domain' => $domain, 'action' => $action,
            'amount' => '15.00', 'renewal_amount' => '15.00', 'currency' => 'USD', 'expires_at' => '2099-01-01'];
    }
};
$controller = new Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController(
    new Paymenter\Extensions\Servers\Enom\Services\DomainManager($operations), $orders, $operations);
$checks = 0;
function checkFallback(bool $ok, string $message): void {
    global $checks; if (!$ok) throw new RuntimeException($message); $checks++;
}
function fallbackRequest(array $input): Illuminate\Http\Request {
    global $app, $routes, $user;
    $request = Illuminate\Http\Request::create('https://enom-fallback.invalid/domains/search', 'POST', $input);
    (new Illuminate\Foundation\Http\Middleware\TrimStrings)->handle($request,
        fn ($request) => (new Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull)->handle($request, fn () => null));
    $session = new Illuminate\Session\Store('domain-validation-fallback', new Illuminate\Session\ArraySessionHandler(60));
    $session->start(); $request->setLaravelSession($session); $request->setUserResolver(fn () => $user);
    $request->request->set('_request_key', Paymenter\Extensions\Servers\Enom\Http\FormKeys::issue($request, 'quote'));
    $url = new Illuminate\Routing\UrlGenerator($routes, $request);
    $redirect = new Illuminate\Routing\Redirector($url); $redirect->setSession($session);
    $app->instance('request', $request); $app->instance('session', $session);
    $app->instance('url', $url); $app->instance('redirect', $redirect);
    return $request;
}
foreach (['example.net', 'example.org', 'example.co.uk', 'www.example.com', '-example.com',
    'example-.com', 'ab--cd.com', str_repeat('a', 64).'.com', 'https://example.com',
    'example.com/path', 'example_name.com', '', ['example.com']] as $raw) {
    $request = fallbackRequest(['domain' => $raw, 'action' => 'register', 'epp' => 'PRIVATE-EPP', 'contact' => ['first_name' => 'PRIVATE-CONTACT']]);
    $key = $request->input('_request_key'); $before = count($orders->calls);
    $response = $controller->quote($request);
    checkFallback($response instanceof Illuminate\Http\RedirectResponse && $response->getTargetUrl() === 'https://enom-fallback.invalid/domains', 'JS-off invalid domain did not return to the customer form.');
    checkFallback(count($orders->calls) === $before, 'JS-off invalid domain reached quote/provider work.');
    checkFallback($request->session()->get('errors')->has('domain'), 'JS-off error was not bound to the domain field.');
    $effective = $request->input('domain');
    checkFallback($request->session()->get('_old_input') === ['domain' => is_string($effective) ? substr($effective, 0, 253) : '', 'action' => 'register'], 'JS-off fallback lost the ordinary entered domain or flashed private fields.');
    checkFallback(Paymenter\Extensions\Servers\Enom\Http\FormKeys::verify($request, 'quote') === $key, 'JS-off rejected search consumed its request key.');
}
foreach (['register', 'transfer'] as $action) {
    $request = fallbackRequest(['domain' => "\u{200B} EXAMPLE.COM \u{00A0}", 'action' => $action]);
    $before = count($orders->calls); $response = $controller->quote($request);
    checkFallback($response instanceof Illuminate\View\View && $response->name() === 'enom::order', 'Corrected native request did not open the existing order journey.');
    checkFallback(count($orders->calls) === $before + 1 && end($orders->calls) === [501, 'EXAMPLE.COM', $action, 1], 'Corrected request was rewritten or dispatched more than once.');
    checkFallback($response->getData()['quote']['currency'] === 'USD' && $response->getData()['quote']['amount'] === '15.00', 'Fallback changed the quoted invoice currency/amount contract.');
}
$request = fallbackRequest(['domain' => 'example.com', 'action' => 'other']);
$before = count($orders->calls); $controller->quote($request);
checkFallback(count($orders->calls) === $before && $request->session()->get('errors')->has('action'), 'Invalid action reached the order journey.');
echo json_encode(['complete' => true, 'checks' => $checks, 'provider_calls' => 0, 
    'limits' => 'Controller/session fallback and offline quote spy only; no order placement, payment or registrar execution.'], JSON_THROW_ON_ERROR)."\n";
