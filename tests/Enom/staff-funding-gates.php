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
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Enom\Admin\Resources\OperationResource\Pages\ListOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;

require_once dirname(__DIR__).'/enom-admin-panel.php';
enomAdminPanel();
$role = App\Models\Role::create(['name' => 'Funding reviewer', 'permissions' => ['admin.enom.manage', 'admin.enom.fund']]);
$staff = App\Models\User::create(['email' => 'funding-reviewer@example.invalid', 'password' => 'synthetic', 'role_id' => $role->id]);
$managerRole = App\Models\Role::create(['name' => 'Funding manager', 'permissions' => ['admin.enom.manage']]);
$manager = App\Models\User::create(['email' => 'funding-manager@example.invalid', 'password' => 'synthetic', 'role_id' => $managerRole->id]);
$fundOnlyRole = App\Models\Role::create(['name' => 'Funding only', 'permissions' => ['admin.enom.fund']]);
$fundOnly = App\Models\User::create(['email' => 'funding-only@example.invalid', 'password' => 'synthetic', 'role_id' => $fundOnlyRole->id]);
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
    verify(!$operations->canFund($target, $staff), 'Funding is available behind an unresolved request.');
    try { $operations->approveFunding($target->id, $staff, 'Reviewed specific funding expense'); verify(false, 'Staff authorized funding behind an unresolved same-domain request.'); }
    catch (InputValidationException) { verify(allRows() == $before, 'Funding refusal changed target/peer rows, invoice, service or queue.'); }
    legacyFundRefused($admin, $staff, $user, $target, 'Unpaid customer invoice behind '.$state);
}
foreach (['awaiting_payment', 'held'] as $eligibleState) {
    [$initial, $target] = fixture('register');
    $target->update(['state' => $eligibleState, 'started_at' => null, 'provider_order_id' => null]);
    $before = allRows();
    verify(!$operations->canFund($target, $staff), 'Funding is available for a linked invoice.');
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
    verify($operations->canFund($target, $staff), 'Authorized staff cannot fund an eligible request.');
    foreach ([null, $user, $manager, $fundOnly] as $actor) {
        verify(!$operations->canFund($target, $actor), 'Funding is available without both staff permissions.');
        if (!$actor) { continue; }
        try { $operations->approveFunding($target->id, $actor, 'Reviewed specific funding expense'); verify(false, 'Funding accepted staff without both permissions.'); }
        catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            verify($exception->getStatusCode() === 404 && allRows() == $before, 'Permission refusal changed finances, history or jobs.');
        }
    }
    foreach ([['state' => 'queued'], ['started_at' => now()], ['amount' => '0.00'], ['amount' => '-1.00'],
        ['safe_code' => 'binding_invalid'], ['base_expires_at' => now()->addYears(2)->format('Y-m-d')]] as $updates) {
        $original = [];
        foreach (array_keys($updates) as $field) { $original[$field] = $target->getRawOriginal($field); }
        $target->update($updates);
        $beforeRefusal = allRows();
        verify(!$operations->canFund($target, $staff), 'Funding is available for an ineligible request: '.json_encode(array_keys($updates)));
        try { $operations->approveFunding($target->id, $staff, 'Reviewed specific funding expense'); verify(false, 'Funding accepted an ineligible request.'); }
        catch (InputValidationException|RuntimeException) { verify(allRows() == $beforeRefusal, 'Funding refusal changed finances, history or jobs.'); }
        $target->update($original);
    }
    foreach (['running', 'pending', 'uncertain'] as $state) {
        $peer->update(['state' => $state]);
        $beforeRefusal = allRows();
        verify(!$operations->canFund($target, $staff), 'Funding is available behind an unresolved peer.');
        try { $operations->approveFunding($target->id, $staff, 'Reviewed specific funding expense'); verify(false, 'Funding accepted an unresolved peer.'); }
        catch (InputValidationException) { verify(allRows() == $beforeRefusal, 'Peer refusal changed finances, history or jobs.'); }
    }
    $peer->update(['state' => 'succeeded']);
    $target->update(['base_expires_at' => $domain->provider_expires_at]);
    verify($operations->canFund($target, $staff), 'An unchanged base expiry prevents funding.');
    $domain->update(['provider_expires_at' => null]);
    verify(!$operations->canFund($target, $staff), 'Funding is available after the provider expiry was removed.');
    $beforeRefusal = allRows();
    try { $operations->approveFunding($target->id, $staff, 'Reviewed specific funding expense'); verify(false, 'Funding accepted a missing provider expiry.'); }
    catch (InputValidationException) { verify(allRows() == $beforeRefusal, 'Missing expiry refusal changed finances, history or jobs.'); }
    $domain->update(['provider_expires_at' => $target->base_expires_at]);
    foreach (['    ', '  abcd  ', '  éééé  ', str_repeat('x', 501)] as $reason) {
        $beforeRefusal = allRows();
        try { $operations->approveFunding($target->id, $staff, $reason); verify(false, 'Funding accepted an invalid reason.'); }
        catch (RuntimeException) { verify(allRows() == $beforeRefusal, 'Reason refusal changed finances, history or jobs.'); }
    }
    $before = allRows();
    $reason = $eligibleState === 'held' ? str_repeat('é', 500) : 'Reviewed specific funding expense';
    $approved = $operations->approveFunding($target->id, $staff, '  '.$reason.'  ');
    verify($approved->funding_reason === $reason, 'Funding did not validate and save the trimmed reason.');
    $after = allRows();
    verify($approved->state === 'queued' && $approved->funding_basis === 'operator_funded' && $approved->authorized_by === $staff->id,
        'Unlinked explicit funding failed its existing gate.');
    foreach (['enom_domains', 'invoices', 'invoice_items', 'invoice_transactions', 'services'] as $table) {
        verify($after[$table] == $before[$table], 'Unlinked funding changed native financial rows.');
    }
    verify(!$operations->canFund($target, $staff), 'Queued funding remains available through a stale model.');
    $beforeDuplicate = allRows();
    try { $operations->approveFunding($target->id, $staff, 'Duplicate funding expense'); }
    catch (RuntimeException) {}
    verify(allRows() == $beforeDuplicate, 'Repeated funding authorization changed history or dispatched another job.');
}
[$domain, $peer] = activeFixture();
$peer->update(['state' => 'succeeded']);
$target = Operation::create(['domain_id' => $domain->id, 'service_id' => $domain->service_id,
    'operation_key' => 'filament-funding', 'action' => 'register', 'state' => 'held',
    'funding_basis' => 'unfunded', 'term' => 1, 'amount' => '15.00', 'currency' => 'USD']);
$fundAction = function ($actor, Operation $record) {
    Illuminate\Support\Facades\Auth::setUser($actor);
    $page = app(ListOperations::class);
    $page->bootedInteractsWithTable();
    $action = $page->getTable()->getAction('fund');
    verify($action !== null, 'Registrar requests have no funding action.');
    return $action->record($record);
};
foreach ([$manager, $fundOnly, $user] as $actor) {
    verify($fundAction($actor, $target)->isHidden(), 'Funding action is visible without both permissions.');
}
foreach ([['state' => 'queued'], ['started_at' => now()], ['amount' => '0.00'],
    ['invoice_id' => $peer->invoice_id], ['safe_code' => 'binding_invalid'],
    ['base_expires_at' => now()->addYears(2)->format('Y-m-d')]] as $updates) {
    $original = [];
    foreach (array_keys($updates) as $field) { $original[$field] = $target->getRawOriginal($field); }
    $target->update($updates);
    verify(!$operations->canFund($target, $staff) && $fundAction($staff, $target)->isHidden(), 'Funding action is visible for an ineligible request.');
    $target->update($original);
}
$peer->update(['state' => 'uncertain']);
verify($fundAction($staff, $target)->isHidden(), 'Funding action is visible behind an unresolved peer.');
$peer->update(['state' => 'succeeded']);
$action = $fundAction($staff, $target);
verify(!$action->isHidden() && $action->getLabel() === 'Fund this request'
    && $action->getIcon() === 'heroicon-o-banknotes' && $action->getColor() === 'warning', 'Eligible funding action has the wrong label, icon or colour.');
verify($action->getModalHeading() === 'Fund this request yourself?'
    && $action->getModalDescription() === 'Your Enom account pays for this registration of '.$domain->domain.' (15.00 USD). The client is not invoiced. Use this only when you have agreed to cover the cost.'
    && $action->getModalSubmitActionLabel() === 'Fund and queue', 'Funding confirmation text does not describe the expense.');
$page = Livewire::actingAs($staff)->test(ListOperations::class)->set('activeTab', 'all');
foreach (['    ', '  abcd  ', '  éééé  ', '  '.str_repeat('x', 501).'  '] as $reason) {
    $before = allRows();
    $page->callTableAction('fund', $target, ['reason' => $reason])->assertHasTableActionErrors(['reason']);
    verify(allRows() == $before, 'Invalid funding reason changed finances, history or jobs.');
    $page->unmountTableAction();
}
$before = allRows();
$page->callTableAction('fund', $target, ['reason' => '  Agreed to cover this registration  '])->assertHasNoTableActionErrors();
$after = allRows();
$funded = $target->fresh();
verify($funded->state === 'queued' && $funded->funding_basis === 'operator_funded'
    && $funded->authorized_by === $staff->id && $funded->funding_reason === 'Agreed to cover this registration',
    'Funding action did not queue the request with its staff authority and trimmed reason.');
verify(count($after['jobs']) === count($before['jobs']) + 1, 'Funding action did not dispatch exactly one job.');
foreach (['enom_domains', 'invoices', 'invoice_items', 'invoice_transactions', 'services'] as $table) {
    verify($after[$table] == $before[$table], 'Funding action changed native financial rows.');
}
$notifications = session()->get('filament.claimed_notifications', []);
verify(collect($notifications)->contains(fn ($notice) => $notice['title'] === 'Request funded and queued.' && $notice['status'] === 'success'), 'Funding action did not send the success notification.');
verify($fundAction($staff, $target)->isHidden(), 'Funding action remains visible after queueing.');
$target->refresh()->update(['state' => 'held', 'funding_basis' => 'unfunded', 'authorized_by' => null, 'funding_reason' => null]);
$page = Livewire::actingAs($staff)->test(ListOperations::class)->set('activeTab', 'all');
$page->callTableAction('fund', $target, ['reason' => '  '.str_repeat('é', 500).'  '])->assertHasNoTableActionErrors();
verify($target->fresh()->state === 'queued' && $target->fresh()->funding_reason === str_repeat('é', 500), 'Funding action rejected 500 trimmed characters.');
// Service refusals must reach staff as danger notifications.
foreach ([InputValidationException::class, RuntimeException::class] as $exceptionClass) {
    $target->refresh()->update(['state' => 'held', 'funding_basis' => 'unfunded', 'authorized_by' => null, 'funding_reason' => null]);
    $app->instance(DomainOperations::class, new class($exceptionClass) extends DomainOperations {
        public function __construct(private string $exceptionClass) {}
        public function canFund(Operation $operation, ?App\Models\User $actor): bool { return true; }
        public function approveFunding(int $operationId, App\Models\User $staff, string $reason): Operation
        {
            if ($this->exceptionClass === InputValidationException::class) {
                throw new InputValidationException(['enom' => 'Funding changed while confirmation was open.']);
            }
            throw new RuntimeException('Funding changed while confirmation was open.');
        }
    });
    $before = allRows();
    $page = Livewire::actingAs($staff)->test(ListOperations::class)->set('activeTab', 'all');
    $page->callTableAction('fund', $target, ['reason' => 'Agreed to cover this registration'])->assertHasNoTableActionErrors();
    $notifications = session()->get('filament.claimed_notifications', []);
    verify(allRows() == $before && collect($notifications)->contains(fn ($notice) => $notice['title'] === 'Funding changed while confirmation was open.' && $notice['status'] === 'danger'),
        'Funding refusal was not shown as a danger notification without changes.');
}
$app->instance(DomainOperations::class, $operations);
verify($providerCalls === 0, 'Staff funding gate performed a provider request.');
echo 'Staff funding gates: '.$checks.' passed; native SQLite, Filament funding action, legacy funding URL redirects only, no provider calls.'.PHP_EOL;
$successful = true;
