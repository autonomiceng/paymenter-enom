<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';
// Native SQLite and synthetic records only. No installed app, credentials, network or provider writes.
require __DIR__.'/domain-administration.php';
$successful = false;
// The reused native harness removed its disposable app. Restore only small directories and release links.
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) { if (!is_dir($testRoot.'/'.$path)) { mkdir($testRoot.'/'.$path, 0700, true); } }
foreach (['config', 'database', 'routes', 'vendor', 'themes', 'resources', 'public'] as $path) { if (!file_exists($testRoot.'/'.$path)) { symlink($release.'/'.$path, $testRoot.'/'.$path); } }
register_shutdown_function(fn () => (new Illuminate\Filesystem\Filesystem)->deleteDirectory($testRoot));
$checks = 0;
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Enom\\', $repo.'/Enom/', true);
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\HelpController;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Services\DomainAdministration;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainPresentation;

$app['view']->addNamespace('enom', $repo.'/Enom/resources/views');
$help = new HelpController;
$request = Request::create('https://enom-tests.invalid/domains/help');
$app->instance('request', $request);
$request->setUserResolver(fn () => null);
function refuseUiux(callable $action, int $status, string $message): void {
    try { $action(); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) { checkBilling($exception->getStatusCode() === $status, $message); return; }
    throw new RuntimeException($message);
}
refuseUiux(fn () => $help->customer($request), 403, 'Unauthenticated customer Help was accepted.');
$other->forceFill(['role_id' => null])->save(); $other->unsetRelation('role');
$request->setUserResolver(fn () => $other);
DB::enableQueryLog(); DB::flushQueryLog();
foreach (array_keys(HelpController::CUSTOMER_TOPICS) as $topic) {
    $view = $help->customer($request, $topic);
    checkBilling($view->getData()['topic'] === $topic, 'Customer Help topic was lost.');
}
checkBilling(DB::getQueryLog() === [], 'Static customer Help read private records.');
foreach (['../costs', 'costs', 'setup', '<script>', 'arbitrary'] as $topic) {
    refuseUiux(fn () => $help->customer($request, $topic), 404, 'Customer Help topic allowlist was bypassed.');
}
refuseUiux(fn () => $help->admin($request, 'setup'), 403, 'Customer entered admin Help.');
$request->setUserResolver(fn () => $staff);
foreach (array_keys(HelpController::ADMIN_TOPICS) as $topic) {
    checkBilling($help->admin($request, $topic)->getData()['topic'] === $topic, 'Admin Help topic was lost.');
}
refuseUiux(fn () => $help->admin($request, '../setup'), 404, 'Admin Help path traversal was accepted.');

require $repo.'/Enom/routes/web.php';
$helpRoute = $app['router']->getRoutes()->match(Request::create('https://enom-tests.invalid/domains/help/dns', 'GET'));
checkBilling(in_array('auth', $helpRoute->gatherMiddleware(), true) && in_array('web', $helpRoute->gatherMiddleware(), true)
    && in_array(App\Http\Middleware\MustVerfiyEmail::class, $helpRoute->gatherMiddleware(), true), 'Help escaped native auth/session/verified-email middleware.');
checkBilling($helpRoute->getName() === 'enom.help', 'Customer Help GET matched a different route.');
$adminHelpRoute = $app['router']->getRoutes()->match(Request::create('https://enom-tests.invalid/admin/enom/help/costs', 'GET'));
checkBilling($adminHelpRoute->getName() === 'enom.admin.help' && in_array('auth', $adminHelpRoute->gatherMiddleware(), true), 'Admin Help escaped authenticated route protection.');
refuseUiux(fn () => $app['router']->getRoutes()->match(Request::create('https://enom-tests.invalid/domains/help/arbitrary', 'GET')), 404, 'Unlisted customer Help topic matched a route.');
refuseUiux(fn () => $app['router']->getRoutes()->match(Request::create('https://enom-tests.invalid/domains/help/dns', 'POST')), 405, 'Help admitted a mutation method.');

$consumed = $make('uiux-consumed', 0);
$admin->savePolicy($staff, $consumed->id, $policy);
$admin->saveSponsorship($staff, $consumed->id, $sponsorship);
$consumed->refresh()->update(['provider_expires_at' => '2027-12-01']);
$data = $admin->get($staff, $consumed->id);
checkBilling($data['sponsorship']['enabled'] === true && $data['sponsorship']['current_cycle'] === false, 'Consumed one-cycle approval was advertised as current authority.');
checkBilling($data['sponsorship']['base_expires_at'] === '2026-12-01', 'Consumed approval lost its original expiry cycle.');
$nonfinancial = Operation::create(['domain_id' => $consumed->id, 'service_id' => $consumed->service_id, 'operation_key' => 'uiux-management', 'action' => 'nameservers', 'state' => 'succeeded', 'amount' => '0.00', 'currency' => 'USD']);
checkBilling($admin->get($costStaff, $consumed->id)['costs'] === [], 'Management-only requests polluted the financial cost list.');
$financial = Operation::create(['domain_id' => $consumed->id, 'service_id' => $consumed->service_id, 'operation_key' => 'uiux-financial', 'action' => 'renew', 'state' => 'succeeded', 'amount' => '0.00', 'currency' => 'USD',
    'cost' => ['quote' => ['amount' => '8.95', 'currency' => 'USD', 'state' => 'quoted'], 'actual' => ['state' => 'unconfirmed', 'reason' => 'order_bill_amount_missing']]]);
$data = $admin->get($costStaff, $consumed->id);
checkBilling(count($data['costs']) === 1 && $data['costs'][0]['confirmed'] === null, 'An item or quote amount was promoted to a confirmed charge.');
DB::flushQueryLog(); $admin->get($staff, $consumed->id);
checkBilling(!array_filter(DB::getQueryLog(), fn ($query) => preg_match('/select .*"cost"/i', $query['query'])), 'Private costs were queried without the dedicated permission.');

$session = new Illuminate\Session\Store('uiux-cost', new Illuminate\Session\ArraySessionHandler(60)); $session->start();
$request = Request::create('https://enom-tests.invalid/admin/enom/'.$financial->id.'/reconcile', 'POST', ['confirmed' => '1']);
$request->setLaravelSession($session); $app->instance('request', $request); $request->setUserResolver(fn () => $staff);
$request->request->set('_request_key', FormKeys::issue($request, 'staff:'.$financial->id.':reconcile'));
$controller = new AdminController($operations, $admin);
DB::flushQueryLog();
refuseUiux(fn () => $controller->reconcile($request, $financial->id), 403, 'Manage-only staff could reconcile completed-request charge data.');
checkBilling(!array_filter(DB::getQueryLog(), fn ($query) => preg_match('/select \*|select .*"cost"/i', $query['query'])), 'Completed-request cost denial read private columns before checking permission.');

$foreign = Invoice::create(['user_id' => $other->id, 'currency_code' => 'USD', 'status' => 'pending']);
Operation::create(['domain_id' => $consumed->id, 'service_id' => $consumed->service_id, 'invoice_id' => $foreign->id, 'operation_key' => 'uiux-foreign', 'action' => 'register', 'state' => 'awaiting_payment', 'amount' => '15.00', 'currency' => 'USD']);
$snapshot = (new DomainManager($operations))->snapshot($user, $consumed->id);
checkBilling($snapshot['zero_retail'] === true && empty($snapshot['capabilities']['renew']), 'Zero-price customer was offered a renewal invoice.');
checkBilling(!array_filter($snapshot['operations']->all(), fn ($operation) => ($operation['invoice_id'] ?? null) === $foreign->id), 'Customer request DTO exposed a foreign invoice link.');
checkBilling(!preg_match('/8\.95|limit_minor|approved_by|account_fingerprint/', json_encode($snapshot)), 'Customer snapshot leaked private financial data.');
$pending = $make('uiux-pending'); $pending->update(['status' => 'registration_pending']);
$pendingInvoice = Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => '2026-10-20']);
$item = $pendingInvoice->items()->create(['description' => 'Synthetic registration', 'price' => '15.00', 'quantity' => 1,
    'reference_type' => App\Models\Service::class, 'reference_id' => $pending->service_id]);
$pendingRequest = Operation::create(['domain_id' => $pending->id, 'service_id' => $pending->service_id, 'invoice_id' => $pendingInvoice->id,
    'invoice_item_id' => $item->id, 'operation_key' => 'uiux-pending-invoice', 'action' => 'register', 'state' => 'awaiting_payment', 'amount' => '15.00', 'currency' => 'USD']);
DB::flushQueryLog();
$progress = DomainAdministration::progress([$pending])[$pending->id];
checkBilling($progress['label'] === 'Awaiting payment' && $progress['invoice_id'] === $pendingInvoice->id, 'Exact unpaid registration was advertised as already in progress.');
checkBilling(!array_filter(DB::getQueryLog(), fn ($query) => preg_match('/select \*|select .*"(?:amount|cost|price|payload)"/i', $query['query'])), 'Pending-domain display queried private amounts or payloads.');
$pendingInvoice->update(['status' => 'paid']); $pendingRequest->update(['state' => 'queued']);
checkBilling(DomainAdministration::progress([$pending])[$pending->id]['label'] === 'Registration waiting to be sent', 'Paid queued registration was advertised as unpaid or completed.');
$pendingRequest->update(['state' => 'pending']);
checkBilling(DomainAdministration::progress([$pending])[$pending->id]['label'] === 'Registration in progress', 'Paid pending registration lost its distinct provider status.');
$pendingInvoice->update(['status' => 'pending', 'user_id' => $other->id]); $pendingRequest->update(['state' => 'awaiting_payment']);
$progress = DomainAdministration::progress([$pending])[$pending->id];
checkBilling($progress['invoice_id'] === null && $progress['awaiting_payment'] === false, 'A foreign invoice influenced domain status or exposed its link.');
$pendingInvoice->update(['user_id' => $user->id]); $item->update(['reference_id' => $consumed->service_id]);
checkBilling(DomainAdministration::progress([$pending])[$pending->id]['invoice_id'] === null, 'An invoice item for a different service entered the domain display.');
$item->update(['reference_id' => $pending->service_id]); $pendingRequest->update(['service_id' => $consumed->service_id]);
checkBilling(DomainAdministration::progress([$pending])[$pending->id]['invoice_id'] === null, 'A request bound to another service entered the domain display.');
checkBilling(DomainPresentation::state('rejected', 'cancelled_unpaid') !== 'Declined', 'Cancelled unpaid renewal was presented as a provider decline.');
checkBilling(DomainPresentation::moment('2026-10-14T12:00:00+02:00') === 'Oct 14, 2026, 10:00 UTC', 'Timestamp was relabelled UTC without conversion.');
fwrite(STDOUT, "UI/UX authority and Help regressions: {$checks} passed\n");
$successful = true;
