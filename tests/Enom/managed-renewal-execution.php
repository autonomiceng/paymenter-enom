<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real stock Laravel/models, synthetic environment, in-memory SQLite, injected fake provider.
// Never load an installed app or any .env. Run: php tests/Enom/managed-renewal-execution.php
$repo = dirname(__DIR__, 2);
$successful = false;
register_shutdown_function(function () use (&$successful) { if (!$successful) { exit(1); } });
$release = paymenterTestApp();
if (!is_file($release.'/vendor/autoload.php') || is_file($release.'/.env')) { throw new RuntimeException('A pristine release with vendor and no environment file is required.'); }
$testRoot = $repo.'/.scratch/enom-managed-execution-test-'.bin2hex(random_bytes(5));
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
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceCancellation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainBilling;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;

$checks = 0;
function checkExecution(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function executionClock(string $date = '2026-11-10'): void { Carbon\Carbon::setTestNow($date.' 12:00:00'); }
function executionXml(string $command, string $body): string {
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body
        .'<PrivateDiagnostic>synthetic-token-do-not-store synthetic-contact-do-not-store</PrivateDiagnostic></interface-response>';
}
function executionNativeMoney(): string {
    return hash('sha256', serialize(array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toArray(),
        ['invoices', 'invoice_items', 'invoice_transactions', 'credits'])));
}
function executionNativeDates(Domain $domain): void {
    $service = $domain->fresh()->service;
    checkExecution($service->status === Service::STATUS_ACTIVE && $service->expires_at === null,
        'Managed registrar fulfillment changed native active/null-date service behavior.');
}
function executionSafeCost(Operation $operation): void {
    $json = json_encode($operation->fresh()->cost, JSON_THROW_ON_ERROR);
    foreach (['synthetic-token-do-not-store', 'synthetic-contact-do-not-store', 'PrivateDiagnostic', 'private transport diagnostic', 'offline-account'] as $secret) {
        checkExecution(!str_contains($json, $secret), 'Registrar cost evidence retained private raw data.');
    }
}
executionClock();
config(['app.version' => 'development']);
Illuminate\Support\Facades\Http::preventStrayRequests();
$nativeSchema = DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name");
$user = App\Models\User::create(['email' => 'execution-owner@example.invalid', 'password' => bcrypt('synthetic')]);
$role = App\Models\Role::create(['name' => 'Synthetic renewal funder', 'permissions' => ['admin.enom.manage', 'admin.enom.fund']]);
$staff = App\Models\User::create(['email' => 'execution-staff@example.invalid', 'password' => bcrypt('synthetic'), 'role_id' => $role->id]);
App\Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$']);
$server = App\Models\Server::create(['name' => 'Synthetic execution', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['operations_enabled', 'billing_enabled'] as $key) {
    $server->settings()->create(['key' => $key, 'value' => true, 'type' => 'boolean']);
}
// 0.3 ordering binds the server account from its stored credentials, which must be encrypted.
$server->settings()->create(['key' => 'uid', 'value' => 'offline-account', 'encrypted' => true]);
$server->settings()->create(['key' => 'token', 'value' => 'synthetic-token-do-not-store', 'encrypted' => true]);
$category = App\Models\Category::create(['name' => 'Execution test', 'slug' => 'execution-test']);
$product = App\Models\Product::create(['name' => 'Managed domain', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
// 0.3: orderable endings and prices come from the catalogue; this product is the server's catalogue product.
$product->settings()->create(['key' => 'enom_catalogue', 'value' => '1']);
Paymenter\Extensions\Servers\Enom\Models\Tld::create(['server_id' => $server->id, 'tld' => 'com', 'enabled' => true, 'pricing_mode' => 'fixed',
    'register_price' => '15.00', 'renew_price' => '15.00', 'transfer_price' => '15.00']);
$plan = $product->plans()->create(['name' => 'One year', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$plan->prices()->create(['currency_code' => 'USD', 'price' => 15, 'setup_fee' => 0]);
App\Models\Gateway::create(['name' => 'Synthetic gateway', 'type' => 'gateway', 'extension' => 'Stripe', 'enabled' => true]);

$configuration = ['uid' => 'offline-account', 'token' => 'synthetic-token-do-not-store', 'sandbox' => true,
    'operations_enabled' => true, 'grace_renewals_enabled' => true];
$fixtures = []; $calls = []; $writes = []; $orderNames = []; $during = null;
$fake = function (string $command, array $params) use (&$fixtures, &$calls, &$writes, &$orderNames, &$during): string {
    if ($command === 'TP_CreateOrder') {
        $calls[] = $command;
        $writes[] = [$command, $params];
        return executionXml($command, '<TransferOrderID>30000</TransferOrderID>');
    }
    $name = $command === 'GetOrderDetail' ? ($orderNames[$params['OrderID']] ?? '')
        : ($params['DomainName'] ?? (($params['SLD'] ?? '').'.'.($params['TLD'] ?? '')));
    if (in_array($command, ['GetBalance', 'GetExpiredDomains'], true)) { $name = array_key_last($fixtures); }
    if (!isset($fixtures[$name])) { throw new RuntimeException('No isolated provider fixture for '.$command); }
    $f = &$fixtures[$name];
    $calls[] = $command;
    if ($during) { $during($command, $name); }
    $quoted = htmlspecialchars($name, ENT_XML1);
    if (($f['fail'] ?? null) === $command) { throw new RuntimeException('private transport diagnostic'); }
    if (in_array($command, ['Purchase', 'Extend', 'UpdateExpiredDomains'], true)) {
        $writes[] = [$command, $params];
        checkExecution(($params['UseCreditCard'] ?? null) === 'no', 'Registrar write allowed provider credit-card funding.');
        checkExecution(($params['NumYears'] ?? null) === 1, 'Registrar write changed the captured one-year term.');
        $f['order_id'] ??= (string) (1000 + count($fixtures));
        $orderNames[$f['order_id']] = $name;
        if (($f['send_unknown'] ?? false)) { return '<invalid'; }
        if (($f['advance'] ?? true)) {
            $f['expiry'] = $f['next_expiry'] ?? (new DateTimeImmutable($f['expiry']))->modify('+1 year')->format('Y-m-d');
            foreach (['info_expiry','extend_expiry','expiry_read'] as $field) { if (isset($f['next_'.$field])) { $f[$field]=$f['next_'.$field]; } }
        }
        if ($command === 'UpdateExpiredDomains') {
            return executionXml($command, '<ReactivateDomainName><Status>true</Status><OrderID>'.$f['order_id'].'</OrderID></ReactivateDomainName>');
        }
        return executionXml($command, '<RRPCode>200</RRPCode><OrderID>'.$f['order_id'].'</OrderID><Extension>Successful</Extension><RegistryExpDate>'.($f['ack_expiry'] ?? $f['expiry']).'</RegistryExpDate>');
    }
    $body = match ($command) {
        'Check' => '<Domains><Domain><Name>'.$quoted.'</Name><RRPCode>'.(($f['available'] ?? false) ? '210' : '211').'</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Properties><Transferable>true</Transferable></Properties><Prices><Currency>USD</Currency><Registration>'.$f['price'].'</Registration><Renewal>'.$f['price'].'</Renewal></Prices></Domain></Domains>',
        'GetDomainInfo' => '<GetDomainInfo><domainname domainnameid="'.($f['domain_id'] ?? '456').'">'.$quoted.'</domainname><status><expiration>'.($f['info_expiry'] ?? $f['expiry']).'</expiration><belongs-to>'.($f['uid'] ?? 'offline-account').'</belongs-to></status></GetDomainInfo>',
        'GetDomainExp' => '<ExpirationDate>'.($f['expiry_read'] ?? $f['expiry']).'</ExpirationDate>',
        'GetExtendInfo' => '<Expiration>'.($f['extend_expiry'] ?? $f['expiry']).'</Expiration><RegistrarHold>'.($f['hold'] ?? 'false').'</RegistrarHold><MaxExtension>9</MaxExtension>'.(($f['unknown_quote'] ?? false) ? '' : '<Price>'.$f['price'].'</Price>'),
        'GetExpiredDomains' => '<DomainCount>1</DomainCount><DomainDetail><DomainName>'.$quoted.'</DomainName><DomainNameID>'.($f['domain_id'] ?? '456').'</DomainNameID><Status>'.($f['expired_status'] ?? 'Expired').'</Status><Expiration-Date>'.$f['expiry'].'</Expiration-Date></DomainDetail>',
        'GetRenew' => '<Auto-Renew>false</Auto-Renew>',
        'GetBalance' => '<AvailableBalance>'.($f['balance'] ?? '100.00').'</AvailableBalance>',
        'GetOrderDetail' => '<Order><OrderID>'.($f['read_order_id'] ?? $f['order_id']).'</OrderID><Result>true</Result><OrderBillAmount>'.($f['actual'] ?? $f['price']).'</OrderBillAmount><OrderDetail><Description>'.($f['order_domain'] ?? $quoted).'</Description><Status>Successful</Status><OrderStatus>'.($f['order_state'] ?? 'Closed').'</OrderStatus><Quantity>'.($f['order_term'] ?? '1').'</Quantity><ProductType>'.($f['product_type'] ?? 'Extend').'</ProductType>'.(($f['unknown_actual'] ?? false) ? '' : '<AmountPaid>'.($f['actual'] ?? $f['price']).'</AmountPaid>').'<ExpiryDatetime>'.($f['order_expiry'] ?? $f['expiry']).'</ExpiryDatetime></OrderDetail></Order>',
        default => throw new RuntimeException('Unexpected isolated provider command '.$command),
    };
    return executionXml($command, $body);
};
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'synthetic-token-do-not-store', $fake)),
    function (int $id, bool $readOnly = false) use (&$configuration, $server): array {
        checkExecution($id === $server->id, 'Provider call escaped the synthetic server.');
        return $configuration;
    });
$billing = new DomainBilling($operations);
$app->instance(DomainOperations::class, $operations);
$app->instance(DomainBilling::class, $billing);
Event::listen(App\Events\Invoice\Paid::class, fn ($event) => $operations->acceptPaidInvoice($event->invoice));
$counter = 0;
$make = function (string $kind = 'paid', string $tld = 'com', array $options = [], string $settlement = '15.00') use (&$counter, &$fixtures, &$calls, &$writes, $user, $staff, $server, $product, $plan, $billing): Operation {
    executionClock();
    $counter++;
    $name = 'execution-'.$counter.'.'.$tld;
    $fixtures[$name] = array_replace(['expiry' => '2026-12-01', 'price' => '10.00'], $options);
    $calls = []; $writes = [];
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
        'price' => $kind === 'sponsored' ? '0.00' : '15.00', 'quantity' => 1, 'currency_code' => 'USD',
        'status' => Service::STATUS_ACTIVE, 'expires_at' => null]);
    $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id,
        'domain' => $name, 'sld' => 'execution-'.$counter, 'tld' => $tld, 'provider_domain_id' => '456',
        'status' => 'active', 'provider_expires_at' => $options['expiry'] ?? '2026-12-01', 'billing_managed' => true,
        'authority_generation' => 1, 'confirmed_revision' => 1, 'renewal_policy' => ['enabled' => true], 'price_mode' => 'custom']); // 0.3 backfill: keep the native price
    if ($kind === 'complimentary') {
        // 0.3: the reseller pays; one renewal per cycle at a quote under the server's complimentary cost limit.
        $service->update(['price' => '0.00']);
        $domain->update(['price_mode' => 'complimentary']);
        return $billing->complimentary($domain->id, $staff);
    }
    if ($kind === 'sponsored') {
        $domain->update(['sponsorship' => ['enabled' => true, 'standing' => true, 'approved_by' => $staff->id,
            'reason' => 'Isolated sponsorship approval', 'limit_minor' => 1200, 'currency' => 'USD', 'term' => 1],
            'observation' => ['renewal_quote' => ['state' => 'validated', 'complete' => true, 'domain' => $name,
                'action' => 'renew', 'term' => 1, 'base_expires_at' => '2026-12-01', 'amount' => '10.00', 'currency' => 'USD',
                'grace_eligible' => false, 'observed_at' => now()->toIso8601String()]]]);
        return $billing->sponsor($domain->id);
    }
    $invoice = $billing->invoice($domain->id, $user);
    checkExecution($invoice->items->first()->reference_type === null && $invoice->items->first()->reference_id === null,
        'Managed obligation used a native service renewal item.');
    if ($settlement !== '0.00') { ExtensionHelper::addPayment($invoice, 'Stripe', $settlement, transactionId: 'execution-payment-'.$counter); }
    $operation = Operation::where('invoice_id', $invoice->id)->firstOrFail();
    checkExecution($operation->policy['original_net_minor'] === 1500, 'Native original net price was not captured.');
    executionNativeDates($domain);
    return $operation;
};

// Native settled obligation executes exactly once and preserves original financial rows.
foreach (['com', 'net', 'us'] as $tld) {
    $operation = $make(tld: $tld);
    $before = executionNativeMoney();
    checkExecution($operation->state === 'queued' && $operations->funded($operation), 'Native paid event failed to admit funded obligation.');
    $result = $operations->execute($operation->id);
    checkExecution($result->state === 'succeeded', 'Ordinary '.$tld.' renewal failed: '.$result->safe_code);
    checkExecution(count($writes) === 1 && $writes[0][0] === 'Extend' && $writes[0][1]['TLD'] === $tld, 'Renewal used wrong command or TLD.');
    checkExecution(array_slice($calls, 0, 6) === ['GetDomainInfo', 'GetExtendInfo', 'Check', 'GetRenew', 'GetBalance', 'Extend'], 'Mutation preceded fresh admission.');
    checkExecution($result->domain->provider_expires_at->format('Y-m-d') === '2027-12-01'
        && $result->confirmed_expires_at->format('Y-m-d') === '2027-12-01', 'Confirmed registrar expiry did not advance.');
    checkExecution($result->cost['quote']['amount'] === '10.00' && $result->cost['quote']['complete'] === true, 'Fresh quote was not retained.');
    checkExecution($result->cost['execution']['command'] === 'Extend' && $result->cost['execution']['orderid'] === $result->provider_order_id, 'Execution identity was not retained.');
    checkExecution($result->cost['actual']['state'] === 'unconfirmed' && $result->cost['actual']['amount'] === null, 'Unaccepted product type falsely confirmed actual cost.');
    executionNativeDates($result->domain);
    executionSafeCost($result);
    checkExecution($before === executionNativeMoney(), 'Execution changed paid invoice or transaction rows.');
    $operations->execute($operation->id);
    $operations->reconcile($operation->id);
    $operations->acceptPaidInvoice(Invoice::findOrFail($operation->invoice_id));
    checkExecution(count($writes) === 1, 'Duplicate execution or paid-event replay sent another renewal.');
}

foreach ([['privacy_purchase', 'awaiting_payment'], ['hosts', 'held']] as [$action, $state]) {
    $operation = $make();
    $peer = Operation::create(['domain_id' => $operation->domain_id, 'service_id' => $operation->service_id,
        'operation_key' => 'execution-unrelated-'.$action, 'action' => $action, 'state' => $state, 'amount' => 5, 'currency' => 'USD']);
    $peerBefore = $peer->fresh()->getRawOriginal();
    $result = $operations->execute($operation->id);
    checkExecution($result->state === 'succeeded' && count($writes) === 1,
        'Unrelated unpaid privacy or held management operation blocked funded annual renewal.');
    checkExecution($peer->fresh()->getRawOriginal() === $peerBefore, 'Annual execution changed unrelated operation history.');
}
$leap = $make(options: ['expiry' => '2028-02-29', 'next_expiry' => '2029-02-28']);
executionClock('2028-02-08');
$result = $operations->execute($leap->id);
checkExecution($result->state === 'succeeded' && $result->confirmed_expires_at->format('Y-m-d') === '2029-02-28',
    'Real registry leap-day expiry failed one-year execution confirmation: '.$result->safe_code);
executionNativeDates($result->domain);

// Expired acknowledgement stays pending until exact order and expiry agree.
$grace = $make(tld: 'net', options: ['hold' => 'true']);
executionClock('2026-12-09');
$grace->domain->update(['status' => 'expired']);
$before = executionNativeMoney();
$result = $operations->execute($grace->id);
checkExecution($result->state === 'pending' && count($writes) === 1 && $writes[0][0] === 'UpdateExpiredDomains', 'Expired path failed: '.$result->safe_code);
checkExecution($writes[0][1]['DomainName'] === $grace->domain->domain && !isset($writes[0][1]['SLD']), 'Expired request changed domain identity.');
checkExecution($result->provider_order_id !== null && $result->cost['execution']['reactivatedomainname']['orderid'] === $result->provider_order_id, 'Nested order identity lost.');
checkExecution($result->domain->provider_expires_at->format('Y-m-d') === '2026-12-01', 'Pending renewal prematurely changed registrar expiry.');
$operations->execute($grace->id);
checkExecution(count($writes) === 1, 'Pending grace operation was retransmitted.');
foreach ([['read_order_id' => '99999'], ['order_domain' => 'foreign.net'], ['order_term' => '2'], ['order_state' => 'Open']] as $invalid) {
    $fixtures[$grace->domain->domain] = array_replace($fixtures[$grace->domain->domain], $invalid);
    checkExecution($operations->reconcile($grace->id)->state === 'pending', 'Mismatched order confirmed expired renewal.');
    foreach (array_keys($invalid) as $key) { unset($fixtures[$grace->domain->domain][$key]); }
}
$fixtures[$grace->domain->domain]['expiry'] = '2028-12-01';
checkExecution($operations->reconcile($grace->id)->state === 'pending', 'Wrong registrar expiry confirmed renewal.');
$fixtures[$grace->domain->domain]['expiry'] = '2027-12-01';
$result = $operations->reconcile($grace->id);
checkExecution($result->state === 'succeeded' && $result->domain->status === 'active', 'Grace reconciliation failed: '.$result->safe_code);
checkExecution($result->cost['actual']['state'] === 'unconfirmed', 'Grace quote was promoted to actual cost.');
executionNativeDates($result->domain);
executionSafeCost($result);
checkExecution(count($writes) === 1 && $before === executionNativeMoney(), 'Grace reconciliation resent purchase or changed finance.');

$unknown = $make(options: ['unknown_quote' => true, 'unknown_actual' => true]);
$result = $operations->execute($unknown->id);
checkExecution($result->state === 'succeeded' && $result->cost['quote']['complete'] === false
    && $result->cost['actual']['state'] === 'unconfirmed', 'Unknown ordinary cost was fabricated or blocked.');
checkExecution(!in_array('GetBalance', $calls, true), 'Unknown cost supplied fabricated balance comparison.');
$interrupted = $make(options: ['send_unknown' => true]);
$result = $operations->execute($interrupted->id);
checkExecution($result->state === 'uncertain' && count($writes) === 1 && $result->provider_order_id === null,
    'Malformed managed-renewal acknowledgement was not retained as unresolved.');
$operations->execute($interrupted->id);
$operations->reconcile($interrupted->id);
checkExecution($interrupted->fresh()->state === 'uncertain' && count($writes) === 1,
    'Unresolved managed renewal was resent without exact order evidence.');
executionSafeCost($result);
foreach (['paid', 'sponsored'] as $kind) {
    $insufficient = $make($kind, options: ['balance' => '9.99']);
    $result = $operations->execute($insufficient->id);
    checkExecution($result->state === 'held' && $writes === [] && $result->cost['quote']['amount'] === '10.00', 'Insufficient balance sent renewal or lost quote.');
    executionNativeDates($result->domain);
    // Staff re-admits the original never-sent obligation after the balance fix.
    $fixtures[$result->domain->domain]['balance'] = '100.00';
    $before = executionNativeMoney();
    checkExecution(!$operations->canReadmitHeld($result, $user) && $operations->canReadmitHeld($result, $staff),
        'Held renewal re-admission lost its staff/sponsorship authorization boundary.');
    $queued = $operations->readmitHeld($result->id, $staff, 'Synthetic balance cause reviewed and corrected.');
    checkExecution($queued->state === 'queued' && $queued->invoice_id === $result->invoice_id
        && $before === executionNativeMoney(), 'Held re-admission created new financial activity.');
    $completed = $operations->execute($queued->id);
    checkExecution($completed->state === 'succeeded' && count($writes) === 1,
        'Reviewed never-sent held obligation failed fresh re-admission.');
}
foreach (['0.00', '5.00'] as $settlement) {
    $unpaid = $make(settlement: $settlement);
    $operations->execute($unpaid->id);
    $operations->acceptPaidInvoice(Invoice::findOrFail($unpaid->invoice_id));
    checkExecution($calls === [] && $writes === [] && $unpaid->fresh()->state === 'awaiting_payment', 'Unpaid/part-paid invoice caused provider work.');
}
$resumed = $make(settlement: '0.00');
$operations->toggleRenewalPreference($user, $resumed->domain_id, false);
$retiredInvoice = Invoice::findOrFail($resumed->invoice_id);
$retiredRows = [$retiredInvoice->getRawOriginal(), $resumed->fresh()->getRawOriginal()];
$operations->toggleRenewalPreference($user, $resumed->domain_id, true);
$replacementInvoice = $billing->invoice($resumed->domain_id, $user);
ExtensionHelper::addPayment($replacementInvoice, 'Stripe', '15.00', transactionId: 'execution-resumed-payment');
$replacement = Operation::where('invoice_id', $replacementInvoice->id)->firstOrFail();
$result = $operations->execute($replacement->id);
checkExecution($result->state === 'succeeded' && count($writes) === 1 && $resumed->fresh()->state === 'rejected',
    'Resumed replacement debt failed its native-paid renewal or replayed a retired operation.');
checkExecution($retiredRows === [$retiredInvoice->fresh()->getRawOriginal(), $resumed->fresh()->getRawOriginal()],
    'Replacement payment/execution rewrote retired history.');

// Grace budget belongs to the issued invoice even when current tariff changes.
$repriced = $make(options: ['price' => '16.00']);
$repriced->domain->service->update(['price' => '30.00']);
executionClock('2026-12-09');
$result = $operations->execute($repriced->id);
checkExecution($result->state === 'held' && $writes === [] && $result->safe_code === 'grace_original_price_exceeded', 'Current tariff replaced original grace budget.');
checkExecution($result->amount === '15.00' && $result->policy['original_net_minor'] === 1500, 'Current tariff rewrote obligation.');
$repriced = $make(options: ['price' => '16.00']);
$repriced->domain->service->update(['price' => '30.00']);
checkExecution($operations->execute($repriced->id)->state === 'succeeded', 'Current tariff invalidated settled ordinary obligation.');
foreach (['2026-11-10', '2026-12-09'] as $date) {
    $sponsored = $make('sponsored');
    executionClock($date);
    $before = executionNativeMoney();
    $result = $operations->execute($sponsored->id);
    if ($result->state === 'pending') { $result = $operations->reconcile($sponsored->id); }
    checkExecution($result->state === 'succeeded' && count($writes) === 1 && $result->invoice_id === null && $result->amount === '0.00', 'Approved sponsorship failed.');
    checkExecution($before === executionNativeMoney(), 'Sponsorship fabricated customer financial activity.');
    executionNativeDates($result->domain);
}
foreach ([['price' => '12.01'], ['unknown_quote' => true]] as $options) {
    $sponsored = $make('sponsored', options: $options);
    checkExecution($operations->execute($sponsored->id)->state === 'held' && $writes === [], 'Sponsorship exceeded approval or used unknown cost.');
}
$sponsored = $make('sponsored');
$sponsored->domain->service->update(['currency_code' => 'EUR']);
$before = executionNativeMoney();
checkExecution(!$operations->funded($sponsored->fresh()), 'Service currency change retained sponsored spending authority.');
$result = $operations->execute($sponsored->id);
checkExecution($result->state === 'held' && $calls === [] && $writes === [], 'Currency change caused sponsored registrar reads or writes.');
checkExecution($before === executionNativeMoney(), 'Currency-change refusal changed native finance.');
foreach (['paid', 'sponsored'] as $kind) {
    $paused = $make($kind);
    $paused->domain->update(['renewal_policy' => ['enabled' => true, 'automatic_spend_paused' => true]]);
    $result = $operations->execute($paused->id);
    checkExecution($result->state === 'held' && $writes === [] && $result->safe_code === 'automatic_spend_paused',
        'Cost incident pause allowed ordinary '.$kind.' registrar spending.');
    executionNativeDates($result->domain);
}

// Fresh-read races prevent the first mutation.
$raced = $make();
$during = function ($command) use ($raced): void { if ($command === 'GetRenew') { $raced->domain->update(['confirmed_revision' => 2]); } };
$result = $operations->execute($raced->id);
$during = null;
checkExecution($result->state === 'held' && $writes === [], 'Revision race dispatched renewal.');
$raced = $make();
$during = function ($command) use (&$configuration): void { if ($command === 'GetExtendInfo') { $configuration['uid'] = 'replacement-offline-account'; } };
$result = $operations->execute($raced->id);
$during = null;
$configuration['uid'] = 'offline-account';
checkExecution($result->state === 'held' && $writes === [], 'Account race dispatched renewal.');
$raced = $make();
$during = function ($command) use ($raced): void {
    if ($command === 'GetBalance') { ServiceCancellation::create(['service_id' => $raced->service_id, 'type' => 'end_of_period', 'reason' => 'Synthetic concurrent cancellation']); }
};
$result = $operations->execute($raced->id);
$during = null;
checkExecution($result->state === 'held' && $writes === [], 'Cancellation during admission dispatched renewal.');
$raced = $make();
$during = function ($command) use ($raced): void {
    if ($command === 'GetBalance') { $raced->domain->update(['provider_expires_at' => '2026-12-02']); }
};
$result = $operations->execute($raced->id);
$during = null;
checkExecution($result->state === 'held' && $writes === [], 'Concurrent local expiry change dispatched renewal.');

// Cancellation after dispatch never reactivates service/domain state.
foreach (['preference', 'service'] as $cancel) {
    $cancelled = $make();
    $during = function ($command) use ($cancelled, $cancel): void {
        if ($command !== 'GetDomainExp') { return; }
        if ($cancel === 'service') { $cancelled->domain->service->update(['status' => Service::STATUS_CANCELLED]); }
        else { ServiceCancellation::create(['service_id' => $cancelled->service_id, 'type' => 'end_of_period', 'reason' => 'Synthetic late cancellation']); }
    };
    $result = $operations->execute($cancelled->id);
    $during = null;
    checkExecution($result->state === 'uncertain' && count($writes) === 1, 'Late cancellation was falsely confirmed or replayed.');
    checkExecution($result->domain->provider_expires_at->format('Y-m-d') === '2026-12-01', 'Late cancellation changed local expiry.');
    $operations->reconcile($cancelled->id);
    checkExecution($cancelled->fresh()->state === 'uncertain' && count($writes) === 1, 'Reconciliation overrode cancellation.');
    if ($cancel === 'service') { checkExecution($cancelled->domain->fresh()->service->status === Service::STATUS_CANCELLED, 'Confirmation reactivated cancelled service.'); }
}

// Native managed sale anchors provider identity for future renewal admission.
executionClock();
$name = 'initial-managed-execution.com';
$fixtures[$name] = ['expiry' => '2026-12-01', 'price' => '10.00', 'available' => true, 'domain_id' => '987', 'product_type' => 'Register'];
$calls = []; $writes = [];
$orders = new DomainOrders($operations);
$quote = $orders->quote($user, $name);
$contacts = ['registrant' => ['first_name' => 'Synthetic', 'last_name' => 'Owner',
    'organization_name' => 'Synthetic', 'address1' => '123 Example Road', 'city' => 'Example', 'postal_code' => '12345',
    'country' => 'US', 'email_address' => 'execution-owner@example.com', 'phone' => '+1.5551234567']];
$invoice = $orders->place($user, $quote['intent_id'], $contacts);
ExtensionHelper::addPayment($invoice, 'Stripe', '15.00', transactionId: 'execution-initial-payment');
$initial = Operation::where('invoice_id', $invoice->id)->firstOrFail();
$result = $operations->execute($initial->id);
checkExecution($result->state === 'succeeded' && $result->domain->billing_managed && $result->domain->provider_domain_id === '987', 'Registration anchor failed: '.$result->safe_code);
checkExecution($result->cost['actual']['state'] === 'confirmed' && $result->cost['actual']['amount'] === '10.00'
    && $result->cost['actual']['currency'] === 'USD', 'Supported registration actual cost was not captured.');
executionNativeDates($result->domain);
executionSafeCost($result);

// A second native billing cycle uses the freshly anchored registration.
executionClock('2027-11-10');
$fixtures[$name]['available'] = false;
$fixtures[$name]['order_id'] = '20000';
$fixtures[$name]['product_type'] = 'Extend';
$calls = []; $writes = [];
$nextInvoice = $billing->invoice($result->domain_id, $user);
ExtensionHelper::addPayment($nextInvoice, 'Stripe', '15.00', transactionId: 'execution-next-cycle-payment');
$next = Operation::where('invoice_id', $nextInvoice->id)->firstOrFail();
$before = executionNativeMoney();
$next = $operations->execute($next->id);
checkExecution($next->state === 'succeeded' && $next->domain->provider_domain_id === '987'
    && $next->domain->provider_expires_at->format('Y-m-d') === '2028-12-01', 'Anchored native sale failed its next managed billing cycle.');
checkExecution(count($writes) === 1 && $writes[0][0] === 'Extend' && $before === executionNativeMoney(),
    'Next native billing cycle resent registration or changed settled finance.');
executionNativeDates($next->domain);

// Provider membership cannot be inferred from the successful Purchase acknowledgement.
foreach (['missing', 'foreign'] as $identity) {
    executionClock();
    $name = 'initial-'.$identity.'-execution.com';
    $fixtures[$name] = ['expiry' => '2026-12-01', 'price' => '10.00', 'available' => true, 'product_type' => 'Register',
        'domain_id' => $identity === 'missing' ? '' : '988', 'uid' => $identity === 'foreign' ? 'foreign-account' : 'offline-account'];
    $calls = []; $writes = [];
    $quote = $orders->quote($user, $name);
    $invoice = $orders->place($user, $quote['intent_id'], $contacts);
    ExtensionHelper::addPayment($invoice, 'Stripe', '15.00', transactionId: 'execution-initial-'.$identity);
    $initial = Operation::where('invoice_id', $invoice->id)->firstOrFail();
    $initialService = $initial->domain->service->getRawOriginal();
    $result = $operations->execute($initial->id);
    checkExecution($result->state === 'uncertain' && $result->domain->provider_domain_id === null
        && $result->domain->provider_expires_at === null && $result->domain->status === 'registration_pending'
        && $result->domain->service->getRawOriginal() === $initialService, 'Unverified initial provider identity changed authoritative/native lifecycle.');
    $operations->execute($initial->id);
    checkExecution(count($writes) === 1, 'Unverified initial provider identity caused registration replay.');
    $fixtures[$name]['domain_id'] = '988';
    $fixtures[$name]['uid'] = 'offline-account';
    $result = $operations->reconcile($initial->id);
    checkExecution($result->state === 'succeeded' && $result->domain->provider_domain_id === '988'
        && count($writes) === 1, 'Known-order reconciliation failed fresh initial anchoring or resent registration.');
    executionNativeDates($result->domain);
    executionSafeCost($result);
}

// Initial lifecycle withdrawal stops Purchase/transfer at claim and final dispatch.
executionClock();
$name = 'initial-cost-overrun.com';
$fixtures[$name] = ['expiry' => '2026-12-01', 'price' => '10.00', 'actual' => '13.00', 'available' => true,
    'domain_id' => '990', 'product_type' => 'Register'];
$calls = []; $writes = [];
$quote = $orders->quote($user, $name);
$invoice = $orders->place($user, $quote['intent_id'], $contacts);
ExtensionHelper::addPayment($invoice, 'Stripe', '15.00', transactionId: 'execution-initial-overrun');
$overrun = Operation::where('invoice_id', $invoice->id)->firstOrFail();
$before = executionNativeMoney();
$result = $operations->execute($overrun->id);
checkExecution($result->state === 'succeeded' && $result->confirmed_expires_at->format('Y-m-d') === '2027-12-01'
    && $result->cost['actual']['state'] === 'confirmed' && $result->cost['actual']['amount'] === '13.00',
    'Supported actual-cost overrun misclassified successful registrar expiry or lost the charged cost.');
checkExecution($result->domain->renewal_policy['automatic_spend_paused'] === true
    && $result->domain->observation['cost_health']['state'] === 'actual_cost_exceeded'
    && $before === executionNativeMoney(), 'Confirmed overrun failed to fence later automatic spending or changed customer money.');
$customer = json_encode((new Paymenter\Extensions\Servers\Enom\Services\DomainManager($operations))->snapshot($user, $result->domain_id), JSON_THROW_ON_ERROR);
checkExecution(!str_contains($customer, 'actual_cost_exceeded') && !str_contains($customer, 'cost_health')
    && !str_contains($customer, '13.00'), 'Internal reseller actual-cost incident leaked into customer domain presentation.');
executionNativeDates($result->domain);
executionSafeCost($result);

// Initial lifecycle withdrawal stops Purchase/transfer at claim and final dispatch.
foreach (['register', 'transfer'] as $action) {
foreach (['before_claim', 'during_check'] as $phase) {
    foreach ([Service::STATUS_CANCELLED, Service::STATUS_SUSPENDED] as $status) {
        executionClock();
        $name = 'initial-'.$action.'-'.str_replace('_', '-', $phase).'-'.$status.'.com';
        $fixtures[$name] = ['expiry' => '2026-12-01', 'price' => '10.00', 'available' => $action === 'register', 'domain_id' => '989', 'product_type' => 'Register'];
        $quote = $orders->quote($user, $name, $action);
        $invoice = $orders->place($user, $quote['intent_id'], $contacts, $action === 'transfer' ? 'synthetic-test-epp' : null);
        ExtensionHelper::addPayment($invoice, 'Stripe', '15.00', transactionId: 'execution-'.$action.'-'.$phase.'-'.$status);
        $initial = Operation::where('invoice_id', $invoice->id)->firstOrFail();
        $calls = []; $writes = [];
        $before = executionNativeMoney();
        if ($phase === 'before_claim') { $initial->domain->service->update(['status' => $status]); }
        else {
            $during = function ($command) use ($initial, $status): void {
                if ($command === 'Check') { $initial->domain->service->update(['status' => $status]); }
            };
        }
        $result = $operations->execute($initial->id);
        $during = null;
        checkExecution($result->state === 'held' && $writes === [] && $result->domain->service->status === $status,
            'Initial '.$status.' lifecycle sent '.$action.' during '.$phase.'.');
        checkExecution($phase !== 'before_claim' || $calls === [], 'Initial invalid lifecycle caused provider reads before claim.');
        checkExecution($before === executionNativeMoney() && $result->domain->provider_expires_at === null,
            'Initial lifecycle refusal changed native finance or confirmed registrar expiry.');
    }
}
}

// Worst admission path: six reads consume their 25-second request budgets.
// The expired acknowledgement stays pending after its one dispatch request.
$slow=$make();executionClock('2026-12-09');$slowStart=Carbon\Carbon::now()->copy();$slowMoney=executionNativeMoney();
$slowReads=[];$slowClaim=null;
$during=function(string $command)use($slow,&$slowReads,&$slowClaim):void{
    Carbon\Carbon::setTestNow(Carbon\Carbon::now()->addSeconds(25));
    if(in_array($command,['GetDomainInfo','GetExtendInfo','Check','GetExpiredDomains','GetRenew','GetBalance'],true))$slowReads[]=$command;
    if($command==='GetDomainInfo')$slowClaim=$slow->fresh()->lease_expires_at->getTimestamp()-$slow->fresh()->started_at->getTimestamp();
};
$slowResult=$operations->execute($slow->id);$during=null;
$elapsed=Carbon\Carbon::now()->getTimestamp()-$slowStart->getTimestamp();
checkExecution(count($slowReads)===6&&$elapsed===175,'Worst read budget differs: '.json_encode(['elapsed'=>$elapsed,'reads'=>$slowReads,'state'=>$slowResult->state,'code'=>$slowResult->safe_code,'calls'=>$calls]).'.');
checkExecution($slowResult->state==='pending'&&count($writes)===1&&$writes[0][0]==='UpdateExpiredDomains',
    'Slow admitted renewal lost its lease or failed bounded registrar completion.');
$executionJob=new Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation($slow->id);
checkExecution($executionJob->timeout>$elapsed&&$executionJob->timeout<Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation::CLAIM_LEASE_SECONDS
    &&$slowClaim===Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation::CLAIM_LEASE_SECONDS,
    'Worker timeout and claimed lease cannot cover the bounded provider path.');
checkExecution($slowMoney===executionNativeMoney(),'Slow registrar reads changed native financial history.');

// Ordinary renewal performs five admission reads, one send and two confirming reads.
$slowOrdinary=$make();$slowStart=Carbon\Carbon::now()->copy();$slowMoney=executionNativeMoney();
$during=fn()=>Carbon\Carbon::setTestNow(Carbon\Carbon::now()->addSeconds(25));
$slowResult=$operations->execute($slowOrdinary->id);$during=null;$elapsed=Carbon\Carbon::now()->getTimestamp()-$slowStart->getTimestamp();
checkExecution($elapsed===200&&$slowResult->state==='succeeded'&&count($writes)===1&&$writes[0][0]==='Extend',
    'Slow ordinary completion lost the claimed lease before its expiry/cost readback.');
checkExecution($executionJob->timeout>$elapsed&&$slowMoney===executionNativeMoney(),'Worker budget or native finance failed slow confirmed renewal.');


// Dispatch needs a complete request timeout left in its existing claim.
foreach ([24, 25] as $remaining) {
    $nearLease = $make(); $claimStart = Carbon\Carbon::now()->copy(); $money = executionNativeMoney();
    $during = function (string $command) use ($remaining, $claimStart): void {
        if ($command === 'GetBalance') { Carbon\Carbon::setTestNow($claimStart->copy()->addSeconds(Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation::CLAIM_LEASE_SECONDS - $remaining)); }
    };
    $nearResult = $operations->execute($nearLease->id); $during = null;
    if ($remaining === 24) {
        checkExecution($nearResult->state === 'held' && $nearResult->safe_code === 'dispatch_lease_insufficient' && $writes === [] && !isset($nearResult->policy['dispatch_attempted_at']), 'Short claim sent or sealed a registrar write.');
    } else {
        checkExecution($nearResult->state === 'succeeded' && count($writes) === 1 && $writes[0][0] === 'Extend', 'Exact full-request lease boundary refused an otherwise valid send.');
    }
    checkExecution($money === executionNativeMoney(), 'Lease dispatch fence altered native finance.');
}

// Actual ordinary Extend cost is accepted only through the evidenced singleton Renew item.
foreach (['8.95', '16.00'] as $actual) {
    $costed = $make(options: ['product_type' => 'Renew', 'actual' => $actual]);
    $money = executionNativeMoney();
    $costResult = $operations->execute($costed->id);
    checkExecution($costResult->state === 'succeeded' && count($writes) === 1 && $writes[0][0] === 'Extend'
        && $costResult->cost['actual']['state'] === 'confirmed' && $costResult->cost['actual']['amount'] === $actual
        && $costResult->cost['actual']['currency'] === 'USD', 'Evidenced ordinary Renew item failed actual-cost confirmation.');
    checkExecution(($costResult->domain->renewal_policy['automatic_spend_paused'] ?? false) === ($actual === '16.00')
        && $money === executionNativeMoney(), 'Ordinary renewal overrun pause or native finance preservation failed.');
    executionNativeDates($costResult->domain);
    executionSafeCost($costResult);
}

// A temporarily incomplete ordinary Renew cost can complete later without another registrar write.
$lateCost = $make(options: ['product_type' => 'Renew', 'actual' => '8.95', 'unknown_actual' => true]);
$money = executionNativeMoney();
$lateResult = $operations->execute($lateCost->id);
checkExecution($lateResult->state === 'succeeded' && $lateResult->cost['actual']['state'] === 'unconfirmed'
    && $lateResult->cost['actual_attempts'] === 1 && !isset($lateResult->cost['actual']['reason']), 'Supported incomplete renewal cost was permanently classified unsupported.');
$fixtures[$lateResult->domain->domain]['unknown_actual'] = false;
$lateResult = $operations->reconcile($lateResult->id);
checkExecution($lateResult->state === 'succeeded' && $lateResult->cost['actual']['state'] === 'confirmed'
    && $lateResult->cost['actual']['amount'] === '8.95' && $lateResult->cost['actual_attempts'] === 2
    && count($writes) === 1 && $money === executionNativeMoney(), 'Later matched renewal cost was lost, resent, or changed financial history.');
$operations->reconcile($lateResult->id);
checkExecution($lateResult->fresh()->cost['actual_attempts'] === 2 && count($writes) === 1, 'Confirmed renewal cost kept polling.');

$boundedCost = $make(options: ['product_type' => 'Renew', 'unknown_actual' => true]);
$money = executionNativeMoney(); $boundedResult = $operations->execute($boundedCost->id);
for ($i = 0; $i < 8; $i++) { $operations->reconcile($boundedResult->id); }
checkExecution($boundedResult->fresh()->cost['actual_attempts'] === 5
    && $boundedResult->fresh()->cost['actual']['reason'] === 'cost_poll_limit_reached'
    && count($writes) === 1 && $money === executionNativeMoney(), 'Supported unknown renewal cost exceeded its five-read budget or resent.');

// A final guard/configuration failure is known unsent only under the new durable contract.
$guardInterrupted = $make(); $money = executionNativeMoney(); $guardFails = false;
Operation::updated(function (Operation $updated) use ($guardInterrupted, &$guardFails): void {
    if ($updated->id === $guardInterrupted->id && ($updated->policy['provider_command'] ?? null) === 'Extend') { $guardFails = true; }
});
$throwingOperations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'synthetic-token-do-not-store', $fake)),
    function () use (&$guardFails, &$configuration): array {
        if ($guardFails) { throw new RuntimeException('Synthetic configuration changed before send.'); }
        return $configuration;
    });
$guardResult = $throwingOperations->execute($guardInterrupted->id); $during = null;
checkExecution($guardResult->state === 'held' && $guardResult->safe_code === 'pre_send_interrupted'
    && ($guardResult->policy['dispatch_marker_version'] ?? null) === 1 && $writes === []
    && !isset($guardResult->policy['dispatch_attempted_at']), 'Pre-send guard interruption stranded or sent a newly contracted request.');
checkExecution($money === executionNativeMoney(), 'Pre-send guard interruption changed finance.');
checkExecution($operations->readmitHeld($guardResult->id, $staff, 'Reviewed unsent interruption')->id === $guardResult->id,
    'Known unsent request could not reuse its retained paid obligation.');

// Simulate the process dying in admission and expiring its persisted claim.
foreach (['execute', 'reconcile', 'sweep'] as $recovery) {
    foreach (['contracted', 'legacy', 'sealed', 'execution', 'order'] as $kind) {
        $dead = $make(); $money = executionNativeMoney();
        $policy = $dead->policy;
        if ($kind === 'legacy') { unset($policy['dispatch_marker_version']); }
        if ($kind === 'sealed') { $policy['dispatch_attempted_at'] = now()->toIso8601String(); }
        $dead->update(['state' => 'running', 'policy' => $policy, 'started_at' => now()->subSeconds(400),
            'lease_expires_at' => now()->subSecond(), 'cost' => $kind === 'execution' ? ['execution' => ['status' => 'unknown']] : null,
            'provider_order_id' => $kind === 'order' ? '987654321' : null]);
        if ($recovery === 'sweep') {
            Illuminate\Support\Facades\Cache::put('enom:sweep:operations', $dead->id - 1);
            $operations->sweep(); $recovered = $dead->fresh();
        } else { $recovered = $operations->$recovery($dead->id); }
        checkExecution($recovered->state === ($kind === 'contracted' ? 'held' : 'uncertain')
            && $recovered->safe_code === ($kind === 'contracted' ? 'pre_send_interrupted' : 'stale_claim'),
            'Expired '.$kind.' claim misclassified during '.$recovery.'.');
        checkExecution($writes === [] && $money === executionNativeMoney(), 'Stale claim recovery sent or changed finance.');
    }
}

// A failed dispatch marker database write must abort the send and be recoverable as unsent.
$dbInterrupted = $make(); $money = executionNativeMoney(); $failMarker = true;
DB::connection()->beforeExecuting(function (string $sql) use (&$failMarker): void {
    if ($failMarker && str_starts_with($sql, 'update "enom_operations" set "policy"') && str_contains($sql, 'and "policy" =')) {
        $failMarker = false;
        throw new RuntimeException('Synthetic database failure before the dispatch seal committed.');
    }
});
$dbResult = $operations->execute($dbInterrupted->id);
checkExecution(!$failMarker && $dbResult->state === 'held' && $dbResult->safe_code === 'pre_send_interrupted'
    && !isset($dbResult->policy['dispatch_attempted_at']) && $writes === [], 'Failed marker persistence permitted a write or stranded its paid obligation.');
checkExecution($money === executionNativeMoney(), 'Dispatch seal database failure changed finance.');

// Concurrent state transitions and replacement claim identity defeat the original dispatch CAS.
foreach (['held', 'disposed', 'replacement_claim'] as $raceKind) {
    $raced = $make(); $money = executionNativeMoney();
    $during = function (string $command) use ($raced, $raceKind): void {
        if ($command !== 'GetBalance') { return; }
        $current = $raced->fresh();
        if ($raceKind === 'replacement_claim') {
            $policy = $current->policy; $policy['dispatch_claim_token'] = str_repeat('f', 32);
            $current->update(['policy' => $policy, 'started_at' => now()->addSecond(), 'lease_expires_at' => now()->addSeconds(400)]);
        } else { $current->update(['state' => $raceKind, 'safe_code' => 'synthetic_concurrent_transition', 'lease_expires_at' => null]); }
    };
    $raceResult = $operations->execute($raced->id); $during = null;
    checkExecution($writes === [] && !isset($raceResult->policy['dispatch_attempted_at'])
        && $raceResult->state === ($raceKind === 'replacement_claim' ? 'running' : $raceKind), 'Final dispatch overwrote concurrent '.$raceKind.' transition.');
    checkExecution($money === executionNativeMoney(), 'Concurrent dispatch transition changed finance.');
}

// Exceptions after the marker remain uncertain and are never re-admitted or resent.
$postMarker = $make(); $money = executionNativeMoney();
$during = function (string $command) use ($postMarker): void {
    if ($command === 'Extend') {
        checkExecution(isset($postMarker->fresh()->policy['dispatch_attempted_at']), 'Write transport entered before its durable marker committed.');
        throw new RuntimeException('Synthetic process failure after durable seal.');
    }
};
$postResult = $operations->execute($postMarker->id); $during = null;
checkExecution($postResult->state === 'uncertain' && isset($postResult->policy['dispatch_attempted_at'])
    && $money === executionNativeMoney(), 'Post-marker interruption was classified unsent or changed finance.');
$operations->execute($postMarker->id);
checkExecution($postMarker->fresh()->state === 'uncertain' && $writes === [], 'Post-marker interruption was automatically replayed.');

// Captured date-only provider pricing can fund one ordinary sponsorship without rewriting its UTC obligation.
$calendarSponsor=$make(kind:'sponsored');$calendarDomain=$calendarSponsor->domain;
$calendarDomain->update(['provider_expires_at'=>'2028-10-13']);
$calendarPolicy=$calendarSponsor->policy;$calendarPolicy['calendar']=['expiry'=>'2028-10-13','issue'=>'2028-09-22','due'=>'2028-10-06'];
$calendarSponsor->update(['base_expires_at'=>'2028-10-13','expected_expires_at'=>'2029-10-13','policy'=>$calendarPolicy]);
$fixtures[$calendarDomain->domain]=['expiry'=>'2028-10-13','info_expiry'=>'10/12/2028 11:14:00 PM','extend_expiry'=>'10/12/2028','price'=>'8.95','balance'=>'49,978.69','advance'=>false];
$calendarMoney=executionNativeMoney();$calendarResult=$operations->execute($calendarSponsor->id);
checkExecution($calendarResult->state==='uncertain'&&$calendarResult->safe_code==='expiry_unconfirmed'&&count($writes)===1&&$writes[0][0]==='Extend'
    &&$calendarResult->cost['quote']['complete']&&$calendarResult->cost['quote']['currency']==='USD'&&$calendarResult->cost['quote']['base_expires_at']==='2028-10-13',
    'Exact date-only sponsorship did not admit a single ordinary Extend or fabricated successful expiry');
checkExecution($calendarMoney===executionNativeMoney()&&$calendarDomain->fresh()->provider_expires_at->format('Y-m-d')==='2028-10-13'
    &&$calendarDomain->fresh()->service->expires_at===null,'Date-only sponsorship fabricated financial rows or new registrar/native expiry.');
// Exact local timestamps before/after one year close both execution and reconciliation confirmation paths.
foreach(['execute','reconcile'] as $calendarPath){
    $preciseSponsor=$make(kind:'sponsored');$preciseDomain=$preciseSponsor->domain;$preciseDomain->update(['provider_expires_at'=>'2028-10-13']);
    $precisePolicy=$preciseSponsor->policy;$precisePolicy['calendar']=['expiry'=>'2028-10-13','issue'=>'2028-09-22','due'=>'2028-10-06'];
    $preciseSponsor->update(['base_expires_at'=>'2028-10-13','expected_expires_at'=>'2029-10-13','policy'=>$precisePolicy]);
    $fixtures[$preciseDomain->domain]=['expiry'=>'2028-10-13','info_expiry'=>'10/12/2028 11:14:00 PM','extend_expiry'=>'10/12/2028',
        'expiry_read'=>'10/12/2028 11:14:00 PM','price'=>'8.95','balance'=>'49,978.69','product_type'=>'Renew','order_expiry'=>'10/12/2029 11:14:00 PM',
        'next_expiry'=>'2029-10-13','next_info_expiry'=>'10/12/2029 11:14:00 PM','next_extend_expiry'=>'10/12/2029',
        'next_expiry_read'=>'10/12/2029 11:14:00 PM','advance'=>$calendarPath==='execute',
        'ack_expiry'=>$calendarPath==='execute'?'10/12/2029 11:14:00 PM':'10/12/2028 11:14:00 PM'];
    $preciseMoney=executionNativeMoney();$preciseResult=$operations->execute($preciseSponsor->id);
    if($calendarPath==='reconcile'){
        checkExecution($preciseResult->state==='uncertain'&&$preciseResult->safe_code==='expiry_unconfirmed'&&count($writes)===1,
            'Initial unconfirmed expiry was fabricated or failed to retain the one known Extend.');
        $f=&$fixtures[$preciseDomain->domain];$f['expiry']='2029-10-13';$f['info_expiry']='10/12/2029 11:14:00 PM';
        $f['extend_expiry']='10/12/2029';$f['expiry_read']='10/12/2029 11:14:00 PM';unset($f);
        $preciseResult=$operations->reconcile($preciseSponsor->id);
    }
    checkExecution($preciseResult->state==='succeeded'&&$preciseResult->safe_code==='expiry_confirmed'&&count($writes)===1&&$writes[0][0]==='Extend'
        &&$preciseResult->confirmed_expires_at->format('Y-m-d')==='2029-10-13'&&$preciseDomain->fresh()->provider_expires_at->format('Y-m-d')==='2029-10-13',
        'Exact LA one-year precise expiry failed '.$calendarPath.' completion or replayed Extend.');
    checkExecution($preciseResult->cost['actual']['state']==='confirmed'&&$preciseResult->cost['actual']['amount']==='8.95'&&$preciseResult->cost['actual']['currency']==='USD'
        &&$preciseResult->cost['quote']['expiry_source']==='GetDomainInfo_precise_la_date_reconciled'
        &&$preciseResult->cost['quote']['expires_at']==='2028-10-13T06:14:00.000000+00:00',
        'Precise completed '.$calendarPath.' renewal lost actual singleton Renew cost or quote expiry provenance.');
    $afterObserver=new Paymenter\Extensions\Servers\Enom\Services\DomainObservation(new EnomApi(new RegistrarClient('offline-account','synthetic-token-do-not-store',$fake,null,true)),fn()=>$configuration);
    checkExecution($afterObserver->observeRenewalQuote($preciseDomain->id)==='quoted'
        &&$preciseDomain->fresh()->observation['renewal_quote']['base_expires_at']==='2029-10-13'
        &&$preciseDomain->fresh()->observation['renewal_quote']['expires_at']==='2029-10-13T06:14:00.000000+00:00',
        'Exact after-renewal GetInfo/GetExtendInfo local shapes failed read-only quote confirmation.');
    $operations->execute($preciseSponsor->id);$operations->reconcile($preciseSponsor->id);
    checkExecution(count($writes)===1&&$preciseMoney===executionNativeMoney()&&$preciseDomain->fresh()->service->expires_at===null,
        'Completed date reconciliation replayed provider spending or changed native financial history/date.');
}
// 0.3 complimentary renewals: the quote must be complete and at or under complimentary_cost_cap; a confirmed overrun pauses spending.
executionClock();
$server->settings()->create(['key' => 'complimentary_cost_cap', 'value' => '11.00']);
$complimentary = $make('complimentary');
checkExecution($complimentary->state === 'queued' && $complimentary->funding_basis === 'complimentary' && $complimentary->invoice_id === null
    && $complimentary->amount === '0.00' && $complimentary->policy['complimentary_cap_minor'] === 1100 && (int) $complimentary->authorized_by === $staff->id,
    'Complimentary renewal was not queued with its cost limit.');
checkExecution($billing->complimentary($complimentary->domain_id)?->id === $complimentary->id && $billing->complimentary($complimentary->domain_id, $staff)?->id === $complimentary->id,
    'Repeat in the same cycle created another complimentary renewal.');
$before = executionNativeMoney();
$result = $operations->execute($complimentary->id);
if ($result->state === 'pending') { $result = $operations->reconcile($complimentary->id); }
checkExecution($result->state === 'succeeded' && count($writes) === 1 && $writes[0][0] === 'Extend' && $result->invoice_id === null, 'Complimentary renewal under the limit failed: '.$result->safe_code);
checkExecution($before === executionNativeMoney(), 'Complimentary renewal created customer financial activity.');
foreach ([[['price' => '11.01'], 'complimentary_cost_above_limit'], [['unknown_quote' => true], 'complimentary_quote_unconfirmed']] as [$options, $code]) {
    $over = $make('complimentary', options: $options);
    $result = $operations->execute($over->id);
    checkExecution($result->state === 'held' && $result->safe_code === $code && $writes === [], 'Complimentary renewal was sent with '.json_encode($options).': '.$result->safe_code);
}
$atLimit = $make('complimentary', options: ['price' => '11.00']);
checkExecution($operations->execute($atLimit->id)->state === 'succeeded' && count($writes) === 1, 'Quote exactly at the limit was refused.');
// Revoked before sending: no longer funded, held without any registrar request. After sending, the outcome is still recorded.
$revoked = $make('complimentary');
$revoked->domain->update(['price_mode' => 'standard']);
checkExecution(!$operations->funded($revoked->fresh()), 'Revoked complimentary renewal stayed funded before dispatch.');
$result = $operations->execute($revoked->id);
checkExecution($result->state === 'held' && $calls === [] && $writes === [], 'Revoked complimentary renewal reached Enom.');
$sent = $make('complimentary');
$sent->update(['policy' => $sent->policy + ['dispatch_attempted_at' => now()->toIso8601String()]]);
$sent->domain->update(['price_mode' => 'standard']);
checkExecution($operations->funded($sent->fresh()), 'Revocation after dispatch stopped the outcome being recorded.');
$recorded = $make('complimentary');
$recorded->domain->update(['price_mode' => 'custom']);
$recorded->domain->service->update(['price' => '20.00']);
checkExecution(!$operations->funded($recorded->fresh()), 'Complimentary renewal stayed funded after the service became paid.');
// A confirmed actual charge above the limit pauses further automatic spending for that domain.
$overrun = $make('complimentary', options: ['product_type' => 'Renew', 'actual' => '12.50']);
$result = $operations->execute($overrun->id);
if ($result->state === 'pending') { $result = $operations->reconcile($overrun->id); }
checkExecution($result->state === 'succeeded' && ($result->cost['actual']['state'] ?? null) === 'confirmed' && $result->cost['actual']['amount'] === '12.50', 'Overrun fixture did not confirm its actual charge.');
checkExecution(($result->domain->fresh()->renewal_policy['automatic_spend_paused'] ?? false) === true
    && ($result->domain->fresh()->observation['cost_health']['state'] ?? null) === 'actual_cost_exceeded', 'Actual complimentary overrun did not pause automatic spending.');
// Complimentary funding cannot be read-admitted by a staff member lacking admin.enom.fund.
$heldForFund = $make('complimentary', options: ['price' => '11.01']);
$operations->execute($heldForFund->id);
$manageOnly = App\Models\User::create(['email' => 'manage-only@example.invalid', 'password' => bcrypt('synthetic'),
    'role_id' => App\Models\Role::create(['name' => 'Manage only', 'permissions' => ['admin.enom.manage']])->id]);
checkExecution(!$operations->canReadmitHeld($heldForFund->fresh(), $manageOnly) && $operations->canReadmitHeld($heldForFund->fresh(), $staff), 'Readmission of a held complimentary renewal ignored admin.enom.fund.');
checkExecution($nativeSchema == DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name"), 'Native schema changed.');
$successful = true;
echo json_encode(['checks' => $checks, 'status' => 'passed', 'database' => 'isolated-sqlite-memory',
    'provider' => 'injected-execution-and-readback-fixtures', 'actual_provider_writes' => 0,
    'native_finance_and_schema_preserved' => true], JSON_PRETTY_PRINT).PHP_EOL;
