<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Native models/events, SQLite memory, provider reads injected and writes prohibited.
require __DIR__.'/domain-administration.php';
$successful = false; $checks = 0;
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Enom\\', $repo.'/Enom/', true);
mkdir($testRoot, 0700, true);
foreach (['storage/framework/views', 'storage/framework/cache', 'storage/logs', 'bootstrap/cache'] as $path) { mkdir($testRoot.'/'.$path, 0700, true); }
foreach (['public', 'resources', 'themes', 'vendor', 'config'] as $path) { symlink($release.'/'.$path, $testRoot.'/'.$path); }
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
$makeUncertain = function ($name, $known = true) use ($make, $admin, $staff, $policy, $billing, $user) {
    $domain = $make($name); $admin->savePolicy($staff, $domain->id, $policy);
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
    refuseDisposition(fn () => $admin->savePolicy($staff, $currentDomain->id, $policy), 'Policy toggle bypasses staff disposition hold');
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
    $late = $make('late-retired'); $admin->savePolicy($staff, $late->id, $policy);
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

    // Registration cost polling is bounded independently of financial fulfillment.
    $response = ['expiry' => '2027-12-01', 'account' => 'test-account', 'anchor' => '456', 'order_domain' => null, 'order_id' => '789', 'order_term' => '1', 'order_status' => 'Closed'];
    $currentDomain = $make('cost-limit'); $admin->savePolicy($staff, $currentDomain->id, $policy);
    $costOperation = Paymenter\Extensions\Servers\Enom\Models\Operation::create(['domain_id' => $currentDomain->id, 'service_id' => $currentDomain->service_id, 'operation_key' => 'synthetic-cost-limit',
        'action' => 'register', 'state' => 'succeeded', 'provider_order_id' => '789', 'amount' => '15.00', 'currency' => 'USD', 'term' => 1,
        'policy' => ['account_fingerprint' => hash('sha256', 'sandbox:test-account')], 'cost' => ['execution' => ['command' => 'Purchase', 'status' => 'success', 'orderid' => '789']]]);
    $beforeCalls = count($commands); $before = dispositionRows();
    for ($i = 0; $i < 8; $i++) { $reviewer->reconcile($costOperation->id); }
    checkBilling(count($commands) - $beforeCalls === 5 && $costOperation->fresh()->cost['actual_attempts'] === 5 && $costOperation->fresh()->cost['actual']['reason'] === 'cost_poll_limit_reached', 'Registration cost polling exceeded its persisted attempt cap.');
    $after = dispositionRows(); foreach (['enom_domains', 'services', 'invoices', 'invoice_items', 'invoice_transactions', 'jobs'] as $table) { checkBilling($before[$table] === $after[$table], 'Cost polling changed '.$table); }
    $costReview = $admin->get($costStaff, $currentDomain->id);
    checkBilling($costReview['costs'][0]['poll_attempts'] === 5 && $costReview['costs'][0]['poll_reason'] === 'cost_poll_limit_reached', 'Cost-authorized staff cannot see the bounded polling review reason.');
    checkBilling($costOperation->fresh()->state === 'succeeded' && !array_intersect(['Extend', 'Purchase', 'UpdateExpiredDomains', 'SetRegLock'], $commands), 'Staff review/report/cost path performed a registrar mutation.');

    // Real request/session/controller/Blade coverage for the two-step staff action.
    $cache = $testRoot.'/ui-review'; mkdir($cache, 0700); mkdir($cache.'/layouts', 0700); mkdir($cache.'/components', 0700);
    file_put_contents($cache.'/layouts/app.blade.php', '<html><body>{{ $slot }}</body></html>');
    file_put_contents($cache.'/components/ri-loader-5-fill.blade.php', '<span aria-hidden="true"></span>');
    config(['view.paths' => [$cache, $release.'/themes/default/views']]);
    $app['view']->getFinder()->setPaths([$cache, $release.'/themes/default/views']);
    $app['view']->addNamespace('enom', $repo.'/Enom/resources/views');
    foreach (['enom.help' => '/domains/help/{topic?}', 'enom.admin.help' => '/admin/enom/help/{topic?}', 'enom.admin.index' => '/admin/enom', 'enom.admin.domain' => '/admin/enom/domains/{domain}', 'enom.admin.fund' => '/admin/enom/{operation}/fund', 'enom.admin.reconcile' => '/admin/enom/{operation}/reconcile', 'enom.admin.readmit' => '/admin/enom/{operation}/readmit', 'enom.admin.provider-fact' => '/admin/enom/{operation}/provider-fact', 'enom.admin.disposition' => '/admin/enom/{operation}/disposition', 'invoices.show' => '/invoices/{invoice}', 'filament.admin.pages.dashboard' => '/admin'] as $name => $path) { $app['router']->get($path, fn () => null)->name($name); }
    $app['router']->getRoutes()->refreshNameLookups();
    $app['view']->share('errors', new Illuminate\Support\ViewErrorBag);
    $controller = new Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController($reviewer, $admin);
    $requestFor = function ($actor, $operation, $action, $input = []) use ($app) {
        $request = Illuminate\Http\Request::create('http://enom-tests.invalid/admin/enom/'.$operation->id.'/'.$action, 'POST', $input);
        $session = new Illuminate\Session\Store('provider-fact-ui', new Illuminate\Session\ArraySessionHandler(60)); $session->start(); $request->setLaravelSession($session);
        $request->request->set('_request_key', Paymenter\Extensions\Servers\Enom\Http\FormKeys::issue($request, 'staff:'.$operation->id.':'.$action));
        $app->instance('request', $request); $app->instance('session', $session); $app['url']->setRequest($request); $app['redirect']->setSession($session); $request->setUserResolver(fn () => $actor);
        return $request;
    };
    [$currentDomain, $uiOperation] = $makeUncertain('fact-ui', false);
    $request = $requestFor($staff, $uiOperation, 'provider-fact'); $before = dispositionRows();
    $controller->providerFact($request, $uiOperation->id);
    checkBilling(dispositionRows() === $before && $request->session()->get('errors')->has('confirmed'), 'Unconfirmed provider observation changes data.');
    $request = $requestFor($user, $uiOperation, 'provider-fact', ['confirmed' => '1']);
    refuseDisposition(fn () => $controller->providerFact($request, $uiOperation->id), 'Customer provider-fact controller');
    $request = $requestFor($staff, $uiOperation, 'provider-fact', ['confirmed' => '1']);
    $responseObject = $controller->providerFact($request, $uiOperation->id);
    checkBilling($responseObject->getStatusCode() === 302 && isset($uiOperation->fresh()->policy['provider_fact']), 'Confirmed staff observation did not record a provider fact.');
    try { Paymenter\Extensions\Servers\Enom\Http\FormKeys::verify($request, 'staff:'.$uiOperation->id.':provider-fact'); throw new LogicException('Observation nonce reused.'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { checkBilling($error->getStatusCode() === 419, 'Observation did not consume its nonce.'); }
    $fact = $uiOperation->fresh()->policy['provider_fact'];
    $request = $requestFor($staff, $uiOperation, 'disposition', ['confirmed' => '1', 'fact_digest' => str_repeat('f', 64), 'outcome' => 'unattributed_provider_fact', 'financial' => 'manual_review_required', 'reason' => 'Retain native financial review for the unattributed provider outcome', 'epp' => 'never-flash', 'cost' => 'internal-secret']);
    $before = dispositionRows(); $key = $request->input('_request_key'); $controller->disposition($request, $uiOperation->id);
    checkBilling(dispositionRows() === $before && $request->session()->get('errors')->has('enom') && !$request->session()->has('_old_input.epp') && !$request->session()->has('_old_input.cost') && !$request->session()->has('_old_input.fact_digest') && Paymenter\Extensions\Servers\Enom\Http\FormKeys::verify($request, 'staff:'.$uiOperation->id.':disposition') === $key, 'Failed disposition changes data, leaks input or consumes its nonce.');
    $html = $controller->index($request)->with('errors', $request->session()->get('errors'))->render();
    checkBilling(str_contains($html, 'Record current provider facts') && str_contains($html, 'Record disposition and pause domain') && str_contains($html, 'Manual financial review required') && str_contains($html, '/invoices/'.$retiredInvoice->id), 'Staff page omits provider disposition or native late-payment review links.');
    $document = new DOMDocument; @$document->loadHTML($html); $xpath = new DOMXPath($document);
    checkBilling(!str_contains($html, hash('sha256', 'sandbox:test-account')) && !str_contains($html, 'internal-cost-marker') && $xpath->query('//*[@aria-invalid="true"]')->length === 0, 'Overview leaks private provider/cost identity or unrelated field errors.');
    $request = $requestFor($staff, $uiOperation, 'disposition', ['confirmed' => '1', 'fact_digest' => $fact['digest'], 'outcome' => 'unattributed_provider_fact', 'financial' => 'manual_review_required', 'reason' => 'Retain native financial review for the unattributed provider outcome']);
    $commandsBefore = $commands; $responseObject = $controller->disposition($request, $uiOperation->id);
    checkBilling($responseObject->getStatusCode() === 302 && $uiOperation->fresh()->state === 'disposed' && $commandsBefore === $commands, 'Confirmed disposition does not close review safely.');
    try { Paymenter\Extensions\Servers\Enom\Http\FormKeys::verify($request, 'staff:'.$uiOperation->id.':disposition'); throw new LogicException('Disposition nonce reused.'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $error) { checkBilling($error->getStatusCode() === 419, 'Disposition did not consume its nonce.'); }
    $html = $controller->index($request)->with('errors', new Illuminate\Support\ViewErrorBag)->render();
    checkBilling(str_contains($html, 'Closed by support') && str_contains($html, 'Staff provider disposition: unattributed provider fact'), 'Disposed outcome implies registrar financial fulfillment or is hidden.');
    $routesSource = file_get_contents($repo.'/Enom/routes/web.php');
    foreach (['provider-fact', 'disposition'] as $action) { checkBilling((bool) preg_match("~Route::post\('/admin/enom/\\{operation\\}/".$action."'.*block\\(10, 10\\)~", $routesSource), 'Staff '.$action.' route is not session-serialized POST.'); }
    $scheduleSource = file_get_contents($repo.'/Enom/Enom.php');
    checkBilling(str_contains($scheduleSource, "name('enom-retired-invoice-payments')->dailyAt('00:15')"), 'Native late-payment report is not registered daily.');
    echo 'Provider disposition and native late payment: '.$checks.' passed; isolated native SQLite, provider reads injected only.'.PHP_EOL;
    $successful = true;
} finally { (new Illuminate\Filesystem\Filesystem)->deleteDirectory($testRoot); }
