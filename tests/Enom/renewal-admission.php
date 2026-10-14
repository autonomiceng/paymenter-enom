<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real stock Laravel/models, synthetic environment, in-memory SQLite, injected fake provider.
// Never load an installed app or any .env. Run: php tests/Enom/renewal-admission.php
$repo = dirname(__DIR__, 2);
$successful = false;
register_shutdown_function(function () use (&$successful) { if (!$successful) { exit(1); } });
$release = paymenterTestApp();
if (!is_file($release.'/vendor/autoload.php') || is_file($release.'/.env')) { throw new RuntimeException('A pristine release with vendor and no environment file is required.'); }
$testRoot = $repo.'/.scratch/enom-renewal-admission-test-'.bin2hex(random_bytes(5));
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainBilling;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;

$checks = 0;
function checkAdmission(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function atAdmission(string $day): void { Carbon\Carbon::setTestNow($day.' 12:00:00'); }
config(['app.version' => 'development']);
Illuminate\Support\Facades\Http::preventStrayRequests();
$nativeSchema = DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name");
$user = App\Models\User::create(['email' => 'billing-owner@example.invalid', 'password' => bcrypt('synthetic')]);
$other = App\Models\User::create(['email' => 'billing-other@example.invalid', 'password' => bcrypt('synthetic')]);
$role = App\Models\Role::create(['name' => 'Synthetic Enom staff', 'permissions' => ['admin.enom.manage', 'admin.enom.fund']]);
$staff = App\Models\User::create(['email' => 'billing-staff@example.invalid', 'password' => bcrypt('synthetic'), 'role_id' => $role->id]);
App\Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$']);
$server = App\Models\Server::create(['name' => 'Synthetic billing', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$server->settings()->create(['key' => 'billing_enabled', 'value' => true, 'type' => 'boolean']);
$server->settings()->create(['key' => 'operations_enabled', 'value' => true, 'type' => 'boolean']);
$category = App\Models\Category::create(['name' => 'Domain billing test', 'slug' => 'domain-billing-test']);
$product = App\Models\Product::create(['name' => 'Managed domain', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'One year', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$plan->prices()->create(['currency_code' => 'USD', 'price' => 15, 'setup_fee' => 0]);
App\Models\Gateway::create(['name' => 'Synthetic gateway', 'type' => 'gateway', 'extension' => 'Stripe', 'enabled' => true]);
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Services\RenewalAdmission;

$configuration = ['uid' => 'offline-account', 'token' => 'synthetic-only', 'sandbox' => true,
    'operations_enabled' => true, 'grace_renewals_enabled' => true];
$operations = new DomainOperations(null, function (int $id, bool $readOnly = false) use (&$configuration, $server): array {
    checkAdmission($id === $server->id && !$readOnly, 'Admission used the wrong provider guard.');
    return $configuration;
});
$admission = new RenewalAdmission($operations);
$billing = new DomainBilling($operations);
$app->instance(DomainOperations::class, $operations);
$app->instance(DomainBilling::class, $billing);
Event::listen(App\Events\Invoice\Paid::class, fn ($event) => $operations->acceptPaidInvoice($event->invoice));
$counter = 0;
$make = function (string $kind = 'paid', string $expiry = '2026-12-01', string $settlement = '15.00') use (&$counter, $user, $staff, $server, $product, $plan, $billing): Operation {
    atAdmission('2026-11-10');
    $counter++;
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
        'price' => $kind === 'sponsored' ? '0.00' : '15.00', 'quantity' => 1,
        'currency_code' => 'USD', 'status' => 'active', 'expires_at' => null]);
    $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id,
        'domain' => 'admission-'.$counter.'.com', 'sld' => 'admission-'.$counter, 'tld' => 'com',
        'provider_domain_id' => '456', 'status' => 'active', 'provider_expires_at' => $expiry,
        'billing_managed' => true, 'authority_generation' => 1, 'confirmed_revision' => 1,
        'renewal_policy' => ['enabled' => true]]);
    if ($kind === 'sponsored') {
        $domain->update(['sponsorship' => ['enabled' => true, 'standing' => true, 'approved_by' => $staff->id,
            'reason' => 'Isolated admission test', 'limit_minor' => 1200, 'currency' => 'USD', 'term' => 1],
            'observation' => ['renewal_quote' => ['state' => 'validated', 'complete' => true, 'domain' => $domain->domain,
                'action' => 'renew', 'term' => 1, 'base_expires_at' => $expiry, 'amount' => '10.00', 'currency' => 'USD',
                'grace_eligible' => false, 'observed_at' => now()->toIso8601String(), 'source' => 'isolated-fixture']]]);
        return $billing->sponsor($domain->id);
    }
    $invoice = $billing->invoice($domain->id, $user);
    if ($settlement !== '0.00') {
        ExtensionHelper::addPayment($invoice, 'Stripe', $settlement, transactionId: 'admission-payment-'.$counter);
    }
    return Operation::where('invoice_id', $invoice->id)->firstOrFail();
};
function admissionEnvelope(string $command, string $body): string {
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
}
function admissionDatabase(): string {
    $rows = [];
    foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
        $rows[$table->name] = DB::table($table->name)->orderBy('id')->get()->toArray();
    }
    return hash('sha256', serialize($rows));
}
$read = function (Operation $operation, array $options = [], ?callable $during = null, bool $invariant = true) use ($admission): array {
    $domain = $operation->fresh()->domain;
    $name = htmlspecialchars($domain->domain, ENT_XML1);
    $expiry = $options['expiry'] ?? $domain->provider_expires_at->format('Y-m-d');
    $price = $options['price'] ?? '10.00';
    $uid = $options['uid'] ?? 'offline-account';
    $id = $options['id'] ?? '456';
    $calls = [];
    $api = new EnomApi(new RegistrarClient('offline-account', 'synthetic-only', function (string $command, array $parameters) use (&$calls, $name, $expiry, $price, $uid, $id, $options, $during): string {
        $calls[] = $command;
        checkAdmission(in_array($command, ['GetDomainInfo', 'GetExtendInfo', 'Check', 'GetExpiredDomains', 'GetRenew', 'GetBalance'], true), 'Admission attempted a registrar mutation.');
        if ($during) { $during($command); }
        if (($options['fail'] ?? null) === $command) { throw new RuntimeException('private transport diagnostic'); }
        $body = match ($command) {
            'GetDomainInfo' => '<GetDomainInfo><domainname domainnameid="'.$id.'">'.$name.'</domainname><status><expiration>'.($options['info_expiry'] ?? $expiry).'</expiration><belongs-to>'.$uid.'</belongs-to></status></GetDomainInfo>',
            'GetExtendInfo' => '<Expiration>'.($options['extend_expiry'] ?? $expiry).'</Expiration><RegistrarHold>'.($options['hold'] ?? 'false').'</RegistrarHold><MaxExtension>'.($options['max'] ?? '9').'</MaxExtension>'.(isset($options['unknown_price']) ? '' : '<Price>'.$price.'</Price>'),
            'Check' => '<Domains><Domain><Name>'.($options['check_name'] ?? $name).'</Name><RRPCode>211</RRPCode><IsPremium>'.($options['premium'] ?? 'false').'</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Prices><Currency>'.($options['currency'] ?? 'USD').'</Currency><Renewal>'.$price.'</Renewal></Prices></Domain></Domains>',
            'GetExpiredDomains' => '<DomainCount>'.($options['expired_count'] ?? '1').'</DomainCount><DomainDetail><DomainName>'.$name.'</DomainName><DomainNameID>'.($options['expired_id'] ?? $id).'</DomainNameID><Status>'.($options['expired_status'] ?? 'Expired').'</Status><Expiration-Date>'.($options['expired_expiry'] ?? $expiry).'</Expiration-Date></DomainDetail>',
            'GetRenew' => '<Auto-Renew>'.($options['auto_renew'] ?? 'false').'</Auto-Renew>',
            'GetBalance' => '<AvailableBalance>'.($options['balance'] ?? '100.00').'</AvailableBalance>',
        };
        return admissionEnvelope($command, $body);
    }));
    $before = admissionDatabase();
    $result = $admission->check($operation, $api);
    if ($invariant) { checkAdmission($before === admissionDatabase(), 'Admission changed persistent native or extension state.'); }
    checkAdmission(is_bool($result['allowed']) && is_string($result['code']) && array_key_exists('quote', $result) && array_key_exists('command', $result), 'Admission result lacks its executor contract.');
    if (!$result['allowed']) { checkAdmission($result['command'] === null, 'Refused admission supplied a mutation command.'); }
    return [$result, $calls];
};

$paid = $make();
[$result, $calls] = $read($paid);
checkAdmission($result['allowed'] && $result['command'] === 'Extend' && $result['quote']['complete']
    && $result['quote']['amount'] === '10.00' && $result['provider_domain_id'] === '456', 'Full settled ordinary renewal failed fresh admission.');
checkAdmission($result['account_fingerprint'] === hash('sha256', 'sandbox:offline-account'), 'Account fingerprint disagrees with observer namespace.');
checkAdmission($calls === ['GetDomainInfo', 'GetExtendInfo', 'Check', 'GetRenew', 'GetBalance'], 'Ordinary admission changed its bounded read path.');
[$result] = $read($paid, ['unknown_price' => true]);
checkAdmission($result['allowed'] && !$result['quote']['complete'] && $result['command'] === 'Extend', 'Unknown pre-expiry ordinary cost was fabricated or blocked.');
[$result] = $read($paid, ['currency' => '']);
checkAdmission($result['allowed'] && $result['quote']['complete'] && $result['quote']['currency'] === 'USD'
    && $result['quote']['currency_source'] === 'documented_check_prices_usd_default', 'Documented empty Check currency did not produce a USD quote.');
foreach (['EUR', 'garbage'] as $currency) {
    [$result] = $read($paid, ['currency' => $currency]);
    checkAdmission(!$result['allowed'] && $result['code'] === 'renewal_currency_unaccepted', 'Explicit unsupported Check currency admitted ordinary spending.');
}
[$result] = $read($paid, ['balance' => '49,978.69']);
checkAdmission($result['allowed'], 'Actual grouped USD balance refused affordable ordinary renewal.');
foreach (['9.99','9,99.00','49.978,69','$49,978.69','-49,978.69','049,978.69'] as $balance) {
    [$result] = $read($paid, ['balance' => $balance]);
    checkAdmission(!$result['allowed'] && $result['code'] === 'registrar_balance_insufficient_or_unknown', 'Malformed or insufficient balance admitted spending.');
}
// The real date-only price shape agrees with exact authenticated UTC expiry.
$dateOperation=$make(expiry:'2028-10-13');
[$result]=$read($dateOperation,['info_expiry'=>'10/12/2028 11:14:00 PM','extend_expiry'=>'10/12/2028','price'=>'8.95','currency'=>'','balance'=>'49,978.69']);
checkAdmission($result['allowed']&&$result['quote']['complete']&&$result['quote']['currency']==='USD'&&$result['quote']['base_expires_at']==='2028-10-13'
    &&$result['quote']['expiry_source']==='GetDomainInfo_precise_la_date_reconciled'&&$result['quote']['expires_at']==='2028-10-13T06:14:00.000000+00:00',
    'Precise authenticated date-only ordinary admission failed.');
foreach([['info_expiry'=>'10/13/2028 12:00:00 AM'],['info_expiry'=>'10/13/2028'],['id'=>'457'],['extend_expiry'=>'10/11/2028']] as $fault){
    [$result]=$read($dateOperation,array_replace(['info_expiry'=>'10/12/2028 11:14:00 PM','extend_expiry'=>'10/12/2028','price'=>'8.95','currency'=>''],$fault));
    checkAdmission(!$result['allowed'],'Uncorroborated date-only admission passed.');
}
foreach ([['premium' => 'true'], ['check_name' => 'foreign.com'], ['hold' => 'true'], ['max' => '0'], ['extend_expiry' => '2026-12-02'],
    ['auto_renew' => 'true'], ['auto_renew' => ''], ['uid' => 'foreign-account'], ['id' => '457'], ['expiry' => '2026-12-02'],
    ['balance' => '9.99'], ['balance' => ''], ['fail' => 'GetDomainInfo'], ['fail' => 'GetRenew'], ['fail' => 'GetBalance'], ['fail' => 'Check']] as $options) {
    [$result] = $read($paid, $options);
    checkAdmission(!$result['allowed'], 'Unsafe pre-expiry fixture admitted: '.json_encode($options));
}
[$result, $calls] = $read($paid, ['uid' => 'foreign-account']);
checkAdmission($calls === ['GetDomainInfo'] && $result['code'] === 'provider_membership_unconfirmed', 'Foreign membership was followed by more reads.');
$partial = $make(settlement: '5.00');
[$result, $calls] = $read($partial);
checkAdmission(!$result['allowed'] && $calls === [], 'Partial funding caused provider reads.');
$unpaid = $make(settlement: '0.00');
[$result, $calls] = $read($unpaid);
checkAdmission(!$result['allowed'] && $calls === [], 'Unpaid renewal caused provider reads.');

atAdmission('2026-12-09');
[$result, $calls] = $read($paid);
checkAdmission($result['allowed'] && $result['command'] === 'UpdateExpiredDomains' && $result['quote']['grace_eligible'] === true,
    'Confirmed cheaper grace renewal did not select the expired command.');
checkAdmission($calls === ['GetDomainInfo', 'GetExtendInfo', 'Check', 'GetExpiredDomains', 'GetRenew', 'GetBalance'], 'Grace admission omitted its exact expired inventory or added writes.');
[$result] = $read($paid, ['price' => '15.00']);
checkAdmission($result['allowed'], 'Exact original net-price grace boundary refused.');
[$result] = $read($paid, ['hold' => 'true']);
checkAdmission($result['allowed'] && $result['command'] === 'UpdateExpiredDomains',
    'Exact expired inventory identity/expiry failed grace admission with registrar hold.');
foreach ([['price' => '15.01'], ['unknown_price' => true], ['currency' => 'EUR'], ['currency' => 'garbage'], ['expired_status' => 'RGP'],
    ['expired_status' => 'Extended RGP'], ['expired_id' => '457'], ['expired_count' => '2'], ['expired_expiry' => '2026-12-02'],
    ['fail' => 'GetExpiredDomains'], ['balance' => '9.99']] as $options) {
    [$result] = $read($paid, $options);
    checkAdmission(!$result['allowed'], 'Unsafe grace fixture admitted: '.json_encode($options));
}
$configuration['grace_renewals_enabled'] = false;
[$result, $calls] = $read($paid);
checkAdmission(!$result['allowed'] && $result['code'] === 'grace_capability_unaccepted' && $calls === [], 'Default capability gate allowed unaccepted expired provider behavior.');
$configuration['grace_renewals_enabled'] = true;
$paid->domain->update(['renewal_policy' => ['enabled' => true, 'automatic_spend_paused' => true]]);
[$result] = $read($paid);
checkAdmission(!$result['allowed'] && $result['code'] === 'automatic_spend_paused', 'Cost incident pause allowed another grace admission.');
atAdmission('2026-11-10');
[$result] = $read($paid);
checkAdmission(!$result['allowed'] && $result['code'] === 'automatic_spend_paused',
    'Cost incident pause allowed ordinary paid pre-expiry renewal.');
$paid->domain->update(['renewal_policy' => ['enabled' => true]]);

$sponsored = $make('sponsored');
[$result] = $read($sponsored);
checkAdmission($result['allowed'] && $result['command'] === 'Extend' && $sponsored->invoice_id === null, 'Individually approved zero-retail sponsorship failed admission.');
foreach ([['price' => '12.01'], ['unknown_price' => true], ['currency' => 'garbage'], ['balance' => '9.99']] as $options) {
    [$result] = $read($sponsored, $options);
    checkAdmission(!$result['allowed'], 'Unfunded/unknown sponsored cost admitted.');
}
atAdmission('2026-12-09');
[$result] = $read($sponsored);
checkAdmission($result['allowed'] && $result['command'] === 'UpdateExpiredDomains', 'Approved sponsored grace renewal failed.');
$sponsored->domain->update(['sponsorship' => null]);
[$result, $calls] = $read($sponsored);
checkAdmission(!$result['allowed'] && $calls === [], 'Revoked sponsorship retained registrar spending authority.');

foreach ([['confirmed_revision' => 2], ['authority_generation' => 2], ['provider_domain_id' => null], ['status' => 'redemption']] as $change) {
    $candidate = $make();
    $candidate->domain->update($change);
    [$result, $calls] = $read($candidate);
    checkAdmission(!$result['allowed'] && $calls === [], 'Changed local binding caused registrar reads.');
}
$candidate = $make();
$candidate->domain->service->update(['status' => 'cancelled']);
[$result, $calls] = $read($candidate);
checkAdmission(!$result['allowed'] && $calls === [], 'Cancelled native service retained renewal authority.');
$candidate = $make();
App\Models\ServiceCancellation::create(['service_id' => $candidate->service_id, 'type' => 'end_of_period', 'reason' => 'Synthetic cancellation']);
[$result, $calls] = $read($candidate);
checkAdmission(!$result['allowed'] && $calls === [], 'Owner cancellation retained renewal authority.');
$candidate = $make();
$candidate->domain->service->update(['user_id' => $other->id]);
[$result, $calls] = $read($candidate);
checkAdmission(!$result['allowed'] && $calls === [], 'Foreign service ownership retained renewal authority.');
$candidate = $make();
$candidate->update(['expected_expires_at' => '2028-12-01']);
[$result, $calls] = $read($candidate);
checkAdmission(!$result['allowed'] && $calls === [], 'Wrong captured expected term retained renewal authority.');
$candidate = $make();
$candidate->update(['policy' => array_replace($candidate->policy, ['term' => 2])]);
[$result, $calls] = $read($candidate);
checkAdmission(!$result['allowed'] && $calls === [], 'Multiyear policy was silently shortened.');
$candidate = $make();
$candidate->update(['policy' => array_replace($candidate->policy, ['original_net_minor' => '1500'])]);
atAdmission('2026-12-09');
[$result] = $read($candidate);
checkAdmission(!$result['allowed'], 'Grace admission accepted untyped captured minor-unit budget.');
$candidate = $make();
Operation::create(['domain_id' => $candidate->domain_id, 'service_id' => $candidate->service_id,
    'operation_key' => 'admission-held-peer', 'action' => 'renew', 'term' => 1, 'state' => 'held', 'amount' => 15, 'currency' => 'USD']);
[$result, $calls] = $read($candidate);
checkAdmission(!$result['allowed'] && $calls === [], 'Held peer failed to block renewal.');

$candidate = $make();
[$result] = $read($candidate, during: function ($command) use ($candidate): void {
    if ($command === 'GetRenew') { $candidate->domain->update(['confirmed_revision' => 2]); }
}, invariant: false);
checkAdmission(!$result['allowed'] && $result['code'] === 'renewal_binding_changed', 'Concurrent confirmed-state revision bypassed final admission check.');
$candidate = $make();
[$result] = $read($candidate, during: function ($command) use ($candidate): void {
    if ($command === 'GetRenew') {
        App\Models\ServiceCancellation::create(['service_id' => $candidate->service_id, 'type' => 'end_of_period', 'reason' => 'Concurrent synthetic cancellation']);
    }
}, invariant: false);
checkAdmission(!$result['allowed'] && $result['code'] === 'renewal_lifecycle_invalid', 'Cancellation during provider reads bypassed final admission check.');
$candidate = $make();
[$result] = $read($candidate, during: function ($command) use ($candidate): void {
    if ($command === 'GetRenew') { $candidate->domain->service->update(['status' => 'suspended']); }
}, invariant: false);
checkAdmission(!$result['allowed'] && $result['code'] === 'renewal_lifecycle_invalid', 'Native service suspension during reads bypassed final admission check.');
$candidate = $make();
[$result] = $read($candidate, during: function ($command) use (&$configuration): void {
    if ($command === 'GetExtendInfo') { $configuration['uid'] = 'new-offline-account'; }
});
checkAdmission(!$result['allowed'] && $result['code'] === 'renewal_account_changed', 'Changed account bypassed per-read fencing.');
$configuration['uid'] = 'offline-account';
$candidate = $make();
atAdmission('2026-12-09');
[$result] = $read($candidate, during: function ($command) use (&$configuration): void {
    if ($command === 'GetRenew') { $configuration['grace_renewals_enabled'] = false; }
});
checkAdmission(!$result['allowed'] && $result['code'] === 'grace_capability_unaccepted', 'Capability withdrawal during reads bypassed final admission check.');
$configuration['grace_renewals_enabled'] = true;
$candidate = $make();
$candidate->update(['policy' => array_replace($candidate->policy, ['account_fingerprint' => str_repeat('f', 64)])]);
[$result, $calls] = $read($candidate);
checkAdmission(!$result['allowed'] && $calls === [], 'Captured foreign account fingerprint caused provider reads.');

checkAdmission($nativeSchema == DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name"), 'Admission changed native schema.');
$successful = true;
echo json_encode(['checks' => $checks, 'status' => 'passed', 'database' => 'isolated-sqlite-memory', 'provider' => 'injected-read-only-evidence',
    'actual_provider_writes' => 0, 'native_schema_unchanged' => true], JSON_PRETTY_PRINT).PHP_EOL;
