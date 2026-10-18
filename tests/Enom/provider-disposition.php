<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Native models/events, SQLite memory, provider reads injected and writes prohibited.
require dirname(__DIR__).'/enom-managed-fixture.php';
$successful = false; $checks = 0;
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Enom\\', $repo.'/Enom/', true);



$user->forceFill(['role_id' => null])->save(); $user->unsetRelation('role'); auth()->setUser($staff);
$configuration = ['uid' => 'test-account', 'token' => 'offline', 'sandbox' => true, 'reads_enabled' => true, 'operations_enabled' => true];
$commands = []; $response = ['expiry' => '2027-12-01', 'account' => 'test-account', 'anchor' => '456', 'order_domain' => null, 'order_id' => '789', 'order_term' => '1', 'order_status' => 'Closed']; $currentDomain = null;
$api = new Paymenter\Extensions\Servers\Enom\Api\EnomApi(new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('test-account', 'offline', function ($command, $parameters) use (&$commands, &$response, &$currentDomain): string {
    $commands[] = $command;
    $name = isset($parameters['SLD']) ? $parameters['SLD'].'.'.$parameters['TLD'] : $currentDomain->domain;
    $body = match ($command) {
        'GetDomainInfo' => '<GetDomainInfo><domainname domainnameid="'.$response['anchor'].'">'.$name.'</domainname><status><belongs-to>'.$response['account'].'</belongs-to><expiration>'.$response['expiry'].'</expiration><registrationstatus>Registered</registrationstatus><purchase-status>Paid</purchase-status></status></GetDomainInfo>',
        'GetDomainExp' => '<ExpirationDate>'.$response['expiry'].'</ExpirationDate>',
        'GetOrderDetail' => '<Order><OrderID>'.$response['order_id'].'</OrderID><Result>True</Result><OrderDetail><Description>'.($response['order_domain'] ?? $name).'</Description><Status>Successful</Status><OrderStatus>'.$response['order_status'].'</OrderStatus><Quantity>'.$response['order_term'].'</Quantity><ExpiryDatetime>'.$response['expiry'].'</ExpiryDatetime></OrderDetail></Order>',
        default => throw new RuntimeException('Provider review attempted a mutation or unsupported read.'),
    };
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
}, null, true));
$reviewer = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations($api, function () use (&$configuration) { return $configuration; });
function dispositionRows(): array {
    $result = [];
    foreach (['enom_domains', 'enom_operations', 'services', 'invoices', 'invoice_items', 'invoice_transactions', 'jobs', 'service_cancellations'] as $table) { $result[$table] = json_encode(Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()); }
    return $result;
}
function refuseDisposition(callable $call, string $message): void {
    $before = dispositionRows();
    try { $call(); throw new LogicException($message); }
    catch (Paymenter\Extensions\Servers\Enom\Services\InputValidationException | Symfony\Component\HttpKernel\Exception\HttpException | InvalidArgumentException $error) { checkBilling(dispositionRows() === $before, $message.' changed business data.'); }
}
$makeUncertain = function ($name, $known = true) use ($make, $billing, $user) {
    $domain = $make($name);
    $invoice = $billing->invoice($domain->id, $user);
    App\Helpers\ExtensionHelper::addPayment($invoice, 'Stripe', 15, transactionId: 'synthetic-'.$name);
    $operation = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $invoice->id)->sole();
    $operation->update(['state' => 'uncertain', 'provider_order_id' => $known ? '789' : null,
        'policy' => array_replace($operation->policy, ['dispatch_attempted_at' => now()->toIso8601String()]),
        'safe_code' => 'confirmation_lifecycle_changed']);
    $domain->service->update(['status' => 'cancelled']);
    return [$domain->fresh(), $operation->fresh(), $invoice->fresh()];
};
try {
    [$currentDomain, $operation, $invoice] = $makeUncertain('fact-known');
    checkBilling(!$reviewer->funded($operation), 'Cancelled native service retained spending authority.');
    $before = dispositionRows(); $observed = $reviewer->observeProviderFact($operation->id, $staff);
    $after = dispositionRows();
    foreach (['enom_domains', 'services', 'invoices', 'invoice_items', 'invoice_transactions', 'jobs'] as $table) { checkBilling($before[$table] === $after[$table], 'Fact observation changed '.$table); }
    checkBilling($observed->state === 'uncertain' && $observed->policy['provider_fact']['order_completed'] === true && $commands === ['GetDomainInfo', 'GetDomainExp', 'GetOrderDetail'], 'Known-order provider fact weakened evidence or sent a mutation.');
    $fact = $observed->policy['provider_fact'];
    refuseDisposition(fn () => $reviewer->disposeProviderFact($operation->id, $user, $fact['digest'], 'provider_completed', 'manual_review_required', 'Retain invoice and provider facts for financial review'), 'Customer provider disposition');
    refuseDisposition(fn () => $reviewer->disposeProviderFact($operation->id, $staff, str_repeat('f', 64), 'provider_completed', 'manual_review_required', 'Retain invoice and provider facts for financial review'), 'Stale posted fact digest');
    refuseDisposition(fn () => $reviewer->disposeProviderFact($operation->id, $staff, $fact['digest'], 'unattributed_provider_fact', 'manual_review_required', 'Retain invoice and provider facts for financial review'), 'Known order cannot be relabeled unattributed');
    $commandsBefore = $commands; $before = dispositionRows();
    $disposed = $reviewer->disposeProviderFact($operation->id, $staff, $fact['digest'], 'provider_completed', 'manual_review_required', 'Retain invoice and provider facts for financial review');
    $after = dispositionRows();
    foreach (['services', 'invoices', 'invoice_items', 'invoice_transactions', 'jobs'] as $table) { checkBilling($before[$table] === $after[$table], 'Disposition changed '.$table); }
    checkBilling($commands === $commandsBefore && $disposed->state === 'disposed' && $disposed->policy['staff_disposition']['staff_id'] === $staff->id && $disposed->policy['staff_disposition']['financial'] === 'manual_review_required', 'Disposition resends or loses audited responsibility.');
    checkBilling($currentDomain->fresh()->provider_expires_at->format('Y-m-d') === '2026-12-01' && $currentDomain->fresh()->service->status === 'cancelled' && $currentDomain->fresh()->service->expires_at === null && Paymenter\Extensions\Servers\Enom\Services\DomainOperations::writesPaused($currentDomain->fresh()), 'Provider fact advanced confirmed expiry or resurrected native service.');
    $reviewer->toggleRenewalPreference($user, $currentDomain->id, true);
    checkBilling(Paymenter\Extensions\Servers\Enom\Services\DomainOperations::writesPaused($currentDomain->fresh()), 'Renewal preference cleared the disposition hold.');
    refuseDisposition(fn () => $reviewer->enqueueManagement($user, $currentDomain->id, 'lock', ['locked' => true], str_repeat('a', 64)), 'New management bypasses staff disposition hold');
    checkBilling(!$reviewer->canReadmitHeld($disposed) && !$reviewer->funded($disposed), 'Disposition grants readmission or spending authority.');
    $unsafe = $disposed->replicate(); $unsafe->operation_key = 'synthetic-paused-queued'; $unsafe->state = 'queued'; $unsafe->action = 'lock'; $unsafe->save();
    $held = $reviewer->execute($unsafe->id);
    checkBilling($held->state === 'held' && $held->started_at === null && $commands === $commandsBefore, 'Existing queued operation sent after domain disposition hold.');
    $dto = (new Paymenter\Extensions\Servers\Enom\Services\DomainManager($reviewer))->snapshot($user, $currentDomain->id);
    checkBilling($dto['writes_blocked'] && $dto['capabilities'] === [] && !$dto['automation']['enabled'] && !str_contains(json_encode($dto), 'manual_review_required') && !str_contains(json_encode($dto), 'account_fingerprint'), 'Customer hold DTO exposes private disposition or available writes.');

    [$currentDomain, $unknown] = $makeUncertain('fact-unknown', false);
    $observed = $reviewer->observeProviderFact($unknown->id, $staff); $fact = $observed->policy['provider_fact'];
    checkBilling($fact['known_order'] === false && $fact['order_completed'] === false, 'Expiry-only facts attribute an unknown-order renewal.');
    refuseDisposition(fn () => $reviewer->disposeProviderFact($unknown->id, $staff, $fact['digest'], 'provider_completed', 'manual_review_required', 'Provider order remains unknown and unattributed'), 'Unknown order claimed successful renewal');
    $closed = $reviewer->disposeProviderFact($unknown->id, $staff, $fact['digest'], 'unattributed_provider_fact', 'manual_review_required', 'Provider order remains unknown and unattributed');
    checkBilling($closed->state === 'disposed' && $closed->policy['staff_disposition']['outcome'] === 'unattributed_provider_fact' && $closed->confirmed_expires_at === null, 'Unknown-order disposition implies a confirmed renewal.');

    foreach (['order_domain', 'order_term', 'order_status', 'order_id', 'wrong_account', 'wrong_anchor', 'stale_fact', 'changed_account', 'changed_expiry'] as $case) {
        [$currentDomain, $candidate] = $makeUncertain('fact-'.str_replace('_', '-', $case));
        $response = ['expiry' => '2027-12-01', 'account' => 'test-account', 'anchor' => '456', 'order_domain' => null, 'order_id' => '789', 'order_term' => '1', 'order_status' => 'Closed'];
        if ($case === 'wrong_account') { $response['account'] = 'foreign-account'; }
        if ($case === 'wrong_anchor') { $response['anchor'] = '999'; }
        if (in_array($case, ['wrong_account', 'wrong_anchor'], true)) { refuseDisposition(fn () => $reviewer->observeProviderFact($candidate->id, $staff), 'Invalid observed '.$case); continue; }
        if ($case === 'order_domain') { $response['order_domain'] = 'foreign.com'; }
        if ($case === 'order_term') { $response['order_term'] = '2'; }
        if ($case === 'order_status') { $response['order_status'] = 'Open'; }
        if ($case === 'order_id') { $response['order_id'] = '990'; }
        $observed = $reviewer->observeProviderFact($candidate->id, $staff); $fact = $observed->policy['provider_fact'];
        if ($case === 'stale_fact') { Carbon\Carbon::setTestNow(now()->addMinutes(16)); }
        if ($case === 'changed_account') { $configuration['uid'] = 'other-account'; }
        if ($case === 'changed_expiry') { $currentDomain->update(['provider_expires_at' => '2028-12-01']); }
        refuseDisposition(fn () => $reviewer->disposeProviderFact($candidate->id, $staff, $fact['digest'], 'provider_completed', 'manual_review_required', 'Evidence must match the exact original order'), 'Conflicting or stale '.$case.' disposition');
        atBilling('2026-10-13'); $configuration['uid'] = 'test-account';
    }

    // Native cancelled invoices accept payment and become paid; no retired renewal is requeued.
    $late = $make('late-retired');
    $retiredInvoice = $billing->invoice($late->id, $user); $retired = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $retiredInvoice->id)->sole();
    $reviewer->toggleRenewalPreference($user, $late->id, false);
    checkBilling($retiredInvoice->fresh()->status === 'cancelled' && $retired->fresh()->safe_code === 'renewal_cancelled_unpaid', 'Synthetic invoice retirement did not preserve its explicit code.');
    $serviceBefore = $late->fresh()->service->getRawOriginal(); $domainBefore = $late->fresh()->getRawOriginal(); $jobsBefore = Illuminate\Support\Facades\DB::table('jobs')->count();
    App\Helpers\ExtensionHelper::addPayment($retiredInvoice, 'Stripe', 15, transactionId: 'synthetic-late-retired-payment');
    checkBilling($retiredInvoice->fresh()->status === 'paid' && $retiredInvoice->fresh()->transactions()->count() === 1 && (float) $retiredInvoice->fresh()->remaining === 0.0, 'Native late cancelled-invoice payment behavior changed.');
    checkBilling($reviewer->acceptPaidInvoice($retiredInvoice->fresh()) === [] && $retired->fresh()->state === 'rejected' && $retired->fresh()->started_at === null, 'Late payment revived a retired renewal.');
    checkBilling($late->fresh()->service->getRawOriginal() === $serviceBefore && $late->fresh()->getRawOriginal() === $domainBefore, 'Unreferenced retired payment changed domain/service lifecycle.');
    $report = $reviewer->retiredInvoicePayments();
    checkBilling(count($report) === 1 && $report[0]['invoice_id'] === $retiredInvoice->id && $report[0]['invoice_status'] === 'paid' && $report[0]['transaction_count'] === 1, 'Live staff report missed native late payment.');
    $before = dispositionRows(); checkBilling($reviewer->reportRetiredInvoicePayments() === 1, 'Daily report did not persist review activity.'); $after = dispositionRows();
    foreach (['enom_domains', 'services', 'invoices', 'invoice_items', 'invoice_transactions', 'jobs'] as $table) { checkBilling($before[$table] === $after[$table], 'Daily report changed '.$table); }
    checkBilling(isset($retired->fresh()->policy['retired_payment_review']), 'Daily late-payment marker is absent.');
    $before = dispositionRows(); $reviewer->reportRetiredInvoicePayments(); checkBilling(dispositionRows() === $before, 'Daily unchanged report is not idempotent.');

    $safe = Paymenter\Extensions\Servers\Enom\Services\DomainAdministration::customer($currentDomain->fresh());
    checkBilling(!str_contains(json_encode($safe), 'internal-cost-marker') && !str_contains(json_encode($safe), 'account_fingerprint'), 'Customer projection leaked private evidence.');
    $foreign = App\Models\Invoice::create(['user_id' => $other->id, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => '2026-11-24']);
    $foreignItem = $foreign->items()->create(['description' => 'Foreign invoice', 'price' => 15, 'quantity' => 1]);
    $wrong = $retired->replicate(); $wrong->operation_key = 'foreign-owner-invoice'; $wrong->invoice_id = $foreign->id; $wrong->invoice_item_id = $foreignItem->id; $wrong->save();
    $safe = Paymenter\Extensions\Servers\Enom\Services\DomainAdministration::customer($late->fresh());
    checkBilling(!in_array($foreign->id, array_column($safe['invoices'], 'id'), true), 'Customer projection linked a foreign-owner invoice.');

    // Registration cost polling is bounded independently of financial fulfillment.
    $response = ['expiry' => '2027-12-01', 'account' => 'test-account', 'anchor' => '456', 'order_domain' => null, 'order_id' => '789', 'order_term' => '1', 'order_status' => 'Closed'];
    $currentDomain = $make('cost-limit');
    $costOperation = Paymenter\Extensions\Servers\Enom\Models\Operation::create(['domain_id' => $currentDomain->id, 'service_id' => $currentDomain->service_id, 'operation_key' => 'synthetic-cost-limit',
        'action' => 'register', 'state' => 'succeeded', 'provider_order_id' => '789', 'amount' => '15.00', 'currency' => 'USD', 'term' => 1,
        'policy' => ['account_fingerprint' => hash('sha256', 'test:test-account')], 'cost' => ['execution' => ['command' => 'Purchase', 'status' => 'success', 'orderid' => '789']]]);
    $beforeCalls = count($commands); $before = dispositionRows();
    for ($i = 0; $i < 8; $i++) { $reviewer->reconcile($costOperation->id); }
    checkBilling(count($commands) - $beforeCalls === 5 && $costOperation->fresh()->cost['actual_attempts'] === 5 && $costOperation->fresh()->cost['actual']['reason'] === 'cost_poll_limit_reached', 'Registration cost polling exceeded its persisted attempt cap.');
    $after = dispositionRows(); foreach (['enom_domains', 'services', 'invoices', 'invoice_items', 'invoice_transactions', 'jobs'] as $table) { checkBilling($before[$table] === $after[$table], 'Cost polling changed '.$table); }
    checkBilling($costOperation->fresh()->state === 'succeeded' && !array_intersect(['Extend', 'Purchase', 'UpdateExpiredDomains', 'SetRegLock'], $commands), 'Staff review/report/cost path performed a registrar mutation.');

    require_once dirname(__DIR__).'/enom-admin-panel.php';
    enomAdminPanel();
    [$currentDomain, $uiOperation] = $makeUncertain('fact-ui', false);
    $observedUi = $reviewer->observeProviderFact($uiOperation->id, $staff);
    $uiFact = $observedUi->policy['provider_fact'];
    // The renewal-review controls now live on Registrar requests: a fresh fact offers "Record outcome", which still needs staff.
    $commandsBefore = $commands;
    $closed = $reviewer->disposeProviderFact($uiOperation->id, $staff, $uiFact['digest'], 'unattributed_provider_fact', 'manual_review_required', 'Retain native financial review for the unattributed provider outcome');
    checkBilling($closed->state === 'disposed' && $commands === $commandsBefore && Paymenter\Extensions\Servers\Enom\Services\DomainOperations::writesPaused($currentDomain->fresh()), 'Confirmed disposition does not close review safely.');
    $scheduleSource = file_get_contents($repo.'/Enom/Enom.php');
    checkBilling(str_contains($scheduleSource, "name('enom-retired-invoice-payments')->dailyAt('00:15')"), 'Native late-payment report is not registered daily.');
    echo 'Provider disposition and native late payment: '.$checks.' passed; isolated native SQLite, provider reads injected only.'.PHP_EOL;
    $successful = true;
} finally { (new Illuminate\Filesystem\Filesystem)->deleteDirectory($testRoot); }
