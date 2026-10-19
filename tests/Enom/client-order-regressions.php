<?php
$enomTestLabel = 'client-order-regressions';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-client-fixture.php';

function text(string $html): string { return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))); }
$owner = client('client-order-regressions@example.com');
$initial = enomCartOrder($owner, 'invoice-number.com');
$pending = $initial['domain'];
$invoice = $initial['invoice'];
$availability['deadline-code.com'] = 'transferable';
$waiting = enomCartOrder($owner, 'deadline-code.com', 'transfer');
App\Helpers\ExtensionHelper::addPayment($waiting['invoice']->id, null, amount: 13, transactionId: 'deadline-code-paid');
// Prepare the native theme and request context used by direct view rendering.
enomHttp($owner, 'GET', '/domains/'.$pending->id);

// Snapshot failures leave a plain native service action and report the failure.
Illuminate\Support\Facades\Auth::setUser($owner);
$reportedActions = [];
app(Illuminate\Contracts\Debug\ExceptionHandler::class)->stopIgnoring(Symfony\Component\HttpKernel\Exception\HttpException::class);
app(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (Symfony\Component\HttpKernel\Exception\HttpException $error) use (&$reportedActions) {
    $reportedActions[] = $error; return false;
});
$moved = clientDomain($owner, ['domain' => 'moved-service.test', 'server_id' => $server->id + 100]);
verify($extension->getActions($moved->service) === [['type' => 'button', 'label' => 'Manage domain', 'url' => route('enom.domains.show', $moved->id)]]
    && count($reportedActions) === 1, 'Snapshot failure lost its plain service action or was not reported.');

// Activity consumes verified invoice numbers from its DTO without issuing a view query.
$manager = app(\Paymenter\Extensions\Servers\Enom\Services\DomainManager::class);
$invoice->update(['number' => 'CLIENT-<42>']);
$dto = $manager->snapshot($owner, $pending->id);
verify($dto['operations']->first()['invoice_number'] === 'CLIENT-<42>', 'Snapshot omitted the verified invoice number.');
Illuminate\Support\Facades\DB::enableQueryLog();
Illuminate\Support\Facades\DB::flushQueryLog();
$activity = view('enom::partials.activity', ['operations' => $dto['operations']])->render();
verify(str_contains($activity, 'Invoice CLIENT-&lt;42&gt;') && Illuminate\Support\Facades\DB::getQueryLog() === [],
    'Activity queried invoices or failed to escape the DTO invoice number.');
Illuminate\Support\Facades\DB::disableQueryLog();

// Render a retained timeout DTO, then the same DTO after its Operation disappears.
$waiting['operation']->update(['state' => 'rejected', 'safe_code' => 'transfer_code_timeout', 'cost' => ['refund' => ['amount' => '13.00', 'currency' => 'USD']]]);
$waiting['domain']->update(['status' => 'transfer_rejected']);
Illuminate\Support\Facades\Auth::setUser($owner);
$request = Illuminate\Http\Request::create('http://enom-tests.invalid/domains/'.$waiting['domain']->id);
$request->setUserResolver(fn () => $owner);
$request->setLaravelSession(app('session.store'));
$viewData = app(\Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::class)->show($request, $waiting['domain']->id)->getData();
$hostileCredit = 'https://enom-tests.invalid/credit?x=" onmouseover="alert(1)';
$viewData['creditsUrl'] = $hostileCredit;
Illuminate\Support\Facades\DB::enableQueryLog();
Illuminate\Support\Facades\DB::flushQueryLog();
$timeoutPage = view('enom::show', $viewData)->render();
$initialReads = collect(Illuminate\Support\Facades\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"enom_operations"."id" = ?') && $q['bindings'] === [$waiting['operation']->id]);
verify($initialReads->count() === 1, 'Transfer status card did not load its initial operation exactly once.');
Illuminate\Support\Facades\DB::disableQueryLog();
foreach (['code_timeout', 'provider_rejected'] as $outcome) {
    $data = $viewData;
    $data['snapshot']['operations'] = collect($data['snapshot']['operations'])->map(fn ($op) => array_replace($op, ['outcome' => $outcome]));
    $html = view('enom::show', $data)->render();
    $creditLinks = enomDocument($html)->query('//a[normalize-space(.)="View account credit"]');
    verify($creditLinks->length === 1 && $creditLinks->item(0)->getAttribute('href') === $hostileCredit
        && enomDocument($html)->query('//*[@onmouseover]')->length === 0, 'Refund credit link missing or unescaped: '.$outcome);
    $data['creditsUrl'] = null;
    verify(!str_contains(view('enom::show', $data)->render(), 'View account credit'), 'Refund showed a credit link without a credit route: '.$outcome);
}
$waiting['operation']->delete();
$stalePage = view('enom::show', $viewData)->render();
$stalePending = $viewData;
$stalePending['snapshot']['status'] = 'transfer_pending';
$stalePending['snapshot']['operations'] = $stalePending['snapshot']['operations']->map(fn ($op) => array_replace($op, ['outcome' => null]));
$stalePendingPage = view('enom::show', $stalePending)->render();
verify(str_contains(text($stalePage), 'by the deadline')
    && enomDocument($stalePendingPage)->query('//div[@class="enom-progress"]/span[3][not(contains(@class, "is-done"))]')->length === 1,
    'Missing initial operation did not leave empty transfer status values.');
$app['auth']->forgetGuards();

// Transfer lookups share the authenticated availability bucket, including the final refused request.
enomResetThrottle($owner);
for ($attempt = 1; $attempt <= 60; $attempt++) {
    $response = $attempt % 2 === 0
        ? enomHttp($owner, 'GET', '/domains/transfer?name=throttle-'.$attempt.'.com')
        : enomHttp($owner, 'POST', '/domains/check', ['domain' => 'throttle-'.$attempt.'.com'], ['json' => true]);
    verify($response->getStatusCode() === 200, 'Shared availability budget refused request '.$attempt.' before 60.');
}
$beforeLimit = count($providerCommands);
verify(enomHttp($owner, 'GET', '/domains/transfer?name=throttle-blocked.test')->getStatusCode() === 429
    && count($providerCommands) === $beforeLimit, 'The 61st shared availability request was not throttled before the registrar check.');
enomResetThrottle($owner);

echo 'Client order regressions: '.$checks.' passed; injected transport and native views.'.PHP_EOL;
$successful = true;
