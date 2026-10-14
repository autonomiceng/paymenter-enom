<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Native models/controller/session, isolated SQLite and no provider transport.
require __DIR__.'/request-gates.php';
$successful = false;
$checks = 0;

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;

$role = App\Models\Role::create(['name' => 'Funding reviewer', 'permissions' => ['*']]);
$staff = App\Models\User::create(['email' => 'funding-reviewer@example.invalid', 'password' => 'synthetic', 'role_id' => $role->id]);
$admin = new AdminController($operations);
function fundingRequest($staff, $operation): Request {
    global $app;
    $request = Request::create('http://enom-tests.invalid/admin/enom/'.$operation->id.'/fund', 'POST', [
        'confirmed' => '1', 'reason' => 'Reviewed specific funding expense', 'amount' => (string) $operation->amount,
        'currency' => $operation->currency, 'epp' => 'never-flash-this',
    ]);
    $session = new Store('funding-gate-test', new ArraySessionHandler(60)); $session->start();
    $request->setLaravelSession($session);
    $request->request->set('_request_key', FormKeys::issue($request, 'staff:'.$operation->id.':fund'));
    $app->instance('request', $request); $app->instance('session', $session);
    $app['redirect']->setSession($session); $app['url']->setRequest($request);
    $request->setUserResolver(fn () => $staff);
    return $request;
}
foreach (['running', 'pending', 'uncertain'] as $state) {
    [$domain, $peer] = activeFixture();
    $invoice = $orders->privacy($user, $domain->id, 'purchase', hash('sha256', 'funding-gate-'.$state));
    $target = Operation::where('invoice_id', $invoice->id)->sole();
    $peer->update(['state' => $state]); $before = allRows();
    try { $operations->approveFunding($target->id, $staff, 'Reviewed specific funding expense'); verify(false, 'Staff authorized funding behind an unresolved same-domain request.'); }
    catch (InputValidationException) { verify(allRows() == $before, 'Funding refusal changed target/peer rows, invoice, service or queue.'); }
    $request = fundingRequest($staff, $target); $key = $request->input('_request_key');
    try { $admin->fund($request, $target->id); verify(false, 'Unpaid customer invoice admitted generic funding.'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        verify($exception->getStatusCode() === 403 && allRows() == $before, 'Customer invoice funding gate did not refuse without mutation.');
    }
    verify(!$request->session()->has('_old_input') && FormKeys::verify($request, 'staff:'.$target->id.':fund') === $key, 'Refused customer funding flashed input or consumed its nonce.');
    auth()->setUser($staff);
    $view = $admin->index($request);
    verify(($view->getData()['fundingBlockers'][$target->id] ?? false) === true, 'Staff page did not derive its funding blocker from the backend predicate.');
    $html = $view->with('errors', new Illuminate\Support\ViewErrorBag)->render();
    $document = new DOMDocument; @$document->loadHTML($html); $xpath = new DOMXPath($document);
    verify($xpath->query('//form[@action="'.route('enom.admin.fund', $target->id).'"]')->length === 0
, 'Blocked staff page still offers a funding form or lacks a truthful review notice.');
}
foreach (['awaiting_payment', 'held'] as $eligibleState) {
    [$initial, $target] = fixture('register');
    $target->update(['state' => $eligibleState, 'started_at' => null, 'provider_order_id' => null]);
    $before = allRows();
    $request = fundingRequest($staff, $target);
    try { $admin->fund($request, $target->id); verify(false, 'Linked invoice funding was overridden.'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        verify($exception->getStatusCode() === 403 && allRows() == $before, 'Linked invoice gate changed native finances.');
    }
    try { $operations->approveFunding($target->id, $staff, 'Reviewed specific funding expense'); verify(false, 'Backend bypassed a customer obligation.'); }
    catch (InputValidationException) { verify(allRows() == $before, 'Backend funding refusal changed history.'); }
    $html = $admin->index($request)->with('errors', new Illuminate\Support\ViewErrorBag)->render();
    $document = new DOMDocument; @$document->loadHTML($html); $xpath = new DOMXPath($document);
    verify($xpath->query('//form[@action="'.route('enom.admin.fund', $target->id).'"]')->length === 0, 'Customer invoice exposes generic funding control.');
    // A separate explicitly unlinked fixture still exercises its legacy administrative gate.
    [$domain, $peer] = activeFixture(); $peer->update(['state' => 'succeeded']);
    $target = Operation::create(['domain_id' => $domain->id, 'service_id' => $domain->service_id,
        'operation_key' => 'unlinked-funding-'.$eligibleState, 'action' => 'register', 'state' => $eligibleState,
        'funding_basis' => 'unfunded', 'term' => 1, 'amount' => '15.00', 'currency' => 'USD']);
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
echo 'Staff funding gates: '.$checks.' passed; native SQLite/controller/session, no provider calls.'.PHP_EOL;
$successful = true;
