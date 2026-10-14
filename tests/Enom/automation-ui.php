<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';
// Native Request/session/controllers and Blade components; no app bootstrap, DB, .env or provider calls.
$root = dirname(__DIR__, 2);
$release = paymenterTestApp();
require $release.'/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'Paymenter\\Extensions\\Servers\\Enom\\';
    if (str_starts_with($class, $prefix)) { require $root.'/Enom/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php'; }
});
use App\Models\User;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainAdministration;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;

$app = new Illuminate\Foundation\Application($release);
$cache = sys_get_temp_dir().'/enom-automation-ui-'.bin2hex(random_bytes(8));
mkdir($cache, 0700); mkdir($cache.'/layouts', 0700); mkdir($cache.'/components', 0700);
file_put_contents($cache.'/layouts/app.blade.php', '<html><body>{{ $slot }}</body></html>');
file_put_contents($cache.'/components/ri-loader-5-fill.blade.php', '<span aria-hidden="true"></span>');
$app->instance('config', new Illuminate\Config\Repository(['app' => ['namespace' => 'App\\'], 'view' => ['paths' => [$cache, $release.'/themes/default/views'], 'compiled' => $cache]]));
$app->instance('events', new Illuminate\Events\Dispatcher($app));
$app->instance('files', new Illuminate\Filesystem\Filesystem);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->register(Illuminate\View\ViewServiceProvider::class);
$app->register(Illuminate\Pagination\PaginationServiceProvider::class);
$app['view']->addNamespace('enom', $root.'/Enom/resources/views');
$app['view']->addNamespace('pagination', $release.'/vendor/laravel/framework/src/Illuminate/Pagination/resources/views');
$routes = new Illuminate\Routing\RouteCollection;
foreach (['enom.help' => '/domains/help/{topic?}', 'enom.admin.help' => '/admin/enom/help/{topic?}', 'enom.admin.index' => '/admin/enom', 'enom.admin.domain' => '/admin/enom/domains/{domain}', 'enom.admin.policy' => '/admin/enom/domains/{domain}/policy', 'enom.admin.sponsorship' => '/admin/enom/domains/{domain}/sponsorship', 'enom.admin.refresh' => '/admin/enom/domains/{domain}/refresh', 'enom.admin.sponsored-renewal' => '/admin/enom/domains/{domain}/sponsored-renewal', 'invoices.show' => '/invoices/{invoice}', 'filament.admin.pages.dashboard' => '/admin'] as $name => $path) {
    $routes->add((new Illuminate\Routing\Route('GET', $path, fn () => null))->name($name));
}
$staff = new class extends User {
    public array $permissions = ['admin.enom.manage', 'admin.enom.fund', 'admin.enom.costs'];
    public function hasPermission($permission): bool { return in_array($permission, $this->permissions, true); }
};
$data = ['id' => 7, 'domain' => 'example.com', 'service_id' => 88, 'status' => 'active', 'provider_expires_at' => '2027-12-01', 'managed' => false,
    'observed_expires_at' => '2027-12-01', 'observed_at' => '2026-10-13T10:00:00Z', 'observation_status' => 'fresh', 'observation_problem' => null,
    'calendar' => ['issue_at' => '2027-11-10', 'due_at' => '2027-11-24'], 'policy' => ['enabled' => false, 'issue_days' => 21, 'due_days' => 7, 'credits_auto_use' => false],
    'eligibility' => ['can_enable' => true, 'reason' => null], 'sponsorship' => [], 'permissions' => ['manage' => true, 'fund' => true, 'costs' => true],
    'costs' => [['operation_id' => 20, 'action' => 'renew', 'quoted' => ['amount' => '11.23', 'currency' => 'USD'], 'confirmed' => null, 'status' => 'cost_unconfirmed']]];
$administration = new class extends DomainAdministration {
    public array $data = [];
    public array $calls = [];
    public bool $reject = false;
    public function __construct() {}
    public function get(User $user, int $domain): array { return $this->data; }
    public function savePolicy(User $user, int $domain, array $input): void {
        if ($this->reject) { throw new InputValidationException(['issue_days' => 'Issue must precede due.']); }
        $this->calls[] = ['policy', $domain, $input];
    }
    public function saveSponsorship(User $user, int $domain, array $input): void { $this->calls[] = ['sponsorship', $domain, $input]; }
    public function refreshObservation(User $user, int $domain): string { $this->calls[] = ['refresh', $domain]; return 'observed'; }
    public function requestSponsoredRenewal(User $user, int $domain): ?Operation { $this->calls[] = ['sponsored', $domain]; return (new Operation)->forceFill(['id' => 9]); }
};
$administration->data = $data;
$ops = (new ReflectionClass(DomainOperations::class))->newInstanceWithoutConstructor();
$controller = new AdminController($ops, $administration);
$count = 0;
function check(bool $ok, string $message): void { global $count; if (!$ok) { throw new RuntimeException($message); } $count++; }
function requestFor(string $action, array $input = []): Request {
    global $app, $staff, $routes;
    $request = Request::create('https://enom-tests.invalid/admin/enom/domains/7/'.$action, 'POST', $input);
    $session = new Illuminate\Session\Store('automation-ui', new Illuminate\Session\ArraySessionHandler(60));
    $session->start(); $request->setLaravelSession($session); $request->setUserResolver(fn () => $staff);
    $request->request->set('_request_key', FormKeys::issue($request, 'staff-domain:7:'.$action));
    $url = new Illuminate\Routing\UrlGenerator($routes, $request);
    $redirector = new Illuminate\Routing\Redirector($url); $redirector->setSession($session);
    $app->instance('redirect', $redirector); $app->instance('url', $url); $app->instance('request', $request); $app->instance('session', $session);
    return $request;
}
function denied(callable $call, int $status, string $message): void {
    try { $call(); } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) { check($exception->getStatusCode() === $status, $message); return; }
    throw new RuntimeException($message);
}
try {
    $request = requestFor('policy', ['enabled' => '1', 'billing_managed' => '1', 'issue_days' => '5', 'due_days' => '7', 'confirmed' => '1', 'injected_cost' => 'private', 'reason' => 'unrelated']);
    $administration->reject = true;
    $key = $request->input('_request_key');
    $controller->policy($request, 7);
    check($request->session()->get('errors')->keys() === ['issue_days'], 'Calendar validation binds to its individual field');
    check($request->session()->get('_old_input') === ['_enom_form' => 'staff-domain:7:policy', 'billing_managed' => '1', 'enabled' => '1', 'issue_days' => '5', 'due_days' => '7'], 'Only scoped ordinary policy fields are flashed');
    check(FormKeys::verify($request, 'staff-domain:7:policy') === $key && !$administration->calls, 'Invalid policy preserves nonce and performs no helper mutation');
    $view = $controller->domain($request, 7);
    $app['view']->share('errors', $request->session()->get('errors'));
    $html = $view->render();
    check(str_contains($html, 'value="5"') && str_contains($html, 'id="enom-admin-7-policy-issue_days-error"') && str_contains($html, 'aria-invalid="true"'), 'Corrective admin form retains values and accessible individual error');
    check(str_contains($html, 'USD 11.23') && str_contains($html, 'Not confirmed'), 'Authorized admin distinguishes quote from unconfirmed actual cost');
    check(str_contains($html, 'This renewal only') && str_contains($html, 'Every future renewal'), 'Sponsorship offers individual cycle and standing approvals');
    $administration->reject = false;
    $request = requestFor('policy', ['enabled' => '1', 'billing_managed' => '1', 'issue_days' => '21', 'due_days' => '7', 'credits_auto_use' => '0', 'confirmed' => '1', 'provider_secret' => 'private']);
    $controller->policy($request, 7);
    check($administration->calls === [['policy', 7, ['billing_managed' => '1', 'enabled' => '1', 'issue_days' => '21', 'due_days' => '7', 'credits_auto_use' => '0']]], 'Confirmed policy forwards only whitelisted fields');
    denied(fn () => FormKeys::verify($request, 'staff-domain:7:policy'), 419, 'Successful policy consumes nonce');
    $request = requestFor('refresh');
    $controller->refresh($request, 7);
    check($administration->calls[1] === ['refresh', 7], 'Read-only observation POST needs no funding confirmation');
    denied(fn () => FormKeys::verify($request, 'staff-domain:7:refresh'), 419, 'Read-only observation consumes its nonce');
    $request = requestFor('refresh', ['confirmed' => '1']);
    $controller->refresh($request, 7);
    check($administration->calls[2] === ['refresh', 7] && count($administration->calls) === 3, 'Refresh invokes only observation helper and never purchase/policy approval');
    $request = requestFor('sponsorship', ['enabled' => '1', 'standing' => '0', 'limit_amount' => '20.00', 'currency' => 'USD', 'term' => '1', 'reason' => 'Individual approved domain cycle', 'confirmed' => '1', 'approved_by' => 'forged']);
    $controller->sponsorship($request, 7);
    check($administration->calls[3][0] === 'sponsorship' && !isset($administration->calls[3][2]['approved_by']), 'Sponsorship approval never trusts posted approver identity');
    check(count($administration->calls) === 4, 'Saving sponsorship does not request a purchase');
    $request = requestFor('sponsored-renewal', ['confirmed' => '1']);
    $controller->sponsoredRenewal($request, 7);
    check($administration->calls[4] === ['sponsored', 7], 'Sponsored purchase requires its separate confirmed action');
    $staff->permissions = ['admin.enom.manage'];
    denied(fn () => $controller->sponsorship(requestFor('sponsorship', ['confirmed' => '1']), 7), 403, 'Manage permission cannot approve funding');
    denied(fn () => $controller->sponsoredRenewal(requestFor('sponsored-renewal', ['confirmed' => '1']), 7), 403, 'Manage permission cannot spend sponsorship');
    $request = requestFor('policy'); $request->request->set('_request_key', str_repeat('f', 64));
    denied(fn () => $controller->policy($request, 7), 419, 'Invalid domain nonce prevents policy action');
    $staff->permissions = [];
    denied(fn () => $controller->domain(requestFor('policy'), 7), 403, 'Customer cannot view domain administration');

    $request = requestFor('policy'); $app['view']->share('errors', new Illuminate\Support\ViewErrorBag);
    $restricted = $data; $restricted['permissions'] = ['manage' => true, 'fund' => false, 'costs' => false];
    $keys = array_fill_keys(['policy', 'sponsorship', 'refresh', 'sponsored-renewal'], str_repeat('a', 64));
    $html = $app['view']->make('enom::admin-domain', ['domain' => $restricted, 'keys' => $keys])->render();
    check(!str_contains($html, '11.23') && !str_contains($html, 'Individual sponsorship approval'), 'Restricted staff HTML excludes internal cost and sponsorship details');
    check(!preg_match('/id="enroll-domain-7"[^>]*checked/', $html), 'Existing unmanged mapping is not preselected for enrollment');
    $snapshot = ['domain' => 'example.com', 'provider_expires_at' => '2027-12-01', 'automation' => ['observed_expires_at' => '2027-12-02', 'observed_at' => null, 'observation_status' => 'stale', 'observation_problem' => 'Observed expiry differs from confirmed registrar expiry.', 'calendar' => ['issue_at' => '2027-11-10', 'due_at' => '2027-11-24'], 'invoices' => [['id' => 123, 'status' => 'pending', 'due_at' => '2027-11-24']], 'managed' => true, 'enabled' => true]];
    $html = $app['view']->make('enom::partials.automation-info', ['snapshot' => $snapshot])->render();
    check(str_contains($html, '/invoices/123') && str_contains($html, 'different expiry date') && str_contains($html, 'Support will review'), 'Customer calendar exposes native invoice and stale observed expiry separately');
    check(!str_contains($html, '11.23') && !str_contains($html, 'sponsor') && !str_contains($html, 'budget') && !str_contains($html, 'reseller'), 'Customer HTML omits internal financial authority');
    $operation = (object) ['id' => 8, 'action' => 'renew', 'state' => 'awaiting_payment', 'domain' => (object) ['domain' => 'example.com'], 'funding_basis' => 'paid_invoice', 'amount' => '15.00', 'currency' => 'USD', 'invoice_id' => 123, 'created_at' => null, 'confirmed_expires_at' => null, 'started_at' => null, 'term' => 1];
    $html = $app['view']->make('enom::admin', ['canFund' => true, 'domainCount' => 1, 'reviewCount' => 0, 'operations' => new Illuminate\Pagination\LengthAwarePaginator([$operation], 1, 20), 'keys' => [], 'fundingBlockers' => []])->render();
    check(!str_contains($html, 'Approve Operator funding'), 'Customer linked invoice cannot use the generic funding override');
    $routesSource = file_get_contents($root.'/Enom/routes/web.php');
    foreach (['policy', 'sponsorship', 'refresh', 'sponsored-renewal'] as $action) {
        check((bool) preg_match("~Route::post\('/admin/enom/domains/\\{domain\\}/".preg_quote($action, '~')."'.*block\\(10, 10\\)~", $routesSource), 'Domain action '.$action.' is POST and session-serialized');
    }
    fwrite(STDOUT, "Automation native UI: {$count} passed\n");
} finally {
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($cache);
}
