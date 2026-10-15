<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Native models/session, isolated SQLite and no provider transport. Funding is a service decision; the 0.2
// staff funding URL now only redirects into the Filament admin and must never fund anything.
require __DIR__.'/request-gates.php';
$successful = false;
$checks = 0;

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;

require_once dirname(__DIR__).'/enom-admin-panel.php';
enomAdminPanel();
$role = App\Models\Role::create(['name' => 'Funding reviewer', 'permissions' => ['*']]);
$staff = App\Models\User::create(['email' => 'funding-reviewer@example.invalid', 'password' => 'synthetic', 'role_id' => $role->id]);
$admin = $app->make(AdminController::class);
function fundingRequest($actor, $operation): Request {
    global $app;
    $request = Request::create('http://enom-tests.invalid/admin/enom/'.$operation->id.'/fund', 'POST', [
        'confirmed' => '1', 'reason' => 'Reviewed specific funding expense', 'amount' => (string) $operation->amount,
        'currency' => $operation->currency, 'epp' => 'never-flash-this', '_request_key' => str_repeat('a', 64),
    ]);
    $session = new Store('funding-gate-test', new ArraySessionHandler(60)); $session->start();
    $request->setLaravelSession($session);
    $app->instance('request', $request); $app->instance('session.store', $session);
    $app['redirect']->setSession($session); $app['url']->setRequest($request);
    $request->setUserResolver(fn () => $actor);
    return $request;
}
/** The legacy funding POST redirects to Registrar requests, changes nothing and never flashes input; customers get 403. */
function legacyFundRefused(AdminController $admin, $staff, $customer, Operation $operation, string $message): void {
    global $providerCalls;
    $before = allRows();
    $request = fundingRequest($staff, $operation);
    $response = $admin->fund($request, $operation->id);
    verify($response->getStatusCode() === 302 && $response->getTargetUrl() === OperationResource::getUrl('index'), $message.': legacy funding POST did not redirect to Registrar requests.');
    verify(allRows() == $before && $providerCalls === 0, $message.': legacy funding POST changed finances, history or jobs.');
    verify(!$request->session()->has('_old_input'), $message.': legacy funding POST flashed submitted input.');
    try { $admin->fund(fundingRequest($customer, $operation), $operation->id); verify(false, $message.': customer reached the staff funding URL.'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) { verify($exception->getStatusCode() === 403 && allRows() == $before, $message.': customer refusal was not a clean 403.'); }
}
foreach (['running', 'pending', 'uncertain'] as $state) {
    [$domain, $peer] = activeFixture();
    $invoice = $orders->privacy($user, $domain->id, 'purchase', hash('sha256', 'funding-gate-'.$state));
    $target = Operation::where('invoice_id', $invoice->id)->sole();
    $peer->update(['state' => $state]); $before = allRows();
    try { $operations->approveFunding($target->id, $staff, 'Reviewed specific funding expense'); verify(false, 'Staff authorized funding behind an unresolved same-domain request.'); }
    catch (InputValidationException) { verify(allRows() == $before, 'Funding refusal changed target/peer rows, invoice, service or queue.'); }
    legacyFundRefused($admin, $staff, $user, $target, 'Unpaid customer invoice behind '.$state);
}
foreach (['awaiting_payment', 'held'] as $eligibleState) {
    [$initial, $target] = fixture('register');
    $target->update(['state' => $eligibleState, 'started_at' => null, 'provider_order_id' => null]);
    $before = allRows();
    legacyFundRefused($admin, $staff, $user, $target, 'Linked invoice '.$eligibleState);
    try { $operations->approveFunding($target->id, $staff, 'Reviewed specific funding expense'); verify(false, 'Backend bypassed a customer obligation.'); }
    catch (InputValidationException) { verify(allRows() == $before, 'Backend funding refusal changed history.'); }
    // A separate explicitly unlinked fixture still exercises its legacy administrative gate.
    [$domain, $peer] = activeFixture(); $peer->update(['state' => 'succeeded']);
    $target = Operation::create(['domain_id' => $domain->id, 'service_id' => $domain->service_id,
        'operation_key' => 'unlinked-funding-'.$eligibleState, 'action' => 'register', 'state' => $eligibleState,
        'funding_basis' => 'unfunded', 'term' => 1, 'amount' => '15.00', 'currency' => 'USD']);
    // Even an operation the service would fund is not funded through the old URL.
    legacyFundRefused($admin, $staff, $user, $target, 'Eligible unlinked '.$eligibleState);
    verify($target->fresh()->state === $eligibleState && $target->fresh()->funding_basis === 'unfunded' && $target->fresh()->authorized_by === null, 'Legacy funding URL funded an eligible operation.');
    $before = allRows();
    $approved = $operations->approveFunding($target->id, $staff, 'Reviewed specific funding expense');
    $after = allRows();
    verify($approved->state === 'queued' && $approved->funding_basis === 'operator_funded' && $approved->authorized_by === $staff->id,
        'Unlinked explicit funding failed its existing gate.');
    foreach (['enom_domains', 'invoices', 'invoice_items', 'invoice_transactions', 'services'] as $table) {
        verify($after[$table] == $before[$table], 'Unlinked funding changed native financial rows.');
    }
    $beforeDuplicate = allRows();
    try { $operations->approveFunding($target->id, $staff, 'Duplicate funding expense'); }
    catch (RuntimeException) {}
    verify(allRows() == $beforeDuplicate, 'Repeated funding authorization changed history or dispatched another job.');
}
verify($providerCalls === 0, 'Staff funding gate performed a provider request.');
echo 'Staff funding gates: '.$checks.' passed; native SQLite, legacy funding URL redirects only, no provider calls.'.PHP_EOL;
$successful = true;
