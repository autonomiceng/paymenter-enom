<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Independent native held-renewal controller/DTO/queue proof; synthetic SQLite only.
require __DIR__.'/domain-administration.php';
$successful = false; $checks = 0;
// The shared administration setup removes its filesystem after its own checks.
// Native payment notifications still need the pristine public/resources paths.
mkdir($testRoot, 0700, true);
foreach (['storage/framework/views', 'storage/framework/cache', 'storage/logs', 'bootstrap/cache'] as $path) { mkdir($testRoot.'/'.$path, 0700, true); }
foreach (['public', 'resources', 'themes', 'vendor', 'config'] as $path) { symlink($release.'/'.$path, $testRoot.'/'.$path); }
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Enom\\', $repo.'/Enom/', true);
// Native first-user promotion is fixture setup behavior, not customer authority.
$user->forceFill(['role_id' => null])->save(); $user->unsetRelation('role');
$configuration = ['uid' => 'test-account', 'token' => 'offline', 'sandbox' => true, 'operations_enabled' => true, 'reads_enabled' => true];
$providerCommands = [];
$api = new Paymenter\Extensions\Servers\Enom\Api\EnomApi(new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('test-account', 'offline', function ($command) use (&$providerCommands): string {
    $providerCommands[] = $command;
    throw new RuntimeException('Synthetic provider read unavailable.');
}));
$guarded = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations($api, function () use (&$configuration) { return $configuration; });
$recovery = $make('ui-held-recovery'); $admin->savePolicy($staff, $recovery->id, $policy);
$invoice = $billing->invoice($recovery->id, $user);
App\Helpers\ExtensionHelper::addPayment($invoice, 'Stripe', 15, transactionId: 'synthetic-readmission-settlement');
$guarded->acceptPaidInvoice($invoice->fresh());
$operation = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $invoice->id)->sole();
$held = $guarded->execute($operation->id);
checkBilling($held->state === 'held' && !isset($held->policy['dispatch_attempted_at']) && !isset($held->cost['execution']) && !array_intersect(['Extend', 'UpdateExpiredDomains', 'Purchase'], $providerCommands), 'Synthetic original hold performed or sealed a registrar write.');
checkBilling($guarded->canReadmitHeld($held), 'Paid never-sent hold is not offered for staff recovery.');
function recoveryRows(): array {
    $rows = [];
    foreach (['enom_domains', 'enom_operations', 'services', 'invoices', 'invoice_items', 'invoice_transactions', 'jobs'] as $table) { $rows[$table] = json_encode(Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()); }
    return $rows;
}
$cache = sys_get_temp_dir().'/enom-readmission-ui-'.bin2hex(random_bytes(6)); mkdir($cache, 0700); mkdir($cache.'/layouts', 0700); mkdir($cache.'/components', 0700);
file_put_contents($cache.'/layouts/app.blade.php', '<html><body>{{ $slot }}</body></html>');
file_put_contents($cache.'/components/ri-loader-5-fill.blade.php', '<span aria-hidden="true"></span>');
config(['view.paths' => [$cache, $release.'/themes/default/views'], 'view.compiled' => $cache]);
$app['view']->getFinder()->setPaths([$cache, $release.'/themes/default/views']);
$app['view']->addNamespace('enom', $repo.'/Enom/resources/views');
foreach (['enom.help' => '/domains/help/{topic?}', 'enom.admin.help' => '/admin/enom/help/{topic?}', 'enom.admin.index' => '/admin/enom', 'enom.admin.domain' => '/admin/enom/domains/{domain}', 'enom.admin.readmit' => '/admin/enom/{operation}/readmit', 'enom.admin.fund' => '/admin/enom/{operation}/fund', 'enom.admin.reconcile' => '/admin/enom/{operation}/reconcile', 'filament.admin.pages.dashboard' => '/admin'] as $name => $path) { $app['router']->get($path, fn () => null)->name($name); }
$app['router']->getRoutes()->refreshNameLookups();
function recoveryRequest($actor, int $operationId, array $input = []): Illuminate\Http\Request {
    global $app;
    $request = Illuminate\Http\Request::create('http://enom-tests.invalid/admin/enom/'.$operationId.'/readmit', 'POST', $input);
    $session = new Illuminate\Session\Store('readmission-ui', new Illuminate\Session\ArraySessionHandler(60)); $session->start(); $request->setLaravelSession($session);
    $request->request->set('_request_key', Paymenter\Extensions\Servers\Enom\Http\FormKeys::issue($request, 'staff:'.$operationId.':readmit'));
    $app->instance('request', $request); $app->instance('session', $session); $app['url']->setRequest($request); $app['redirect']->setSession($session);
    $request->setUserResolver(fn () => $actor);
    return $request;
}
function refusedRecovery(callable $action, string $message): void {
    $before = recoveryRows();
    try { $action(); throw new LogicException($message); }
    catch (Paymenter\Extensions\Servers\Enom\Services\InputValidationException | Symfony\Component\HttpKernel\Exception\HttpException | RuntimeException $error) { checkBilling(recoveryRows() === $before, $message.' changed persistent data.'); }
}
$controller = new Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController($guarded, $admin);
auth()->setUser($staff);
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag);
try {
    $request = recoveryRequest($staff, $held->id);
    $data = $controller->index($request)->getData();
    checkBilling(($data['readmissionEligible'][$held->id] ?? false) === true, 'Admin DTO omits eligible held recovery.');
    $html = $controller->index($request)->render();
    checkBilling(str_contains($html, 'Resume verified unsent renewal') && str_contains($html, '/'.$held->id.'/readmit') && str_contains($html, 'same invoice and request'), 'Admin recovery form omits same-obligation and unsent-only semantics.');
    $key = $request->input('_request_key'); $before = recoveryRows();
    $controller->readmit($request, $held->id);
    checkBilling(recoveryRows() === $before && $request->session()->get('errors')->has('confirmed') && $request->session()->get('errors')->has('reason') && Paymenter\Extensions\Servers\Enom\Http\FormKeys::verify($request, 'staff:'.$held->id.':readmit') === $key, 'Unconfirmed/empty recovery mutates or consumes the request key.');
    $request = recoveryRequest($staff, $held->id, ['confirmed' => '1', 'reason' => 'Reviewed current expiry and resolved synthetic provider outage', 'epp' => 'private-never-flash']);
    $request->request->set('_request_key', str_repeat('f', 64));
    refusedRecovery(fn () => $controller->readmit($request, $held->id), 'Invalid recovery nonce');
    $request = recoveryRequest($user, $held->id, ['confirmed' => '1', 'reason' => 'Customer cannot approve own registrar recovery']);
    refusedRecovery(fn () => $controller->readmit($request, $held->id), 'Customer recovery permission');
    refusedRecovery(fn () => $guarded->readmitHeld($held->id, $user, 'Customer cannot approve own registrar recovery'), 'Direct customer recovery permission');
    $original = $held->fresh()->getRawOriginal(); $domainRow = $recovery->fresh()->getRawOriginal();
    foreach (['uncertain', 'pending', 'dispatch_sealed', 'execution_evidence', 'wrong_account', 'changed_expiry', 'changed_authority', 'underpaid'] as $case) {
        $candidate = $held->fresh();
        if (in_array($case, ['uncertain', 'pending'], true)) { $candidate->update(['state' => $case]); }
        if ($case === 'dispatch_sealed') { $candidate->update(['policy' => array_replace($candidate->policy, ['dispatch_attempted_at' => now()->toIso8601String()])]); }
        if ($case === 'execution_evidence') { $candidate->update(['cost' => array_replace($candidate->cost ?? [], ['execution' => ['command' => 'Extend']])]); }
        if ($case === 'wrong_account') { $configuration['uid'] = 'other-account'; }
        if ($case === 'changed_expiry') { $recovery->refresh()->update(['provider_expires_at' => '2027-12-01']); }
        if ($case === 'changed_authority') { $recovery->refresh()->update(['authority_generation' => 2]); }
        $transaction = $invoice->transactions()->first();
        if ($case === 'underpaid') { $transaction->update(['amount' => '14.00']); }
        checkBilling(!$guarded->canReadmitHeld($candidate->fresh()), 'Readmission DTO offers '.$case);
        refusedRecovery(fn () => $guarded->readmitHeld($held->id, $staff, 'Reviewed current expiry and resolved synthetic provider outage'), 'Unsafe readmission '.$case);
        if ($case === 'underpaid') {
            $request = recoveryRequest($staff, $held->id, ['confirmed' => '1', 'reason' => 'Reviewed current expiry and resolved synthetic provider outage', 'epp' => 'private-never-flash', 'amount' => '999.00']);
            $key = $request->input('_request_key'); $before = recoveryRows();
            $response = $controller->readmit($request, $held->id);
            checkBilling($response->getStatusCode() === 302 && recoveryRows() === $before && $request->session()->get('errors')->has('enom'), 'Unsettled recovery controller lacks safe refusal or changes financial data.');
            checkBilling($request->session()->get('_old_input') === ['_enom_form' => 'staff:'.$held->id.':readmit', 'reason' => 'Reviewed current expiry and resolved synthetic provider outage'] && Paymenter\Extensions\Servers\Enom\Http\FormKeys::verify($request, 'staff:'.$held->id.':readmit') === $key, 'Failed recovery leaks private/arbitrary input or loses its nonce.');
        }
        $html = $controller->index(recoveryRequest($staff, $held->id))->render();
        $doc = new DOMDocument; @$doc->loadHTML($html); $xpath = new DOMXPath($doc);
        checkBilling($xpath->query('//form[@action="'.route('enom.admin.readmit', $held->id).'"]')->length === 0, 'Unsafe '.$case.' retains a recovery form.');
        Illuminate\Support\Facades\DB::table('enom_operations')->where('id', $held->id)->update($original);
        Illuminate\Support\Facades\DB::table('enom_domains')->where('id', $recovery->id)->update($domainRow);
        $configuration['uid'] = 'test-account'; $transaction->update(['amount' => '15.00']);
    }
    $request = recoveryRequest($staff, $held->id, ['confirmed' => '1', 'reason' => 'Reviewed current expiry and resolved synthetic provider outage', 'epp' => 'never-flash']);
    $before = recoveryRows(); $commandsBefore = $providerCommands; $jobCount = Illuminate\Support\Facades\DB::table('jobs')->count(); $operationCount = Paymenter\Extensions\Servers\Enom\Models\Operation::count();
    $response = $controller->readmit($request, $held->id);
    $after = recoveryRows(); $queued = $held->fresh();
    checkBilling($response->getStatusCode() === 302 && $queued->state === 'queued' && $queued->invoice_id === $invoice->id && Paymenter\Extensions\Servers\Enom\Models\Operation::count() === $operationCount, 'Staff readmission replaced its paid invoice/operation or failed to queue.');
    $review = $queued->policy['readmissions'][0] ?? [];
    checkBilling(($review['staff_id'] ?? null) === $staff->id && ($review['reason'] ?? null) === 'Reviewed current expiry and resolved synthetic provider outage' && !empty($review['at']) && ($review['previous_code'] ?? null) === $held->safe_code, 'Readmission lost its responsible staff, reason, time or original hold code.');
    foreach (['enom_domains', 'services', 'invoices', 'invoice_items', 'invoice_transactions'] as $table) { checkBilling($after[$table] === $before[$table], 'Readmission changed '.$table); }
    checkBilling(Illuminate\Support\Facades\DB::table('jobs')->count() === $jobCount + 1 && $providerCommands === $commandsBefore, 'Readmission issued provider calls or wrong queue count.');
    refusedRecovery(fn () => Paymenter\Extensions\Servers\Enom\Http\FormKeys::verify($request, 'staff:'.$held->id.':readmit'), 'Successful recovery nonce replay');
    refusedRecovery(fn () => $guarded->readmitHeld($held->id, $staff, 'Duplicate request must preserve the obligation'), 'Duplicate readmission');
    $source = file_get_contents($repo.'/Enom/routes/web.php');
    checkBilling((bool) preg_match("~Route::post\('/admin/enom/\\{operation\\}/readmit'.*block\\(10, 10\\)~", $source), 'Readmission is not session-serialized POST.');
    echo 'Independent readmission UI: '.$checks.' passed; paid native hold, same obligation, no provider writes.'.PHP_EOL;
    $successful = true;
} finally { (new Illuminate\Filesystem\Filesystem)->deleteDirectory($cache); (new Illuminate\Filesystem\Filesystem)->deleteDirectory($testRoot); }
