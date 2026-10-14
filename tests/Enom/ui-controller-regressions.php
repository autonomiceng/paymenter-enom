<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Native requests/sessions/controllers/views, in-memory SQLite, no app bootstrap/.env/network.
$root = dirname(__DIR__, 2);
$release = paymenterTestApp();
require $release.'/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'Paymenter\\Extensions\\Servers\\Enom\\';
    if (str_starts_with($class, $prefix)) {
        require $root.'/Enom/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\ViewServiceProvider;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;

$cache = sys_get_temp_dir().'/enom-ui-regression-'.bin2hex(random_bytes(8));
mkdir($cache, 0700);
$app = new Application($release);
$nativeConfig = require $release.'/config/app.php';
$app->instance('config', new Repository(['app' => ['countries' => $nativeConfig['countries'], 'namespace' => 'App\\'],
    'view' => ['paths' => [$release.'/themes/default/views'], 'compiled' => $cache]]));
$app->instance('events', new Illuminate\Events\Dispatcher($app));
$app->instance('files', new Illuminate\Filesystem\Filesystem);
Facade::setFacadeApplication($app);
$app->register(ViewServiceProvider::class);
$app['view']->addNamespace('enom', $root.'/Enom/resources/views');
$db = new Capsule($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$db->setAsGlobal();
$db->bootEloquent();
$db->schema()->create('enom_order_intents', function ($table) {
    $table->string('id')->primary(); $table->integer('user_id'); $table->string('domain'); $table->string('action');
    $table->string('amount'); $table->string('renewal_amount'); $table->string('currency');
    $table->dateTime('expires_at'); $table->dateTime('claimed_at')->nullable();
});
$db->schema()->create('enom_operations', function ($table) {
    $table->integer('id')->primary(); $table->string('amount'); $table->string('currency');
});
$db->table('enom_operations')->insert(['id' => 8, 'amount' => '15.00', 'currency' => 'USD']);
$intent = str_repeat('a', 48);
$db->table('enom_order_intents')->insert(['id' => $intent, 'user_id' => 101, 'domain' => 'demo.com', 'action' => 'register',
    'amount' => '15.00', 'renewal_amount' => '15.00', 'currency' => 'USD', 'expires_at' => date('Y-m-d H:i:s', time() + 900)]);
$user = (new User)->forceFill(['id' => 101]);
$orders = new class extends DomainOrders {
    public function __construct() {}
    public int $placements = 0;
    public int $searches = 0;
    public array $contacts = [];
    public ?string $epp = null;
    public function place(User $user, string $intentId, array $contacts = [], ?string $epp = null): Invoice {
        $this->placements++; $this->contacts = $contacts; $this->epp = $epp;
        return (new Invoice)->forceFill(['id' => 123]);
    }
    public function quote(User $user, string $domain, string $action = 'register', int $term = 1): array {
        $this->searches++; throw new RuntimeException('Synthetic provider unavailable');
    }
};
$manager = new class extends DomainManager {
    public function __construct() {}
    public int $writes = 0;
    public function owned(User $user, int $domainId): Domain { return (new Domain)->forceFill(['id' => $domainId]); }
    public function request(User $user, int $domainId, string $action, array $payload): Operation {
        self::validatePayload($action, $payload); $this->writes++;
        return (new Operation)->forceFill(['state' => 'queued']);
    }
};
$ops = (new ReflectionClass(DomainOperations::class))->newInstanceWithoutConstructor();
$controller = new DomainController($manager, $orders, $ops);
$routes = new RouteCollection;
foreach (['enom.help' => '/domains/help/{topic?}', 'enom.admin.help' => '/admin/enom/help/{topic?}', 'enom.domains.index' => '/domains', 'enom.domains.order' => '/domains/order/{intent}',
    'enom.domains.show' => '/domains/{domain}', 'invoices.show' => '/invoices/{invoice}'] as $name => $path) {
    $routes->add((new Route('GET', $path, fn () => null))->name($name));
}
$count = 0;
function check(bool $condition, string $message): void { global $count; if (!$condition) { throw new RuntimeException($message); } $count++; }
function requestFor(string $context, array $input): Request {
    global $app, $user, $routes;
    $request = Request::create('https://enom-tests.invalid/domains/order', 'POST', $input);
    $session = new Store('ui-regression', new ArraySessionHandler(60));
    $session->start(); $request->setLaravelSession($session); $request->setUserResolver(fn () => $user);
    $request->request->set('_request_key', FormKeys::issue($request, $context));
    $redirector = new Redirector(new UrlGenerator($routes, $request)); $redirector->setSession($session);
    $app->instance('redirect', $redirector); $app->instance('request', $request); $app->instance('session', $session);
    return $request;
}
function denied(callable $call, int $status, string $message): void {
    try { $call(); } catch (Illuminate\Database\Eloquent\ModelNotFoundException) { check($status === 404, $message); return; }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { check($e->getStatusCode() === $status, $message); return; }
    throw new RuntimeException($message);
}
$contact = ['first_name' => 'Demo', 'last_name' => 'Customer', 'address1' => '123 Demo Road', 'city' => 'Test',
    'postal_code' => '12345', 'country' => 'United States', 'email_address' => 'invalid', 'phone' => '555'];
try {
    $request = requestFor('order:'.$intent, ['intent_id' => $intent, 'confirmed' => '1', 'contact' => $contact + ['epp' => 'private-secret'], 'epp' => 'private-secret']);
    $key = $request->input('_request_key');
    $before = $db->table('enom_order_intents')->get()->toJson();
    $response = $controller->place($request);
    check(parse_url($response->getTargetUrl(), PHP_URL_PATH) === '/domains/order/'.$intent, 'Invalid contacts retain the original quote route');
    check($request->session()->get('errors')->keys() === ['contact.country', 'contact.email_address', 'contact.phone'], 'Contact errors bind independently to all three fields');
    check($request->session()->get('_old_input.contact') === $contact, 'Ordinary entered contact fields preserved, injected contact EPP omitted');
    check(!str_contains(json_encode($request->session()->all()), 'private-secret'), 'No authorization code flashed anywhere in session');
    check(FormKeys::verify($request, 'order:'.$intent) === $key, 'Validation leaves original session-bound order key valid');
    check($orders->placements === 0 && $before === $db->table('enom_order_intents')->get()->toJson(), 'Invalid input never places an order or writes the quote');
    $view = $controller->order($request, $intent);
    check($view->getData()['quote']['domain'] === 'demo.com' && $view->getData()['orderKey'] === $key, 'Retry GET recovers persisted quote without new search');
    $html = $app['view']->make('enom::partials.contact', ['contact' => [], 'contactId' => 'order-contact', 'contactForm' => 'order:'.$intent, 'errors' => $request->session()->get('errors')])->render();
    check(str_contains($html, 'value="Demo"') && str_contains($html, 'name="contact[country]"') && str_contains($html, 'value="US"'), 'Native country options and ordinary contact values rendered');
    check(str_contains($html, 'for="order-contact-phone"') && str_contains($html, 'id="order-contact-phone"') && str_contains($html, 'id="order-contact-phone-error"'), 'Contact labels and inline error references address actual controls');
    $otherHtml = $app['view']->make('enom::partials.contact', ['contact' => ['first_name' => 'Other role'], 'contactId' => 'admin', 'contactForm' => 'domain:1:contacts', 'contactType' => 'admin', 'errors' => $request->session()->get('errors')])->render();
    check(str_contains($otherHtml, 'value="Other role"') && !str_contains($otherHtml, 'id="admin-phone-error"'), 'Unrelated forms retain their cached contact and have no foreign inline errors');
    check((bool) preg_match('/<option value="" disabled\s+selected[^>]*>Choose a country<\/option>/', $otherHtml), 'Empty country has an explicit selected disabled placeholder');

    $valid = $contact; $valid['country'] = 'US'; $valid['email_address'] = 'demo@example.test'; $valid['phone'] = '(555) 234-5678';
    $request = requestFor('order:'.$intent, ['intent_id' => $intent, 'confirmed' => '1', 'contact' => $valid, 'epp' => ['malformed-registration-ignored']]);
    $response = $controller->place($request);
    check($orders->placements === 1 && $orders->contacts['registrant']['phone'] === '+1.5552345678' && $orders->epp === null, 'Browser phone accepted, optional organization and four normalized contacts passed to billing');
    check(parse_url($response->getTargetUrl(), PHP_URL_PATH) === '/invoices/123', 'Successful corrected submission reaches native invoice');
    denied(fn () => FormKeys::verify($request, 'order:'.$intent), 419, 'Successful order consumes form key');

    $db->table('enom_order_intents')->where('id', $intent)->update(['action' => 'transfer']);
    $request = requestFor('order:'.$intent, ['intent_id' => $intent, 'confirmed' => '1', 'contact' => $valid, 'epp' => "bad\nsecret"]);
    $controller->place($request);
    check($request->session()->get('errors')->keys() === ['epp'] && !$request->session()->has('_old_input.epp') && $orders->placements === 1, 'Invalid transfer EPP gives a safe field error without persistence or billing');
    $request = requestFor('order:'.$intent, ['intent_id' => $intent, 'contact' => $valid]);
    $controller->place($request);
    check($request->session()->get('errors')->keys() === ['confirmed'] && $orders->placements === 1, 'Missing confirmation does not bill');
    $unknownCountry = $valid; $unknownCountry['country'] = 'ZZ'; $unknownCountry['phone'] = '+1.5552345678';
    $request = requestFor('order:'.$intent, ['intent_id' => $intent, 'confirmed' => '1', 'contact' => $unknownCountry, 'epp' => 'synthetic-code']);
    $controller->place($request);
    check($request->session()->get('errors')->keys() === ['contact.country'] && $orders->placements === 1, 'Unsupported two-letter country rejected against native country options before billing');

    $other = (new User)->forceFill(['id' => 202]);
    $request->setUserResolver(fn () => $other);
    denied(fn () => $controller->order($request, $intent), 404, 'Foreign user cannot reopen quote');
    denied(fn () => $controller->place($request), 404, 'Foreign user cannot submit quote even with a synthetic valid form key');
    $db->table('enom_order_intents')->where('id', $intent)->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);
    $request = requestFor('order:'.$intent, ['intent_id' => $intent, 'confirmed' => '1', 'contact' => $valid]);
    check(parse_url($controller->order($request, $intent)->getTargetUrl(), PHP_URL_PATH) === '/domains', 'Expired quote requires a fresh search');
    $controller->place($request);
    check($orders->placements === 1, 'Expired quote never bills');

    $request = requestFor('quote', ['domain' => 'https://invalid.example.com', 'action' => 'register', 'epp' => 'private-secret']);
    $controller->quote($request);
    check($request->session()->get('errors')->keys() === ['domain'] && $request->session()->get('_old_input.domain') === 'https://invalid.example.com' && $orders->searches === 0, 'Invalid search keeps ordinary input with local field error before provider call');
    check(!$request->session()->has('_old_input.epp'), 'Search validation never flashes EPP');

    $request = requestFor('domain:7:contacts', ['confirmed' => '1', 'contact_type' => 'tech', 'contact' => $contact, 'epp' => 'private-secret']);
    $controller->manage($request, 7, 'contacts');
    check($request->session()->get('_old_input._enom_form') === 'domain:7:contacts' && $request->session()->get('_old_input.contact_type') === 'tech' && $manager->writes === 0, 'Management retry retains and isolates submitted contact role before enqueue');
    check(!$request->session()->has('_old_input.epp'), 'Management retry never flashes unknown EPP');
    $request = requestFor('domain:7:hosts', ['confirmed' => '1', 'preimage_digest' => str_repeat('b', 64), 'records' => [['name' => '@', 'type' => 'A', 'address' => 'invalid', 'epp' => 'private-secret']]]);
    $controller->manage($request, 7, 'hosts');
    check($request->session()->get('_old_input.records') === [['name' => '@', 'type' => 'A', 'address' => 'invalid']] && $request->session()->get('_old_input.preimage_digest') === str_repeat('b', 64), 'DNS retry preserves only ordinary records and original concurrency preimage');
    check($manager->writes === 0, 'Invalid management actions never enqueue registrar writes');

    $fundingOps = new class extends DomainOperations {
        public function __construct() {}
        public array $approvals = [];
        public function approveFunding(int $operationId, User $staff, string $reason): Operation {
            $this->approvals[] = [$operationId, $reason];
            return (new Operation)->forceFill(['state' => 'queued']);
        }
    };
    $admin = new AdminController($fundingOps);
    $staff = new class extends User { public function hasPermission($permission): bool { return true; } };
    $routes->add((new Route('GET', '/admin/enom', fn () => null))->name('enom.admin.index'));
    $request = requestFor('staff:8:fund', ['confirmed' => '1', 'reason' => "Operator funding\r\nReviewed cost", 'amount' => '15.00', 'currency' => 'USD']);
    $request->setUserResolver(fn () => $staff);
    $admin->fund($request, 8);
    check($fundingOps->approvals === [[8, 'Operator funding Reviewed cost']], 'Browser multiline funding reason normalized into permitted single-line audit text');
    denied(fn () => FormKeys::verify($request, 'staff:8:fund'), 419, 'Successful funding consumes its form key');
    $request = requestFor('staff:8:fund', ['confirmed' => '1', 'reason' => "bad\tcontrol text", 'amount' => '15.00', 'currency' => 'USD', 'epp' => 'private-secret']);
    $request->setUserResolver(fn () => $staff);
    $admin->fund($request, 8);
    check($request->session()->get('errors')->keys() === ['reason'] && count($fundingOps->approvals) === 1, 'Other funding control characters rejected before authorization');
    check($request->session()->get('_old_input._enom_form') === 'staff:8:fund' && !$request->session()->has('_old_input.epp'), 'Funding retry retains only scoped ordinary reason');

    $request = requestFor('quote', ['domain' => 'example.com', 'action' => 'register']);
    $request->request->set('_request_key', str_repeat('f', 64));
    denied(fn () => $controller->quote($request), 419, 'Incorrect form key blocks search before provider request');
    $csrf = new class($app, new Illuminate\Encryption\Encrypter(str_repeat('t', 32), 'AES-256-CBC')) extends Illuminate\Foundation\Http\Middleware\VerifyCsrfToken {
        protected $addHttpCookie = false;
        protected function runningUnitTests() { return false; }
    };
    try { $csrf->handle($request, fn () => throw new RuntimeException('CSRF bypass')); }
    catch (Illuminate\Session\TokenMismatchException) { check(true, 'Native CSRF middleware rejects missing token independently of Enom form key'); }
    $request->request->set('_token', $request->session()->token());
    $result = $csrf->handle($request, fn () => new Illuminate\Http\Response('accepted'));
    check($result->getContent() === 'accepted', 'Native CSRF middleware accepts session token');
    fwrite(STDOUT, "UI controller/session regressions: {$count} passed\n");
} finally {
    foreach (glob($cache.'/*') as $file) { unlink($file); }
    rmdir($cache);
}
