<?php
$enomTestLabel = 'transfer-codes';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-cart-order.php';

use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Paymenter\Extensions\Servers\Enom\Services\Rdap;
use Paymenter\Extensions\Servers\Enom\Services\Refunds;
use Paymenter\Extensions\Servers\Enom\Services\TransferOrders;

// Core invoice-created mail is unrelated here; the native flag avoids repeated shutdown mail in one test process.
Invoice::creating(function (Invoice $invoice) { $invoice->send_create_email = false; });
Carbon::setTestNow('2026-10-17 12:00:00'); CarbonImmutable::setTestNow(Carbon::now());
TransferOrders::boot();
app('router')->getRoutes()->refreshNameLookups();
$owner = User::create(['email' => 'owner@example.invalid', 'password' => 'synthetic', 'email_verified_at' => now()]);
$other = User::create(['email' => 'other@example.invalid', 'password' => 'synthetic', 'email_verified_at' => now()]);
$server = App\Models\Server::create(['name' => 'Transfer tests', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'offline-account', 'token' => 'offline-token', 'environment' => 'test', 'operations_enabled' => '1'] as $key => $value) {
    $server->settings()->create(['key' => $key, 'value' => $value]);
}
enomCartProfile($owner);
enomTestTld(['server_id' => $server->id, 'tld' => 'com']);
$commands = [];
$engine = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(new Paymenter\Extensions\Servers\Enom\Api\EnomApi(new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('offline-account', 'offline-token', function ($command, $params) use (&$commands) {
    $commands[] = $command;
    $name = ($params['SLD'] ?? '').'.'.($params['TLD'] ?? '');
    if ($command === 'Check') { return enomXml($command, '<Domains><Domain><Name>'.$name.'</Name><RRPCode>211</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Properties><Transferable>true</Transferable></Properties></Domain></Domains>'); }
    throw new RuntimeException('Unexpected registrar request '.$command);
})), fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'environment' => 'test', 'operations_enabled' => true]);
app()->instance(Paymenter\Extensions\Servers\Enom\Services\DomainOperations::class, $engine);
$transfers = new TransferOrders(new Rdap(fn () => throw new RuntimeException('offline')));
app()->instance(TransferOrders::class, $transfers);
$counter = 0;
/** Real cart identity, with payment evidence varied for timestamp/recovery cases. */
function transferFixture(bool $paid = true, ?string $paidAt = null): Operation {
    global $counter, $owner;
    $order = enomCartOrder($owner, 'transfer'.(++$counter).'.com', 'transfer');
    $operation = $order['operation'];
    if ($paidAt) { $operation->update(['policy' => array_replace($operation->policy, ['paid_at' => $paidAt])]); }
    if ($paid) {
        App\Models\InvoiceTransaction::withoutEvents(fn () => $order['invoice']->transactions()->create(['amount' => '13.00', 'status' => InvoiceTransactionStatus::Succeeded, 'transaction_id' => 'transfer-'.$counter]));
        DB::table('invoices')->where('id', $order['invoice']->id)->update(['status' => Invoice::STATUS_PAID]);
    }
    return $operation->fresh();
}
function codeFailure(Operation $operation, string $code): void {
    global $owner, $transfers;
    refuses(fn () => $transfers->submitCode($owner, $operation->domain_id, $code), '', 'Unsafe code accepted.', [InputValidationException::class]);
}
// The shared lock order is user, domain, operation, including when SQLite omits FOR UPDATE.
$lockQueries = [];
DB::listen(function ($query) use (&$lockQueries) { if (str_starts_with(strtolower($query->sql), 'select')) { $lockQueries[] = $query->sql; } });
$operation = transferFixture();
$transfers->paymentAccepted($operation);
$lockSql = implode("\n", $lockQueries);
verify(preg_match('/from "users".*from "enom_domains".*from "enom_operations"/s', $lockSql) === 1, 'Payment locks do not follow user, domain, operation order.');
verify($operation->fresh()->state === 'awaiting_code' && $operation->fresh()->policy['paid_at'] === '2026-10-17T12:00:00+00:00', 'Paid no-code transfer did not wait with payment timestamp.');
$firstPaid = $operation->fresh()->policy['paid_at'];
Carbon::setTestNow('2026-10-18 12:00:00'); CarbonImmutable::setTestNow(Carbon::now());
$transfers->paymentAccepted($operation);
verify($operation->fresh()->policy['paid_at'] === $firstPaid, 'Replayed payment reset the deadline.');
refuses(fn () => $transfers->assertReady($operation->fresh()), 'Enter your transfer code', 'Missing code passed dispatch gate.', [InputValidationException::class]);
foreach (['', str_repeat('x', 513), "one\ntwo", "one\0two", "one\x7ftwo"] as $code) { codeFailure($operation, $code); }
// Rollback isolates code-versus-timeout ordering; committed resume is exercised below.
DB::beginTransaction();
$secret = ' Case-Sensitive:CODE  ';
$saved = $transfers->submitCode($owner, $operation->domain_id, $secret);
verify($saved->payload['epp'] === $secret && !$transfers->needsCode($saved), 'Meaningful code bytes changed.');
$transfers->submitCode($owner, $operation->domain_id, $secret);
verify(Operation::where('domain_id', $operation->domain_id)->where('action', 'transfer')->count() === 1, 'Repeated code entry duplicated the transfer.');
$raw = DB::table('enom_operations')->where('id', $saved->id)->first();
verify(!str_contains($raw->payload, $secret) && !str_contains($raw->policy, 'paid_at'), 'Code or timestamp stored unencrypted.');
verify(!str_contains(json_encode($saved), $secret) && !DB::table('properties')->where('value', $secret)->exists(), 'Code leaked through serialization or service properties.');
verify(app(Refunds::class)->rejectUnsentTransfer($saved->id) === null, 'Code entry lost the timeout race.');
$transfers->assertReady($saved);
DB::rollBack();
$unpaid = transferFixture(false);
DB::beginTransaction();
$saved = $transfers->submitCode($owner, $unpaid->domain_id, 'before-payment');
verify($saved->state === 'awaiting_payment' && !isset($saved->policy['paid_at']), 'Code before payment started a paid clock or advanced the state.');
DB::rollBack();
refuses(fn () => $transfers->submitCode($other, $operation->domain_id, 'foreign'), '', 'Another owner entered a code.', [Illuminate\Database\Eloquent\ModelNotFoundException::class]);
foreach ([['policy' => ['dispatch_attempted_at' => now()->toIso8601String()]],
    ['provider_order_id' => 'synthetic-order'], ['cost' => ['execution' => null]], ['state' => 'running'], ['state' => 'pending'], ['state' => 'uncertain'], ['state' => 'rejected']] as $changes) {
    $candidate = transferFixture(); $candidate->update($changes);
    codeFailure($candidate->fresh(), 'replacement');
    verify(app(Refunds::class)->rejectUnsentTransfer($candidate->id) === null, 'Sent or unresolved transfer was refunded.');
}
$late = transferFixture(true, '2026-09-18T12:00:00+00:00');
codeFailure($late, 'too-late');
verify($late->fresh()->payload['epp'] === null, 'Late code persisted.');
$refunded = app(Refunds::class)->rejectUnsentTransfer($late->id);
verify($refunded === ['amount' => '13.00', 'currency' => 'USD'] && $late->fresh()->payload === null && $late->fresh()->safe_code === 'transfer_code_timeout', 'Day-30 timeout did not refund and purge exactly this transfer.');
codeFailure($late->fresh(), 'after-refund');
verify(app(Refunds::class)->rejectUnsentTransfer($late->id) === null, 'Timeout credit repeated.');
$early = transferFixture(true, '2026-09-18T12:00:01+00:00');
verify(app(Refunds::class)->rejectUnsentTransfer($early->id) === null, 'Refund happened a second early.');
// Unchanged successful transactions establish the original payment time, even after a missed callback.
$recovery = transferFixture();
$transfers->paymentAccepted($recovery);
verify($recovery->fresh()->policy['paid_at'] === now()->toIso8601String(), 'Missed payment callback did not recover transaction evidence.');
$reviewRole = App\Models\Role::create(['name' => 'Transfer review', 'permissions' => ['admin.enom.manage']]);
$reviewer = User::create(['email' => 'review@example.invalid', 'password' => 'synthetic', 'role_id' => $reviewRole->id]);
$ambiguous = transferFixture();
DB::table('invoice_transactions')->where('invoice_id', $ambiguous->invoice_id)->update(['updated_at' => now()->subMinute()]);
$transfers->paymentAccepted($ambiguous);
verify($ambiguous->fresh()->safe_code === 'transfer_payment_time_unknown' && !isset($ambiguous->fresh()->policy['paid_at']), 'Ambiguous payment time reset to now.');
$transfers->paymentAccepted($ambiguous);
verify(App\Models\Notification::where('user_id', $reviewer->id)->count() === 1, 'Unknown payment time did not alert staff exactly once.');
DB::table('audits')->insert(['event' => 'updated', 'auditable_type' => Invoice::class, 'auditable_id' => $ambiguous->invoice_id,
    'old_values' => json_encode(['status' => 'pending']), 'new_values' => json_encode(['status' => 'paid']), 'created_at' => now(), 'updated_at' => now()]);
$transfers->paymentAccepted($ambiguous);
verify(isset($ambiguous->fresh()->policy['paid_at']), 'Paid audit did not recover timestamp.');
// Native payment, including a code entered before paying, records the transition once.
$nativePaid = transferFixture(false);
$nativePaid->update(['payload' => ['epp' => 'prepaid-CODE']]);
App\Helpers\ExtensionHelper::addPayment($nativePaid->invoice_id, null, amount: 13, transactionId: 'native-code-payment');
verify(isset($nativePaid->fresh()->policy['paid_at']) && !$transfers->needsCode($nativePaid->fresh()), 'Native paid transition missed a code already on file.');
$nativeMissing = transferFixture(false);
App\Helpers\ExtensionHelper::addPayment($nativeMissing->invoice_id, null, amount: 13, transactionId: 'native-missing-code-payment');
verify($nativeMissing->fresh()->state === 'awaiting_code' && isset($nativeMissing->fresh()->policy['paid_at']), 'Native payment queued a transfer without a code.');

// The allowlist is a setting intersected with supported code-based, one-year endings.
foreach (['com' => true, 'net' => true, 'org' => true, 'io' => false, 'uk' => false, 'ca' => false, 'eu' => false, 'ai' => false, 'de' => false] as $ending => $allowed) {
    $tld = new Tld(['server_id' => $server->id, 'tld' => $ending]);
    verify($transfers->allowed($tld) === $allowed, 'Default allowlist differs for '.$ending);
}
$server->settings()->create(['key' => 'transfer_allowlist', 'value' => 'com,io,co.uk,ca,eu,ai,de', 'type' => 'string']);
foreach (['com' => true, 'net' => false, 'io' => true, 'co.uk' => false, 'ca' => false, 'eu' => false, 'ai' => false, 'de' => false] as $ending => $allowed) {
    verify($transfers->allowed(new Tld(['server_id' => $server->id, 'tld' => $ending])) === $allowed, 'Configured allowlist bypassed support rules for '.$ending);
}
$com = Tld::where('server_id', $server->id)->where('tld', 'com')->firstOrFail();
verify(!$transfers->precheck($com, 'example.net')['allowed'] && !$transfers->precheck($com, 'sub.example.com')['allowed']
    && !$transfers->precheck($com, 'xn--example.com')['allowed'], 'Lookup accepted a mismatched or unsupported name.');
$server->settings()->create(['key' => 'transfer_creation_lock_days', 'value' => '366']);
refuses(fn () => $transfers->precheck($com, 'example.com'), 'Transfer rules need attention', 'Invalid lock window was used.', [InputValidationException::class]);
$server->settings()->where('key', 'transfer_creation_lock_days')->delete();

// Route security uses the native HTTP kernel, CSRF, owner check and session-bound form key.
$httpCandidate = transferFixture(false);
$url = '/domains/'.$httpCandidate->domain_id.'/transfer-code';
$bad = enomHttp($owner, 'POST', $url, ['transfer_code' => 'NEVER-FLASH-THIS'], ['csrf' => true, 'token' => false]);
verify($bad->getStatusCode() === 419, 'Missing CSRF was accepted.');
verify(!str_contains($bad->getContent(), 'NEVER-FLASH-THIS'), 'CSRF error reflected the code.');
$bad = enomHttp($other, 'POST', $url, ['transfer_code' => 'NEVER-FLASH-THIS']);
verify($bad->getStatusCode() === 404, 'Foreign owner reached the transfer form.');
$bad = enomHttp($owner, 'POST', $url, ['transfer_code' => 'NEVER-FLASH-THIS']);
verify($bad->getStatusCode() === 419, 'Missing form key was accepted.');
// Render the owned partial on a test-only route so its real form key lives in the owner's session.
Illuminate\Support\Facades\Route::middleware(['web', 'auth'])->get('/__transfer-form/{domain}', function (Illuminate\Http\Request $request, int $domain) {
    return view('enom::partials.transfer-code', ['domain' => Domain::findOrFail($domain)]);
});
enomResetThrottle($owner);
$formPage = enomHttp($owner, 'GET', '/__transfer-form/'.$httpCandidate->domain_id);
verify($formPage->getStatusCode() === 200, 'Transfer form did not render through session middleware.');
$form = enomFormFields($formPage->getContent(), $url);
$invalidShape = enomHttp($owner, 'POST', $url, array_replace($form, ['transfer_code' => ['NEVER-REFLECT-ARRAY']]), ['csrf' => true]);
verify($invalidShape->getStatusCode() === 302 && !str_contains($invalidShape->getContent(), 'NEVER-REFLECT-ARRAY')
    && !str_contains(json_encode(app('session')->all()), 'NEVER-REFLECT-ARRAY'), 'Non-scalar code input escaped safe validation.');
DB::beginTransaction();
$response = enomHttp($owner, 'POST', $url, array_replace($form, ['transfer_code' => 'HTTP-CASE-code  ']), ['csrf' => true]);
verify($response->getStatusCode() === 302 && enomRedirectPath($response) === '/domains/'.$httpCandidate->domain_id
    && $httpCandidate->fresh()->payload['epp'] === 'HTTP-CASE-code  ', 'Authenticated code submission lost code bytes or failed CSRF.');
verify(!str_contains($response->getContent(), 'HTTP-CASE-code') && !str_contains(json_encode(app('session')->all()), 'HTTP-CASE-code'), 'Response or flash reflected the code.');
$replay = enomHttp($owner, 'POST', $url, array_replace($form, ['transfer_code' => 'replay']));
verify($replay->getStatusCode() === 419, 'Consumed form key accepted a replay.');
DB::rollBack();
enomResetThrottle($owner);
for ($i = 0; $i < 11; $i++) { $limited = enomHttp($owner, 'POST', $url, ['transfer_code' => 'RATE-CODE']); }
verify($limited->getStatusCode() === 429, 'Transfer code endpoint lacks a request limit.');
// Daily timeout work runs while registrar writes are paused.
$server->settings()->where('key', 'operations_enabled')->update(['value' => '0']);
$sweep = transferFixture(true, '2026-09-18T12:00:00+00:00');
$transfers->expireMissingCodes();
verify($sweep->fresh()->safe_code === 'transfer_code_timeout' && isset($sweep->fresh()->cost['refund']), 'Paused registrar writes stopped missing-code refunds.');

// Render only this slice's partial, with the owner signed in.
$operation->refresh()->update(['policy' => array_replace($operation->fresh()->policy, ['transfer_lookup_unknown' => true])]);
auth()->login($owner);
$request = Illuminate\Http\Request::create('/domains/'.$operation->domain_id);
$request->setLaravelSession(app('session')->driver());
app()->instance('request', $request);
$html = view('enom::partials.transfer-code', ['domain' => $operation->domain])->render();
verify(str_contains($html, 'auth code or EPP code') && str_contains($html, 'Nov 16, 2026</time>') && !str_contains($html, ' UTC') && !str_contains($html, 'old('), 'Transfer form lacks an exact deadline or safely empty input.');
verify(str_contains($html, "transfer status in advance"), 'Lookup-unavailable warning is missing from the domain page.');
verify(!str_contains($html, 'value="'.$secret.'"'), 'Form redisplayed the code.');
verify(!str_contains((string) @file_get_contents($testRoot.'/storage/logs/laravel.log'), 'NEVER-FLASH-THIS'), 'Code reached application logs.');
echo 'Transfer codes: '.$checks.' passed; SQLite in memory; native cart and ordering resume integrated.'.PHP_EOL;
$successful = true;
