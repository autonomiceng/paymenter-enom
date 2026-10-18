<?php
$enomBootExtension = false;
$enomCreateCurrency = false;
require dirname(__DIR__).'/enom-native-app.php';
require_once dirname(__DIR__).'/enom-cart-order.php';
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
enomTestTld(['server_id'=>$server->id,'tld'=>'com','renew_price'=>'15.00']);
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
$guardViolations = [];
$guardCalls = [];
$actualProviderWrites = 0;
$readCommands = ['GetDomainInfo', 'GetExtendInfo', 'Check', 'GetExpiredDomains', 'GetRenew', 'GetBalance'];
$operations = new DomainOperations(null, function (int $id, bool $readOnly = false) use (&$configuration, $server, &$guardViolations, &$guardCalls): array {
    $guardCalls[] = [$id, $readOnly];
    if ($id !== $server->id) { $guardViolations[] = [$id, $readOnly]; }
    return $configuration;
});
$admission = new RenewalAdmission($operations);
$billing = new DomainBilling($operations);
$app->instance(DomainOperations::class, $operations);
$app->instance(DomainBilling::class, $billing);
Event::listen(App\Events\Invoice\Paid::class, fn ($event) => $operations->acceptPaidInvoice($event->invoice));
$server->settings()->create(['key' => 'complimentary_cost_cap', 'value' => '12.00']);
$counter = 0;
$make = function (string $kind = 'paid', string $expiry = '2026-12-01', string $settlement = '15.00') use (&$counter, $user, $staff, $server, $product, $plan, $billing): Operation {
    atAdmission('2026-11-10');
    $counter++;
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
        'price' => $kind === 'complimentary' ? '0.00' : '15.00', 'quantity' => 1,
        'currency_code' => 'USD', 'status' => 'active', 'expires_at' => null]);
    $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id,
        'domain' => 'admission-'.$counter.'.com', 'sld' => 'admission-'.$counter, 'tld' => 'com',
        'provider_domain_id' => '456', 'status' => 'active', 'provider_expires_at' => $expiry,
        'billing_managed' => true, 'authority_generation' => 1, 'confirmed_revision' => 1, 'price_mode' => 'standard',
        'renewal_policy' => ['enabled' => true]]);
    if ($kind === 'complimentary') {
        $domain->update(['price_mode' => 'complimentary']);
        return $billing->complimentary($domain->id, $staff);
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
$read = function (Operation $operation, array $options = [], ?callable $during = null, bool $invariant = true) use ($admission, &$guardViolations, &$actualProviderWrites, $readCommands, &$guardCalls): array {
    $guardViolations = [];
    $guardCalls = [];
    $domain = $operation->fresh()->domain;
    $name = htmlspecialchars($domain->domain, ENT_XML1);
    $expiry = $options['expiry'] ?? $domain->provider_expires_at->format('Y-m-d');
    $price = $options['price'] ?? '10.00';
    $uid = $options['uid'] ?? 'offline-account';
    $id = $options['id'] ?? '456';
    $calls = [];
    $api = new EnomApi(new RegistrarClient('offline-account', 'synthetic-only', function (string $command, array $parameters) use (&$calls, $name, $expiry, $price, $uid, $id, $options, $during, &$actualProviderWrites, $readCommands): string {
        $calls[] = $command;
        if (!in_array($command, $readCommands, true)) { $actualProviderWrites++; }
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
    checkAdmission($guardViolations === [], 'Admission used the wrong provider guard.');
    if ($result['allowed']) { checkAdmission(in_array([(int) $operation->domain->server_id, false], $guardCalls, true), 'Allowed admission did not check write permission.'); }
    checkAdmission(array_diff($calls, $readCommands) === [], 'Admission attempted a registrar mutation.');
    if ($invariant) { checkAdmission($before === admissionDatabase(), 'Admission changed persistent native or extension state.'); }
    checkAdmission(is_bool($result['allowed']) && is_string($result['code']) && array_key_exists('quote', $result) && array_key_exists('command', $result), 'Admission result lacks its executor contract.');
    if (!$result['allowed']) { checkAdmission($result['command'] === null, 'Refused admission supplied a mutation command.'); }
    return [$result, $calls];
};

// Exact registrar expiry decides ordinary versus grace renewal on the expiry day.
$expiryDay = $make();
foreach (['2026-12-01 15:59:59' => 'Extend', '2026-12-01 16:00:00' => 'UpdateExpiredDomains', '2026-12-01 16:00:01' => 'UpdateExpiredDomains'] as $instant => $command) {
    Carbon\Carbon::setTestNow($instant);
    [$dayResult] = $read($expiryDay, ['expiry' => '2026-12-01T16:00:00Z']);
    checkAdmission($dayResult['allowed'] && $dayResult['command'] === $command, 'Expiry-day admission chose the wrong registrar command at '.$instant.': '.$dayResult['code']);
}
atAdmission('2026-11-10');

$paid = $make();
[$result, $calls] = $read($paid);
checkAdmission($result['allowed'] && $result['command'] === 'Extend' && $result['quote']['complete']
    && $result['quote']['amount'] === '10.00' && $result['provider_domain_id'] === '456', 'Full settled ordinary renewal failed fresh admission.');
checkAdmission($result['account_fingerprint'] === hash('sha256', 'test:offline-account'), 'Account fingerprint disagrees with observer namespace.');
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
checkAdmission(!$result['allowed'] && $result['code'] === 'grace_capability_unaccepted' && $calls === ['GetDomainInfo', 'GetExtendInfo', 'Check', 'GetExpiredDomains'], 'Default capability gate allowed unaccepted expired provider behavior.');
$configuration['grace_renewals_enabled'] = true;
$paid->domain->update(['renewal_policy' => ['enabled' => true, 'automatic_spend_paused' => true]]);
[$result] = $read($paid);
checkAdmission(!$result['allowed'] && $result['code'] === 'automatic_spend_paused', 'Cost incident pause allowed another grace admission.');
atAdmission('2026-11-10');
[$result] = $read($paid);
checkAdmission(!$result['allowed'] && $result['code'] === 'automatic_spend_paused',
    'Cost incident pause allowed ordinary paid pre-expiry renewal.');
$paid->domain->update(['renewal_policy' => ['enabled' => true]]);

$complimentary = $make('complimentary');
[$result] = $read($complimentary);
checkAdmission($result['allowed'] && $result['command'] === 'Extend' && $complimentary->invoice_id === null, 'Individually approved zero-retail complimentary pricing failed admission.');
foreach ([['price' => '12.01'], ['unknown_price' => true], ['currency' => 'garbage'], ['balance' => '9.99']] as $options) {
    [$result] = $read($complimentary, $options);
    checkAdmission(!$result['allowed'], 'Unfunded/unknown complimentary cost admitted.');
}
atAdmission('2026-12-09');
[$result] = $read($complimentary);
checkAdmission($result['allowed'] && $result['command'] === 'UpdateExpiredDomains', 'Approved complimentary grace renewal failed.');
$complimentary->domain->update(['price_mode' => 'standard']);
[$result, $calls] = $read($complimentary);
checkAdmission(!$result['allowed'] && $calls === [], 'Revoked complimentary pricing retained registrar spending authority.');

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
checkAdmission(!$result['allowed'] && $result['code'] === 'grace_original_price_exceeded', 'Grace admission used the wrong refusal for an untyped budget: '.$result['code']);
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

// ---------------------------------------------------------------- 0.4 multi-year renewals
// A paid N-year obligation: N x the yearly price, expected expiry base + N, policy term N; Enom's quote is per year and scales.
$makeYears = function (int $years, string $settlement = '') use (&$counter, $user, $server, $product, $plan, $billing): Operation {
    atAdmission('2026-11-10');
    $counter++;
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '15.00', 'quantity' => 1,
        'currency_code' => 'USD', 'status' => 'active', 'expires_at' => null]);
    $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id,
        'domain' => 'admission-'.$counter.'.com', 'sld' => 'admission-'.$counter, 'tld' => 'com', 'provider_domain_id' => '456', 'status' => 'active',
        'provider_expires_at' => '2026-12-01', 'billing_managed' => true, 'authority_generation' => 1, 'confirmed_revision' => 1, 'price_mode' => 'standard',
        'renewal_policy' => ['enabled' => true]]);
    $invoice = $billing->invoice($domain->id, $user, null, $years);
    ExtensionHelper::addPayment($invoice, 'Stripe', $settlement ?: number_format(15 * $years, 2, '.', ''), transactionId: 'admission-years-'.$counter);
    return Operation::where('invoice_id', $invoice->id)->firstOrFail();
};
$three = $makeYears(3);
checkAdmission((int) $three->term === 3 && $three->amount === '45.00' && $three->expected_expires_at->format('Y-m-d') === '2029-12-01'
    && $three->policy['term'] === 3 && $three->policy['retail_minor'] === 4500 && $three->state === 'queued', 'Paid three-year renewal obligation shape differs.');
[$result, $calls] = $read($three);
checkAdmission($result['allowed'] && $result['command'] === 'Extend' && $result['quote']['complete'] && $result['quote']['term'] === 3
    && $result['quote']['amount'] === '30.00' && $result['quote']['reason'] === 'ordinary_documented_price_per_year', 'Three-year renewal was not admitted at three times the per-year quote: '.json_encode($result['quote']));
// Enom's MaxExtension is a hard limit whatever the obligation says.
[$result] = $read($three, ['max' => '2']);
checkAdmission(!$result['allowed'] && $result['code'] === 'renewal_term_above_registry_limit', 'Term above MaxExtension was admitted: '.$result['code']);
[$result] = $read($three, ['max' => '3']);
checkAdmission($result['allowed'], 'Term equal to MaxExtension was refused.');
// The expected expiry must be base + term exactly.
$wrongExpiry = $makeYears(2);
$wrongExpiry->update(['expected_expires_at' => '2027-12-01']);
[$result, $calls] = $read($wrongExpiry);
checkAdmission(!$result['allowed'] && $result['code'] === 'expiry_changed' && $calls === [], 'Two-year renewal with a one-year expected expiry was admitted.');
// The policy term must match the operation term.
$policyTerm = $makeYears(2);
$policyTerm->update(['policy' => array_replace($policyTerm->policy, ['term' => 1])]);
[$result, $calls] = $read($policyTerm);
checkAdmission(!$result['allowed'] && $result['code'] === 'renewal_policy_binding_changed' && $calls === [], 'Policy term differing from the operation was admitted.');
// Grace renewals are one year at a time, even for an obligation issued before expiry.
$graceTwo = $makeYears(2);
atAdmission('2026-12-09');
[$result] = $read($graceTwo, ['expired_status' => 'Expired']);
checkAdmission(!$result['allowed'] && $result['code'] === 'grace_renewal_one_year_only', 'Multi-year grace renewal was admitted: '.$result['code']);
// The 10-year registry ceiling: from 2026-12-01 at 2026-11-10 at most nine years; none of the refusals writes.
atAdmission('2026-11-10');
$ceiling = $makeYears(1)->domain;
checkAdmission(DomainBilling::maxRenewalYears($ceiling->fresh()) === 9, 'Ten-year ceiling not derived from today: '.DomainBilling::maxRenewalYears($ceiling->fresh()));
$state = admissionDatabase();
foreach ([0, 10, 11] as $years) {
    try { $billing->invoice($ceiling->id, $user, null, $years); checkAdmission(false, $years.'-year renewal invoice accepted.'); }
    catch (RuntimeException $error) { checkAdmission(str_contains($error->getMessage(), '10-year'), 'Out-of-range term refusal differs: '.$error->getMessage()); }
}
checkAdmission($state === admissionDatabase(), 'Refused renewal terms wrote state.');
// An expired domain renews one year at a time.
$expiredDomain = $makeYears(1)->domain;
atAdmission('2026-12-09');
checkAdmission(DomainBilling::maxRenewalYears($expiredDomain->fresh()) === 1, 'Expired domain offered more than one renewal year.');
// Complimentary multi-year: staff only, and the scaled quote is compared with the cap.
atAdmission('2026-11-10');
$server->settings()->updateOrCreate(['key' => 'complimentary_cost_cap'], ['value' => '25.00']);
$freeService = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '0.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => 'active']);
$free = Domain::create(['service_id' => $freeService->id, 'user_id' => $user->id, 'server_id' => $server->id, 'domain' => 'admission-free.com', 'sld' => 'admission-free', 'tld' => 'com',
    'provider_domain_id' => '456', 'status' => 'active', 'provider_expires_at' => '2026-12-01', 'billing_managed' => true, 'authority_generation' => 1, 'confirmed_revision' => 1,
    'price_mode' => 'complimentary', 'renewal_policy' => ['enabled' => true]]);

$refused = false;
try { $billing->complimentary($free->id, null, 2); }
catch (RuntimeException) { $refused = true; }
checkAdmission($refused, 'The daily run queued a multi-year complimentary renewal.');
checkAdmission($free->operations()->doesntExist(), 'Refused complimentary term wrote an operation.');
$freeRenewal = $billing->complimentary($free->id, $staff, 3);
checkAdmission($freeRenewal->funding_basis === 'complimentary' && (int) $freeRenewal->term === 3 && $freeRenewal->expected_expires_at->format('Y-m-d') === '2029-12-01'
    && $freeRenewal->policy['term'] === 3 && $freeRenewal->policy['complimentary_cap_minor'] === 2500, 'Staff three-year complimentary renewal shape differs.');
[$result] = $read($freeRenewal, ['price' => '8.00']);
checkAdmission($result['allowed'] && $result['quote']['amount'] === '24.00', 'Three years under the complimentary cap were refused: '.$result['code']);
[$result] = $read($freeRenewal, ['price' => '8.95']);
checkAdmission(!$result['allowed'] && $result['code'] === 'complimentary_cost_above_limit' && $result['quote']['amount'] === '26.85', 'Scaled complimentary quote above the cap was admitted (a one-year comparison would pass).');

checkAdmission($nativeSchema == DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name"), 'Admission changed native schema.');
$successful = true;
echo json_encode(['checks' => $checks, 'status' => 'passed', 'database' => 'isolated-sqlite-memory', 'provider' => 'injected-read-only-evidence',
    'actual_provider_writes' => $actualProviderWrites, 'native_schema_unchanged' => true], JSON_PRETTY_PRINT).PHP_EOL;
