<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real stock Laravel/models, synthetic environment, in-memory SQLite, injected fake provider.
// Never load an installed app or any .env. Run: php tests/Enom/reconciliation-rejection.php
$repo = dirname(__DIR__, 2);
$successful = false;
$testRoot = null;
paymenterTestShutdown($testRoot, $successful);
$release = paymenterTestApp();
if (!is_file($release.'/vendor/autoload.php') || is_file($release.'/.env')) { throw new RuntimeException('A pristine release with vendor and no environment file is required.'); }
$testRoot = $repo.'/.scratch/enom-rejection-test-'.bin2hex(random_bytes(5));
mkdir($testRoot, 0700, true);
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) { mkdir($testRoot.'/'.$path, 0700, true); }
foreach (['config', 'database', 'routes', 'vendor', 'themes', 'resources', 'public'] as $path) { symlink($release.'/'.$path, $testRoot.'/'.$path); }
mkdir($testRoot.'/app', 0700);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($release.'/app', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
    $target = $testRoot.'/app/'.substr($file->getPathname(), strlen($release.'/app/'));
    if ($file->isDir()) { mkdir($target, 0700, true); } else { copy($file->getPathname(), $target); }
}
copy($release.'/bootstrap/app.php', $testRoot.'/bootstrap/app.php');
copy($release.'/bootstrap/providers.php', $testRoot.'/bootstrap/providers.php');
copy($release.'/composer.json', $testRoot.'/composer.json');
$extensionRoot = $testRoot.'/extensions/Servers/Enom';
mkdir($extensionRoot, 0700, true);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo.'/Enom', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
    $target = $extensionRoot.'/'.substr($file->getPathname(), strlen($repo.'/Enom/'));
    if ($file->isDir()) { mkdir($target, 0700, true); } else { copy($file->getPathname(), $target); }
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'APP_URL' => 'http://enom-tests.invalid',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
    'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'APP_TELEMETRY_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
$loader = require $release.'/vendor/autoload.php';
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Enom\\', $extensionRoot.'/', true);
$app = require $testRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['audit.enabled' => false, 'settings.mail_disabled' => true, 'settings.default_currency' => 'USD', 'settings.tax_enabled' => false, 'settings.theme' => 'default']);
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') { throw new RuntimeException('Test database isolation failed.'); }
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$extension = new Paymenter\Extensions\Servers\Enom\Enom;
$extension->installed();
$extension->boot();
$checks = 0;
function verify(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\OrderIntent;

App\Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$']);
$user = App\Models\User::create(['email' => 'rejection@example.invalid', 'password' => 'synthetic']);
$other = App\Models\User::create(['email' => 'other@example.invalid', 'password' => 'synthetic']);
$server = App\Models\Server::create(['name' => 'Offline Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$category = App\Models\Category::create(['name' => 'Offline', 'slug' => 'offline']);
$product = App\Models\Product::create(['name' => 'Offline .com', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'Year', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$sequence = 0;
function fixture(string $action = 'transfer'): array {
    global $sequence, $user, $server, $product, $plan;
    $sequence++;
    return Illuminate\Database\Eloquent\Model::withoutEvents(function () use ($action) {
        global $sequence, $user, $server, $product, $plan;
        $order = App\Models\Order::create(['user_id' => $user->id, 'currency_code' => 'USD']);
        $service = $order->services()->create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '15.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => 'pending']);
        $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id, 'domain' => 'reject'.$sequence.'.com', 'sld' => 'reject'.$sequence, 'tld' => 'com', 'status' => $action === 'register' ? 'registration_pending' : 'transfer_pending', 'capabilities' => []]);
        $invoice = App\Models\Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'status' => 'paid']);
        $item = $invoice->items()->create(['reference_type' => App\Models\Service::class, 'reference_id' => $service->id, 'quantity' => 1, 'price' => '15.00', 'description' => 'Offline rejection']);
        $invoice->transactions()->create(['amount' => '15.00', 'status' => App\Enums\InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => true, 'transaction_id' => 'offline-'.$sequence]);
        $operation = Operation::create(['domain_id' => $domain->id, 'service_id' => $service->id, 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'operation_key' => 'offline-'.$sequence, 'action' => $action, 'funding_basis' => 'paid_invoice', 'state' => 'pending', 'amount' => '15.00', 'currency' => 'USD', 'provider_order_id' => (string) (1000 + $sequence), 'payload' => ['epp' => 'synthetic-secret'], 'started_at' => now()->subMinute()]);
        $intent = OrderIntent::create(['id' => 'intent'.$sequence, 'user_id' => $user->id, 'server_id' => $server->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'domain' => $domain->domain, 'sld' => $domain->sld, 'tld' => 'com', 'action' => $action, 'amount' => '15.00', 'renewal_amount' => '15.00', 'currency' => 'USD', 'expires_at' => now()->addMinutes(15), 'service_id' => $service->id, 'payload' => ['epp' => 'synthetic-secret']]);
        return [$domain, $operation->fresh(), $intent];
    });
}
function finances(): array {
    $data = [];
    foreach (['invoices', 'invoice_items', 'invoice_transactions', 'failed_jobs'] as $table) { $data[$table] = DB::table($table)->orderBy('id')->get()->toArray(); }
    // Registrar work queued by a rejection would be a replay; the refund notification email is the only allowed job.
    $data['jobs'] = DB::table('jobs')->orderBy('id')->get()
        ->reject(fn ($job) => (json_decode($job->payload, true)['displayName'] ?? null) === 'App\\Mail\\Mail')->values()->all();
    return $data;
}
/** 0.4: a definite rejection of a paid registration/transfer credits the exact paid amount once and cancels its service. */
function refundedOnce(Operation $operation, string $before): bool {
    $operation = $operation->fresh();
    $credit = App\Models\Credit::where('user_id', $operation->domain->user_id)->where('currency_code', 'USD')->value('amount');
    return ($operation->cost['refund']['amount'] ?? null) === '15.00' && ($operation->cost['refund']['to'] ?? null) === 'account_credit'
        && (int) round(((float) $credit - (float) $before) * 100) === 1500 && App\Models\Service::find($operation->service_id)?->status === 'cancelled';
}
function creditNow(): string { global $user; return (string) (App\Models\Credit::where('user_id', $user->id)->where('currency_code', 'USD')->value('amount') ?? '0'); }
App\Models\Credit::create(['user_id' => $other->id, 'currency_code' => 'USD', 'amount' => '900.00']);
verify(creditNow() === '0', 'Credit lookup included another client.');
function reconciler(Operation $operation, array $overrides = [], ?Closure $afterRead = null): array {
    $commands = new ArrayObject;
    $data = $overrides + ['id' => $operation->provider_order_id, 'sld' => $operation->domain->sld, 'tld' => 'com', 'header' => '2', 'detail' => '32', 'errcount' => '0'];
    $client = new RegistrarClient('offline', 'offline', function ($command, $params) use ($data, $commands, $afterRead): string {
        $commands[] = $command;
        if ($command === 'GetDomainExp') {
            return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><ExpirationDate>2027-10-13</ExpirationDate></interface-response>';
        }
        if ($command !== 'TP_GetOrder') { throw new RuntimeException('Unexpected provider command '.$command); }
        if ($afterRead) { $afterRead(); }
        $detail = '<transferorderdetail><sld>'.$data['sld'].'</sld><tld>'.$data['tld'].'</tld><statusid>'.$data['detail'].'</statusid></transferorderdetail>';
        if ($data['multiple'] ?? false) { $detail .= $detail; }
        if ($data['missing_detail'] ?? false) { $detail = ''; }
        $error = $data['errcount'] === '0' ? '' : '<RRPCode>530</RRPCode><Err1>Offline query failure</Err1>';
        return '<interface-response><Command>'.$command.'</Command><ErrCount>'.$data['errcount'].'</ErrCount><Done>true</Done>'.$error.'<transferorder><transferorderid>'.$data['id'].'</transferorderid><statusid>'.$data['header'].'</statusid>'.$detail.'</transferorder></interface-response>';
    });
    return [new DomainOperations(new EnomApi($client), fn () => []), $commands];
}
[$domain, $operation, $intent] = fixture();
[$operations, $commands] = reconciler($operation);
verify($operations->funded($operation), 'Fixture must use a genuine funded native ledger.');
$before = finances();
$creditBefore = creditNow();
$result = $operations->reconcile($operation->id);
verify($result->state === 'rejected', 'Matching rejected transfer stayed pending.');
verify($domain->fresh()->status === 'transfer_rejected', 'Rejected transfer still labelled pending.');
verify($result->payload === null && $intent->fresh()->payload === null && $result->completed_at !== null, 'Terminal rejection retained private payload or lacked completion timestamp.');
verify($result->safe_code === 'provider_rejected:2', 'Terminal rejection lost bounded refusal code.');
verify(finances() == $before, 'Rejection changed native invoices, payments or registrar queues.');
verify(refundedOnce($result, $creditBefore), 'Definite paid transfer rejection was not credited once with its service cancelled.');
$operations->reconcile($operation->id); $operations->execute($operation->id);
app(Paymenter\Extensions\Servers\Enom\Services\Refunds::class)->retryPending();
verify(refundedOnce($result, $creditBefore), 'Replayed reconciliation or refund retry credited the client again.');
verify($commands->getArrayCopy() === ['TP_GetOrder'], 'Terminal rejection read again or resent a mutation.');
foreach ([['id' => '999999'], ['sld' => 'foreign'], ['tld' => 'net'], ['header' => '1'], ['header' => '99'], ['detail' => '5'], ['detail' => '99'], ['detail' => ''], ['id' => ''], ['multiple' => true], ['missing_detail' => true], ['header' => '1', 'errcount' => '1'], ['errcount' => '1']] as $overrides) {
    [$candidateDomain, $candidate, $candidateIntent] = fixture();
    [$reader, $reads] = reconciler($candidate, $overrides);
    $beforeRow = DB::table('enom_operations')->where('id', $candidate->id)->first();
    $reader->reconcile($candidate->id);
    verify(DB::table('enom_operations')->where('id', $candidate->id)->first() == $beforeRow && $candidateDomain->fresh()->status === 'transfer_pending' && $candidateIntent->fresh()->payload !== null, 'Unmatched, nonterminal or contradictory transfer became terminal.');
    verify($reads->getArrayCopy() === ['TP_GetOrder'], 'Adversarial rejection invoked an unexpected command.');
}
foreach (['unpaid', 'wrong_owner', 'wrong_reference', 'wrong_service', 'wrong_currency', 'wrong_order_binding'] as $fault) {
    [$candidateDomain, $candidate] = fixture();
    if ($fault === 'unpaid') { App\Models\Invoice::withoutEvents(fn () => App\Models\Invoice::find($candidate->invoice_id)->update(['status' => 'pending'])); }
    if ($fault === 'wrong_owner') { App\Models\Invoice::withoutEvents(fn () => App\Models\Invoice::find($candidate->invoice_id)->update(['user_id' => $other->id])); }
    if ($fault === 'wrong_reference') { App\Models\InvoiceItem::withoutEvents(fn () => App\Models\InvoiceItem::find($candidate->invoice_item_id)->update(['reference_id' => 999999])); }
    if ($fault === 'wrong_service') { $candidate->update(['service_id' => 999999]); }
    if ($fault === 'wrong_currency') { $candidate->update(['currency' => 'EUR']); }
    if ($fault === 'wrong_order_binding') { $candidate->update(['provider_order_id' => null]); }
    [$reader, $reads] = reconciler($candidate);
    verify($reader->reconcile($candidate->id)->state === 'pending' && count($reads) === 0, 'Invalid ledger reached provider reconciliation.');
}
// A funded ledger can change during the provider read. Recheck under native row locks.
[$raceDomain, $race] = fixture();
[$raceReader] = reconciler($race, [], fn () => Operation::whereKey($race->id)->update(['provider_order_id' => '999998']));
verify($raceReader->reconcile($race->id)->state === 'pending', 'Changed order binding became rejected from stale provider evidence.');
[$raceDomain, $race] = fixture();
[$raceReader] = reconciler($race, [], fn () => App\Models\Invoice::withoutEvents(fn () => App\Models\Invoice::find($race->invoice_id)->update(['status' => 'pending'])));
verify($raceReader->reconcile($race->id)->state === 'pending', 'Changed funding became rejected from stale provider evidence.');
[$completedDomain, $completedTransfer] = fixture();
[$completionReader, $completionReads] = reconciler($completedTransfer, ['header' => '3', 'detail' => '5']);
verify($completionReader->reconcile($completedTransfer->id)->state === 'succeeded'
    && $completedDomain->fresh()->status === 'active'
    && $completedDomain->fresh()->service->expires_at->format('Y-m-d') === '2027-09-13', 'Successful transfer reconciliation no longer confirms native dates.');
verify($completionReads->getArrayCopy() === ['TP_GetOrder', 'GetDomainExp'], 'Transfer completion invoked a provider mutation.');
// Immediate registration/transfer rejection uses the same terminal presentation path.
function refusalExecutor(): DomainOperations {
    $client = new RegistrarClient('offline', 'offline', function ($command, $params): string {
        // The execution preflight refuses the name before a registrar mutation is sent.
        if ($command !== 'Check') { throw new RuntimeException('Unexpected mutation during initial refusal.'); }
        return '<interface-response><Command>Check</Command><ErrCount>0</ErrCount><Done>true</Done><Domains><Domain><Name>'.$params['SLD'].'.com</Name><RRPCode>211</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Properties><Transferable>false</Transferable></Properties></Domain></Domains></interface-response>';
    });
    return new DomainOperations(new EnomApi($client), fn () => []);
}
foreach (['register' => 'registration_rejected', 'transfer' => 'transfer_rejected'] as $action => $expected) {
    [$initial, $initialOperation] = fixture($action);
    $initialOperation->update(['state' => 'queued', 'provider_order_id' => null]);
    $before = finances();
    $creditBefore = creditNow();
    $refused = refusalExecutor()->execute($initialOperation->id);
    verify($refused->state === 'rejected' && $initial->fresh()->status === $expected && finances() == $before, 'Initial execution rejection failed presentation or changed native accounting.');
    verify(refundedOnce($refused, $creditBefore), 'Initial paid '.$action.' rejection was not credited once.');
}
[$active, $historical] = fixture('register');
$historical->update(['state' => 'queued', 'provider_order_id' => null]);
$active->update(['status' => 'active', 'provider_order_id' => 'confirmed-order', 'provider_expires_at' => '2027-10-13']);
refusalExecutor()->execute($historical->id);
verify($active->fresh()->status === 'active' && $active->fresh()->provider_order_id === 'confirmed-order', 'Historical rejection overwrote an active domain.');
verify($active->fresh()->service->status === 'pending' && !isset($historical->fresh()->cost['refund']), 'A request held against an active domain was refunded or cancelled its service.');
echo 'Reconciliation rejection: '.$checks.' passed; injected provider reads only; SQLite in memory.'.PHP_EOL;
$successful = true;
