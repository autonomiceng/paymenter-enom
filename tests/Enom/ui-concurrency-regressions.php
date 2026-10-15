<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Reuse the pristine native SQLite/fake-provider acceptance setup, never an installed app.
require __DIR__.'/billing-integration.php';
$successful = false;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;

$initialChecks = $checks;
$actor = User::create(['email' => 'concurrency@example.invalid', 'password' => 'synthetic']);
$oldRecords = [['name' => '@', 'type' => 'A', 'address' => '192.0.2.1', 'mx_pref' => 10]];
$freshRecords = [['name' => '@', 'type' => 'A', 'address' => '192.0.2.2', 'mx_pref' => 10]];
$desiredRecords = [['name' => '@', 'type' => 'A', 'address' => '192.0.2.3', 'mx_pref' => 10]];
$current = Illuminate\Database\Eloquent\Model::withoutEvents(function () use ($actor, $product, $plan, $freshRecords) {
    $order = App\Models\Order::create(['user_id' => $actor->id, 'currency_code' => 'USD']);
    $service = $order->services()->create(['user_id' => $actor->id, 'product_id' => $product->id,
        'plan_id' => $plan->id, 'price' => '15.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => 'active']);

    return Domain::create(['service_id' => $service->id, 'user_id' => $actor->id, 'server_id' => $product->server_id,
        'domain' => 'concurrency.com', 'sld' => 'concurrency', 'tld' => 'com', 'status' => 'active',
        'snapshot' => ['hosts' => $freshRecords], 'capabilities' => ['hosts' => true]]);
});
$guardedOperations = new class extends DomainOperations {
    public function __construct() {}
    public int $enqueues = 0;
    public function enqueueManagement(User $actor, int $domainId, string $action, array $payload, string $nonce): Operation
    {
        $this->enqueues++;
        throw new RuntimeException('A stale DNS request reached the operation writer.');
    }
};
$manager = new DomainManager($guardedOperations);
$controller = new DomainController($manager, new DomainOrders($guardedOperations), $guardedOperations);
$request = Request::create('http://enom-tests.invalid/domains/'.$current->id.'/hosts', 'POST', [
    'confirmed' => '1', 'records' => array_map(fn ($row) => array_map('strval', $row), $desiredRecords), 'preimage_digest' => DomainManager::digest($oldRecords),
]);
$session = new Store('concurrency-regression', new ArraySessionHandler(60));
$session->start();
$request->setLaravelSession($session);
$context = 'domain:'.$current->id.':hosts';
$key = FormKeys::issue($request, $context);
$request->request->set('_request_key', $key);
$app->instance('request', $request);
$request->setUserResolver(fn () => $actor);
$app->instance('session', $session);
$app['redirect']->setSession($session);
$app['url']->setRequest($request);
$businessRows = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
    ['enom_domains', 'enom_operations', 'services', 'invoices', 'invoice_items', 'invoice_transactions', 'jobs', 'failed_jobs']);
$before = $businessRows();
$response = $controller->manage($request, $current->id, 'hosts');
check(str_contains($session->get('errors')->first('enom'), 'DNS records changed'), 'Concurrent snapshot change returns the explicit review error.');
check($guardedOperations->enqueues === 0 && $businessRows() === $before, 'Stale DNS list changes no business rows or queue.');
check(FormKeys::verify($request, $context) === $key, 'Stale request retains its original session key.');
check($session->get('_old_input.preimage_digest') === DomainManager::digest($oldRecords), 'Retry retains original digest rather than fresh snapshot digest.');
check($session->get('_old_input.records') === array_map(fn ($row) => array_map('strval', $row), $desiredRecords), 'Retry retains scoped ordinary requested records.');
$snapshot = $manager->snapshot($actor, $current->id);
$html = $app['view']->make('enom::partials.dns', ['domainId' => $current->id, 'snapshot' => $snapshot,
    'cached' => $snapshot['cached'], 'available' => fn ($action) => $action === 'hosts',
    'keys' => ['hosts' => $key], 'errors' => $session->get('errors')])->render();
check(str_contains($html, 'name="preimage_digest" value="'.DomainManager::digest($oldRecords).'"')
    && !str_contains($html, DomainManager::digest($freshRecords)), 'Rendered retry never silently rebases stale list to current DNS.');
$controller->manage($request, $current->id, 'hosts');
check($guardedOperations->enqueues === 0 && $businessRows() === $before, 'Repeated stale retry remains blocked without an operation.');
$session->forget('_old_input');
$html = $app['view']->make('enom::partials.dns', ['domainId' => $current->id, 'snapshot' => $snapshot,
    'cached' => $snapshot['cached'], 'available' => fn ($action) => $action === 'hosts',
    'keys' => ['hosts' => $key], 'errors' => $session->get('errors')])->render();
check(str_contains($html, 'name="preimage_digest" value="'.DomainManager::digest($freshRecords).'"')
    && str_contains($html, 'value="192.0.2.2"') && !str_contains($html, 'value="192.0.2.3"'), 'Ordinary reload presents current complete DNS list and matching digest.');
echo 'UI DNS concurrency: '.($checks - $initialChecks).' passed; native controller/model/Blade, SQLite in memory.'.PHP_EOL;
$successful = true;
