<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real stock Laravel/models, synthetic environment, in-memory SQLite, injected fake provider.
// Never load an installed app or any .env. Run: php tests/Enom/billing-integration.php
$repo = dirname(__DIR__, 2);
$successful = false;
register_shutdown_function(function () use (&$successful) { if (!$successful) { exit(1); } });
$release = paymenterTestApp();
if (!is_file($release.'/vendor/autoload.php') || is_file($release.'/.env')) { throw new RuntimeException('A pristine release with vendor and no environment file is required.'); }
$testRoot = $repo.'/.scratch/enom-billing-test-'.bin2hex(random_bytes(5));
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
Carbon\Carbon::setTestNow('2026-10-12 12:00:00');Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
$checks = 0;
function check(bool $condition, string $message): void { global $checks; if (!$condition) { throw new RuntimeException($message); } $checks++; }
function xml(string $command, string $body): string { return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>'; }
$writes = [];
$providerCalls = 0;
$providerExpiry = '2027-10-12';
$unknownPurchase = false;
$privacyExists = false;
$privacyExpiry = '2027-10-12';
$privacyRenewAdvances = false;
$srvRecord = null;
$checkOverrides = [];
$dnssecShape = 'flat';
$observedDnssec = json_decode(file_get_contents(__DIR__.'/billing-dnssec-single-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$fake = function (string $command, array $params) use (&$writes, &$providerCalls, &$providerExpiry, &$unknownPurchase, &$privacyExists, &$srvRecord, &$privacyExpiry, &$privacyRenewAdvances, &$checkOverrides, &$dnssecShape, $observedDnssec): string {
    $providerCalls++;
    if ($command === 'Check') {
        $check = $checkOverrides[$params['SLD'] ?? ''] ?? ['available' => true, 'transferable' => true];
        return xml($command, '<Domains><Domain><Name>'.($params['SLD'] ?? 'demo').'.com</Name><RRPCode>'.($check['available'] ? '210' : '211').'</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Properties><Transferable>'.($check['transferable'] ? 'true' : 'false').'</Transferable></Properties></Domain></Domains>');
    }
    if ($command === 'GetDomainExp') { return xml($command, '<ExpirationDate>'.$providerExpiry.'</ExpirationDate>'); }
    if ($command === 'GetExtendInfo') { return xml($command, '<Expiration>'.$providerExpiry.'</Expiration><MaxExtension>9</MaxExtension>'); }
    if ($command === 'Purchase' || $command === 'Extend') {
        $writes[] = $command;
        if ($unknownPurchase) { return '<invalid'; }
        if ($command === 'Extend') { $providerExpiry = (new DateTimeImmutable($providerExpiry))->modify('+1 year')->format('Y-m-d'); }
        return xml($command, '<RRPCode>200</RRPCode><OrderID>12345</OrderID><Extension>Successful</Extension><RegistryExpDate>'.$providerExpiry.'</RegistryExpDate>');
    }
    if ($command === 'GetWPPSInfo') { return xml($command, '<GetWPPSInfo><WPPSAllowed>True</WPPSAllowed><WPPSExists>'.($privacyExists ? 'True' : 'False').'</WPPSExists><WPPSEnabled>False</WPPSEnabled><WPPSExpDate>'.$privacyExpiry.'</WPPSExpDate></GetWPPSInfo>'); }
    if ($command === 'PurchaseServices') { $writes[] = $command; $privacyExists = true; return xml($command, '<RRPCode>200</RRPCode><OrderID>54321</OrderID>'); }
    if ($command === 'RenewServices') { $writes[] = $command; if ($privacyRenewAdvances) { $privacyExpiry = Carbon\CarbonImmutable::parse($privacyExpiry)->addYearNoOverflow()->format('Y-m-d'); } return xml($command, '<RRPCode>200</RRPCode><OrderID>54322</OrderID>'); } // Deliberately unchanged expiry.
    if ($command === 'GetDomainSRVHosts') {
        return xml($command, $srvRecord ? '<srv-records><srv><HostName>_sip</HostName><Protocol>_TCP</Protocol><Priority>0</Priority><Weight>0</Weight><Port>443</Port><Address>target.example.com.</Address><HostID>123</HostID></srv></srv-records>' : '<srv-records/>');
    }
    if ($command === 'SetDomainSRVHosts') { $srvRecord = true; $writes[] = $command; return xml($command, '<RRPCode>200</RRPCode>'); }
    if ($command === 'GetDnsSec') {
        if ($dnssecShape === 'empty') { return xml($command, '<Success>True</Success><DnsSecDataCount>0</DnsSecDataCount><DnsSecData><Result><ResponseCode>200</ResponseCode></Result></DnsSecData>'); }
        if ($dnssecShape === 'unverified_nonempty') { return xml($command, '<Success>True</Success><DnsSecDataCount>1</DnsSecDataCount><DnsSecData><Unknown><Algorithm>13</Algorithm></Unknown><Result><ResponseCode>200</ResponseCode></Result></DnsSecData>'); }
        if (in_array($dnssecShape, ['observed_nonempty', 'wrong_count'], true)) {
            $ds = $observedDnssec['dnssecdata']['keydata'];
            return xml($command, '<Success>'.$observedDnssec['success'].'</Success><DnsSecDataCount>'.($dnssecShape === 'wrong_count' ? '2' : $observedDnssec['dnssecdatacount']).'</DnsSecDataCount><DnsSecData><KeyData><Algorithm>'.$ds['algorithm'].'</Algorithm><Digest>'.$ds['digest'].'</Digest><DigestType>'.$ds['digesttype'].'</DigestType><KeyTag>'.$ds['keytag'].'</KeyTag></KeyData><Result><ResponseCode>'.$observedDnssec['dnssecdata']['result']['responsecode'].'</ResponseCode></Result></DnsSecData>');
        }
        return xml($command, '<Algorithm>13</Algorithm><Digest>'.str_repeat('A', 64).'</Digest><DigestType>2</DigestType><KeyTag>123</KeyTag>');
    }
    if ($command === 'TP_CreateOrder') { $writes[] = $command; return xml($command, '<TransferOrderID>555</TransferOrderID>'); }
    if ($command === 'DeleteDnsSec') { $writes[] = $command; return xml($command, '<ResponseCode>100000</ResponseCode>'); } // Provider still returns the tuple after ack.
    return xml($command, '<RRPCode>200</RRPCode>');
};
$client = new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('synthetic', 'synthetic', $fake);
$operations = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(new Paymenter\Extensions\Servers\Enom\Api\EnomApi($client), fn () => ['uid' => 'synthetic', 'token' => 'synthetic']);
$app->instance(Paymenter\Extensions\Servers\Enom\Services\DomainOperations::class, $operations);
$orders = new Paymenter\Extensions\Servers\Enom\Services\DomainOrders($operations);
$user = App\Models\User::create(['email' => 'demo@example.invalid', 'password' => bcrypt('synthetic')]);
$other = App\Models\User::create(['email' => 'other@example.invalid', 'password' => bcrypt('synthetic')]);
App\Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$']);
$server = App\Models\Server::create(['name' => 'Synthetic Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$server->settings()->create(['key' => 'lead_days', 'value' => 30, 'type' => 'integer']);
$server->settings()->create(['key' => 'operations_enabled', 'value' => true, 'type' => 'boolean']);
$category = App\Models\Category::create(['name' => 'Synthetic domains', 'slug' => 'synthetic-domains']);
$product = App\Models\Product::create(['name' => 'Synthetic .com', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
foreach (['enom_tld' => 'com', 'enom_register_price' => '15.00', 'enom_transfer_price' => '15.00', 'enom_privacy_price' => '5.00'] as $key => $value) { $product->settings()->create(['key' => $key, 'value' => $value]); }
$plan = $product->plans()->create(['name' => 'One year', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$plan->prices()->create(['currency_code' => 'USD', 'price' => 15, 'setup_fee' => 0]);
// The upstream direct checkout path must be blocked without a core/template edit.
$checkout = new App\Livewire\Products\Checkout;
$checkout->category = $category; $product->slug = 'synthetic-com'; $product->save();
$checkout->mount('synthetic-com');
check(!empty($checkout->getCheckoutConfig()), 'Direct native checkout auto-added the hidden registrar product.');
try { $checkout->validate(); check(false, 'Native checkout accepted a domain without an intent.'); } catch (Illuminate\Validation\ValidationException) { check(true, 'Direct checkout validation refused.'); }
$nativeCart = App\Models\Cart::create(['user_id' => $user->id, 'currency_code' => 'USD']);
try { $nativeCart->items()->create(['product_id' => $product->id, 'plan_id' => $plan->id, 'quantity' => 1, 'config_options' => [], 'checkout_config' => []]); check(false, 'Native cart stored an Enom order without intent.'); }
catch (App\Exceptions\DisplayException) { check(true, 'Native cart refused registrar product.'); }
$contacts = ['registrant' => ['first_name' => 'Demo', 'last_name' => 'Owner', 'organization_name' => 'Demo', 'address1' => '123 Example Road', 'city' => 'Example', 'postal_code' => '12345', 'country' => 'US', 'email_address' => 'demo@example.invalid', 'phone' => '+1.5551234567']];
$quote = $orders->quote($user, 'billing-demo.com');
$invoice = $orders->place($user, $quote['intent_id'], $contacts);
$domain = Paymenter\Extensions\Servers\Enom\Models\Domain::firstOrFail();
$operation = Paymenter\Extensions\Servers\Enom\Models\Operation::firstOrFail();
check($operation->state === 'awaiting_payment', 'Initial operation must be bound before payment.');
check($orders->place($user, $quote['intent_id'], $contacts)->id === $invoice->id, 'Intent replay created another invoice.');
check(count($writes) === 0, 'Ordering executed a provider write.');
check($operations->acceptPaidInvoice($invoice) === [], 'Unpaid invoice queued registrar action.');
check(!str_contains(Illuminate\Support\Facades\DB::table('enom_operations')->value('payload'), 'Example Road'), 'Operation contacts were stored plaintext.');
try { $operations->ownedDomain($other, $domain->id); check(false, 'Cross-owner access allowed.'); } catch (Illuminate\Database\Eloquent\ModelNotFoundException) { check(true, 'Owner guard passed.'); }
// Native successful credit transaction invokes native paid processing and the extension event.
App\Models\Credit::create(['user_id' => $user->id, 'currency_code' => 'USD', 'amount' => 30]);
Illuminate\Support\Facades\DB::transaction(function () use ($user, $invoice) {
    $credit = $user->credits()->where('currency_code', 'USD')->lockForUpdate()->firstOrFail();
    $credit->amount -= 15; $credit->save();
    App\Helpers\ExtensionHelper::addPayment($invoice->id, null, amount: 15, isCreditTransaction: true);
});
check($invoice->fresh()->status === 'paid', 'Native credit path did not mark invoice paid.');
check($operations->funded($operation->fresh()), 'Native credit funding was rejected.');
check($operation->fresh()->state === 'queued', 'Paid native event did not enqueue operation.');
$completed = $operations->execute($operation->id);
check($completed->state === 'succeeded', 'Registration did not confirm provider expiry: '.$completed->safe_code);
check($domain->fresh()->provider_expires_at->format('Y-m-d') === '2027-10-12', 'Registrar expiry missing.');
check($domain->fresh()->service->expires_at->format('Y-m-d') === '2027-09-12', 'Native billing lead alignment failed.');
$operations->execute($operation->id); $operations->acceptPaidInvoice($invoice->fresh());
check(count($writes) === 1, 'Duplicate paid event executed another registration.');
check($completed->payload === null, 'Terminal registration retained contact/EPP payload.');
$renewal = $orders->renew($user, $domain->id, str_repeat('a', 64));
check($orders->renew($user, $domain->id, str_repeat('a', 64))->id === $renewal->id, 'Renew nonce replay duplicated invoice.');
Illuminate\Support\Facades\DB::transaction(function () use ($user, $renewal) {
    $credit = $user->credits()->where('currency_code', 'USD')->lockForUpdate()->firstOrFail();
    $credit->amount -= 15; $credit->save();
    App\Helpers\ExtensionHelper::addPayment($renewal->id, null, amount: 15, isCreditTransaction: true);
});
$renewOperation = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $renewal->id)->firstOrFail();
check($operations->funded($renewOperation), 'Credit-paid renewal not accepted.');
check($operations->execute($renewOperation->id)->state === 'succeeded', 'Credit-paid registrar renewal failed.');
check($domain->fresh()->provider_expires_at->format('Y-m-d') === '2028-10-12', 'Renewal expiry not advanced.');
check(count($writes) === 2, 'Renewal transmitted wrong number of purchases.');
$operations->toggleRenewalPreference($user, $domain->id, false);
check($domain->fresh()->service->cancellation?->type === 'end_of_period', 'Do-not-renew did not create native cancellation.');
try { $orders->renew($user, $domain->id, str_repeat('b', 64)); check(false, 'Do-not-renew billed another invoice.'); } catch (RuntimeException) { check(true, 'Do-not-renew refused.'); }
$operations->toggleRenewalPreference($user, $domain->id, true);
$service = $domain->fresh()->service;
$service->price = 0; $service->expires_at = '2030-01-01'; $service->save();
$summary = $operations->sweep();
check($domain->fresh()->drift_detected_at !== null && $summary['drift'] > 0, 'Free native renewal drift was invisible.');
$service->price = 15; $service->expires_at = '2028-09-12'; $service->save();
// A running stale claim may never be retransmitted.
$held = Paymenter\Extensions\Servers\Enom\Models\Operation::create(['domain_id' => $domain->id, 'service_id' => $service->id,
    'action' => 'renew', 'operation_key' => 'stale-demo', 'funding_basis' => 'paid_invoice', 'state' => 'running',
    'currency' => 'USD', 'amount' => 15, 'started_at' => now()->subMinutes(10), 'lease_expires_at' => now()->subMinutes(5)]);
check($operations->execute($held->id)->state === 'uncertain', 'Expired claim became replayable.');
check(count($writes) === 2, 'Expired claim executed provider write.');
$held->update(['state' => 'rejected']); // Explicit fixture disposition, never a real uncertain-operation retry.
// Paid privacy is bound to an unreferenced item and leaves native service due unchanged.
$domain = $domain->fresh();
$domain->update(['capabilities' => ['renew' => true, 'privacy_purchase' => true]]);
$privacyInvoice = $orders->privacy($user, $domain->id, 'purchase', str_repeat('c', 64));
check($privacyInvoice->items->first()->reference_type === null && $privacyInvoice->items->first()->reference_id === null, 'Privacy invoice referenced a native Service.');
$gateway = App\Models\Gateway::create(['name' => 'Synthetic test gateway', 'type' => 'gateway', 'extension' => 'Stripe', 'enabled' => true]);
$beforeDue = $domain->service->expires_at->format('Y-m-d');
App\Helpers\ExtensionHelper::addPayment($privacyInvoice->id, 'Stripe', amount: 5, transactionId: 'synthetic-privacy');
$privacyOperation = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $privacyInvoice->id)->firstOrFail();
check($operations->funded($privacyOperation), 'Gateway-paid privacy funding refused.');
check($operations->execute($privacyOperation->id)->state === 'succeeded', 'Privacy purchase did not confirm provider subscription.');
check($domain->fresh()->service->expires_at->format('Y-m-d') === $beforeDue, 'Privacy payment advanced native due date.');
$domain = $domain->fresh(); $domain->update(['capabilities' => ['privacy_renew' => true]]);
$privacyRenewal = $orders->privacy($user, $domain->id, 'renew', str_repeat('f', 64));
App\Helpers\ExtensionHelper::addPayment($privacyRenewal->id, 'Stripe', amount: 5, transactionId: 'synthetic-privacy-renew');
$privacyRenewOp = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $privacyRenewal->id)->firstOrFail();
check($operations->execute($privacyRenewOp->id)->state === 'uncertain', 'Unchanged privacy expiry falsely confirmed a paid renewal.');
$privacyRenewOp->update(['state' => 'rejected']); // Isolated fake fixture knows no expiry change; no real recovery command.

// Feb29 privacy renewal binds and confirms Feb28, without advancing native billing.
Carbon\Carbon::setTestNow('2028-02-28 12:00:00');Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
$privacyExpiry='2028-02-29';$privacyRenewAdvances=true;
$domain=$domain->fresh();$privacySnapshot=$domain->snapshot??[];$privacySnapshot['privacy']['expires_at']=$privacyExpiry;
$domain->update(['snapshot'=>$privacySnapshot,'capabilities'=>['privacy_renew'=>true]]);
$privacyLeap=$orders->privacy($user,$domain->id,'renew',str_repeat('d',64));
$privacyLeapOperation=Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id',$privacyLeap->id)->firstOrFail();
check($privacyLeapOperation->base_expires_at->format('Y-m-d')==='2028-02-29'
    &&$privacyLeapOperation->expected_expires_at->format('Y-m-d')==='2029-02-28','Privacy annual term overflowed Feb29.');
$privacyNativeDue=$domain->service->expires_at->format('Y-m-d');
App\Helpers\ExtensionHelper::addPayment($privacyLeap,'Stripe',amount:5,transactionId:'synthetic-privacy-leap');
check($operations->execute($privacyLeapOperation->id)->state==='succeeded'
    &&$privacyLeapOperation->fresh()->confirmed_expires_at->format('Y-m-d')==='2029-02-28','Feb29 privacy renewal did not confirm exact Feb28 readback.');
check($domain->fresh()->service->expires_at->format('Y-m-d')===$privacyNativeDue,'Privacy leap renewal changed native domain billing.');
$privacyRenewAdvances=false;Carbon\Carbon::setTestNow('2026-10-12 12:00:00');Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());

// A second fresh registration timeout is durable and blocks a later purchase for that domain.
$providerExpiry = '2027-10-12';
$quote2 = $orders->quote($user, 'unknown-billing-demo.com');
$invoice2 = $orders->place($user, $quote2['intent_id'], $contacts);
App\Helpers\ExtensionHelper::addPayment($invoice2->id, 'Stripe', amount: 15, transactionId: 'synthetic-unknown');
$unknown = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $invoice2->id)->firstOrFail();
$unknownPurchase = true;
check($operations->execute($unknown->id)->state === 'uncertain', 'Malformed purchase reply was not uncertain.');
$writesBefore = count($writes);
$operations->execute($unknown->id); $operations->reconcile($unknown->id);
check(count($writes) === $writesBefore, 'Unknown registration was retried.');
check($unknown->fresh()->payload !== null, 'Pending reconciliation lost encrypted request evidence.');
// Native manually-paid status without any successful money transaction never authorizes a purchase.
$quote3 = $orders->quote($user, 'unfunded-billing-demo.com');
$invoice3 = $orders->place($user, $quote3['intent_id'], $contacts);
$invoice3->status = 'paid'; $invoice3->save();
$unfunded = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $invoice3->id)->firstOrFail();
check($unfunded->state === 'awaiting_payment' && !$operations->funded($unfunded), 'Manually-paid status without money authorized registrar action.');
check($operations->acceptPaidInvoice($invoice3) === [], 'Manually-paid invoice queued a purchase.');
// A historical unbound service invoice is not reconstructed by the sweep.
$historical = new App\Models\Invoice(['user_id' => $user->id, 'currency_code' => 'USD', 'due_at' => now(), 'status' => 'paid']);
$historical->send_create_email = false; $historical->save();
$historical->items()->create(['reference_type' => App\Models\Service::class, 'reference_id' => $domain->service_id, 'quantity' => 1, 'price' => 15, 'description' => 'Synthetic historical invoice']);
check(!Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $historical->id)->exists(), 'Historical paid item created an operation.');
$operations->sweep();
check(count($writes) === $writesBefore, 'Sweep replayed historical or uncertain purchase.');
// Actual XML scalar types and key order must not create false SRV uncertainty or false DS deletion.
$domain = $domain->fresh();
$domain->update(['capabilities' => ['srv' => true, 'dnssec_delete' => true]]);
$srvPayload = ['records' => [['service' => 'sip', 'protocol' => 'tcp', 'target' => 'target.example.com.', 'priority' => 0, 'weight' => 0, 'port' => 443]],
    'preimage_digest' => Paymenter\Extensions\Servers\Enom\Services\DomainManager::digest([])];
$srvOperation = $operations->enqueueManagement($user, $domain->id, 'srv', $srvPayload, str_repeat('d', 64));
check($operations->execute($srvOperation->id)->state === 'succeeded', 'Equivalent XML SRV readback was not confirmed.');
$dsPayload = ['ds' => ['algorithm' => 13, 'digest_type' => 2, 'key_tag' => 123, 'digest' => str_repeat('A', 64)]];
$dsOperation = $operations->enqueueManagement($user, $domain->id, 'dnssec_delete', $dsPayload, str_repeat('e', 64));
check($operations->execute($dsOperation->id)->state === 'uncertain', 'DS deletion falsely succeeded while tuple remained present.');
$typedDs = Paymenter\Extensions\Servers\Enom\Services\DomainOperations::canonicalRecords('dnssec', [$dsPayload['ds']]);
$xmlDs = Paymenter\Extensions\Servers\Enom\Services\DomainOperations::canonicalRecords('dnssec', [['key_tag' => '123', 'digest' => str_repeat('a', 64), 'digest_type' => '2', 'algorithm' => '13']]);
check($typedDs === $xmlDs, 'XML string and form numeric DS tuples differ.');
// Rejected expiry reply containing a plausible date cannot confirm an uncertain registration.
$unknown->update(['provider_order_id' => '12345']);
$reviewApi = new Paymenter\Extensions\Servers\Enom\Api\EnomApi(new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('synthetic', 'synthetic', function ($command, $params) {
    if ($command === 'GetOrderDetail') { return xml($command, '<Order><Result>true</Result><OrderID>12345</OrderID><OrderDetail><Description>unknown-billing-demo.com</Description><Status>Successful</Status><OrderStatus>Closed</OrderStatus><Quantity>1</Quantity></OrderDetail></Order>'); }
    return '<interface-response><Command>GetDomainExp</Command><ErrCount>1</ErrCount><Done>true</Done><ExpirationDate>2027-10-12</ExpirationDate></interface-response>';
}));
$reviewOperations = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations($reviewApi, fn () => ['uid' => 'synthetic', 'token' => 'synthetic']);
check($reviewOperations->reconcile($unknown->id)->state === 'uncertain', 'Rejected expiry reply confirmed registration.');
// Generic staff funding cannot override an existing customer invoice.
$role = App\Models\Role::create(['name' => 'Synthetic administrator', 'permissions' => ['*']]);
$staff = App\Models\User::create(['email' => 'staff@example.invalid', 'password' => bcrypt('synthetic'), 'role_id' => $role->id]);
$unknownPurchase = false;
$fundQuote = $orders->quote($user, 'funded-billing-demo.com');
$fundInvoice = $orders->place($user, $fundQuote['intent_id'], $contacts);
$fundOperation = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $fundInvoice->id)->firstOrFail();
$beforeFund = count($writes);
$beforeFundingRows = [
    $fundInvoice->fresh()->getRawOriginal(), $fundOperation->fresh()->getRawOriginal(),
    $fundOperation->domain->service->getRawOriginal(),
];
try {
    $operations->approveFunding($fundOperation->id, $staff, 'Synthetic invoice override refusal.');
    check(false, 'Generic funding overrode an unpaid customer invoice.');
} catch (Paymenter\Extensions\Servers\Enom\Services\InputValidationException) {
    check(true, 'Unpaid customer obligation required separate financial disposition.');
}
check($beforeFundingRows === [
    $fundInvoice->fresh()->getRawOriginal(), $fundOperation->fresh()->getRawOriginal(),
    $fundOperation->domain->fresh()->service->getRawOriginal(),
], 'Refused funding changed the customer invoice, operation or native lifecycle.');
$operations->execute($fundOperation->id);
check(count($writes) === $beforeFund && $fundInvoice->fresh()->status === 'pending', 'Unpaid funding refusal sent a registrar write or fabricated payment.');
App\Helpers\ExtensionHelper::addPayment($fundInvoice->id, 'Stripe', amount: 15, transactionId: 'synthetic-funded-payment');
check($operations->execute($fundOperation->id)->state === 'succeeded', 'Genuinely paid registration failed.');
$fundDomain = $fundOperation->domain->fresh();
check($fundDomain->service->status === 'active', 'Paid registrar success left native service pending.');
check($fundInvoice->fresh()->status === 'paid', 'Real payment did not settle the original customer invoice.');
$operations->acceptPaidInvoice($fundInvoice->fresh());
check($fundDomain->fresh()->service->expires_at->format('Y-m-d') === '2027-09-12', 'Paid-event replay advanced due beyond provider lead.');
check(count($writes) === $beforeFund + 1, 'Paid-event replay bought the domain again.');
// Delayed privacy payment derives expected purchase term when sending, not at invoice creation.
$fundDomain->update(['capabilities' => ['privacy_purchase' => true]]);
$privacyExists = false;
$delayed = $orders->privacy($user, $fundDomain->id, 'purchase', str_repeat('9', 64));
Carbon\Carbon::setTestNow(now()->addDays(3));
Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
$privacyExpiry = now()->addYear()->format('Y-m-d');
App\Helpers\ExtensionHelper::addPayment($delayed->id, 'Stripe', amount: 5, transactionId: 'synthetic-delayed-privacy');
$delayedOperation = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $delayed->id)->firstOrFail();
check($operations->execute($delayedOperation->id)->state === 'succeeded', 'Delayed privacy purchase used invoice-date expiry.');
check($delayedOperation->fresh()->expected_expires_at->format('Y-m-d') === $privacyExpiry, 'Privacy claim did not persist current expected expiry.');
Carbon\Carbon::setTestNow('2026-10-12 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
// Recovery rotates beyond fifty old unpaid rows and performs no provider requests in the sweep.
for ($i = 0; $i < 55; $i++) {
    Paymenter\Extensions\Servers\Enom\Models\Operation::create(['domain_id' => $domain->id, 'service_id' => $domain->service_id,
        'invoice_id' => $invoice3->id, 'operation_key' => 'unpaid-rotation-'.$i, 'action' => 'renew', 'term' => 1,
        'funding_basis' => 'paid_invoice', 'state' => 'awaiting_payment', 'currency' => 'USD', 'amount' => 15]);
}
$lateInvoice = $orders->renew($user, $fundDomain->id, str_repeat('8', 64));
App\Helpers\ExtensionHelper::addPayment($lateInvoice->id, 'Stripe', amount: 15, transactionId: 'synthetic-late-recovery');
$lateOperation = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $lateInvoice->id)->firstOrFail();
$lateOperation->update(['state' => 'awaiting_payment']); // Simulate missed delivery with trustworthy pre-payment binding.
Illuminate\Support\Facades\Cache::forget('enom:sweep:operations');
$sweepWrites = count($writes);
$operations->sweep();
check($lateOperation->fresh()->state === 'awaiting_payment', 'Fairness fixture did not place paid row after the first batch.');
$operations->sweep();
check($lateOperation->fresh()->state === 'queued', 'Old unpaid rows starved a later paid operation.');
check(count($writes) === $sweepWrites, 'Scheduled recovery executed provider HTTP synchronously.');
// Disable while a preflight read is in flight: final dispatch and subsequent reads fail closed.
$guardQuote = $orders->quote($user, 'guard-billing-demo.com');
$guardInvoice = $orders->place($user, $guardQuote['intent_id'], $contacts);
App\Helpers\ExtensionHelper::addPayment($guardInvoice->id, 'Stripe', amount: 15, transactionId: 'synthetic-guard');
$guardOperation = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $guardInvoice->id)->firstOrFail();
$gate = true; $guardWrites = 0; $guardReads = 0;
$guardCheck = function () use (&$gate) { if (!$gate) { throw new RuntimeException('Synthetic gate disabled.'); } return ['uid' => 'synthetic', 'token' => 'synthetic']; };
$guardClient = new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('synthetic', 'synthetic', function ($command, $params) use (&$gate, &$guardWrites, &$guardReads) {
    if ($command === 'Check') { $guardReads++; $gate = false; return xml($command, '<Domains><Domain><RRPCode>210</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP></Domain></Domains>'); }
    $guardWrites++; return xml($command, '<RRPCode>200</RRPCode>');
}, $guardCheck);
$guardOperations = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(new Paymenter\Extensions\Servers\Enom\Api\EnomApi($guardClient), $guardCheck);
check($guardOperations->execute($guardOperation->id)->state !== 'succeeded' && $guardWrites === 0, 'Gate disabled during preflight still allowed registrar mutation.');
check($guardClient->execute('GetDomainInfo')->status === 'rejected' && $guardReads === 1 && $guardWrites === 0, 'Every-request gate did not block subsequent read.');
// A TLD transfer flag cannot make an unregistered name a paid transfer candidate.
$beforeTransferInvoices = App\Models\Invoice::count();
$beforeTransferIntents = Paymenter\Extensions\Servers\Enom\Models\OrderIntent::count();
$unregisteredTransferRejected = false;
try { $orders->quote($user, 'unregistered-transfer-demo.com', 'transfer'); }
catch (RuntimeException) { $unregisteredTransferRejected = true; }
check($unregisteredTransferRejected, 'Unregistered transferable name was quoted.');
$checkOverrides['ineligible-transfer-demo'] = ['available' => false, 'transferable' => false];
$ineligibleTransferRejected = false;
try { $orders->quote($user, 'ineligible-transfer-demo.com', 'transfer'); }
catch (RuntimeException) { $ineligibleTransferRejected = true; }
check($ineligibleTransferRejected, 'Registered but ineligible transfer name was quoted.');
check(App\Models\Invoice::count() === $beforeTransferInvoices && Paymenter\Extensions\Servers\Enom\Models\OrderIntent::count() === $beforeTransferIntents, 'Rejected transfer quote created billing records.');
// Eligibility may disappear after a valid quote and payment. Never send TP_CreateOrder then.
$checkOverrides['race-transfer-demo'] = ['available' => false, 'transferable' => true];
$transferQuote = $orders->quote($user, 'race-transfer-demo.com', 'transfer');
$transferInvoice = $orders->place($user, $transferQuote['intent_id'], $contacts, 'synthetic-epp');
App\Helpers\ExtensionHelper::addPayment($transferInvoice->id, 'Stripe', amount: 15, transactionId: 'synthetic-transfer-race');
$transferOperation = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $transferInvoice->id)->firstOrFail();
check($transferOperation->state === 'queued' && $operations->funded($transferOperation), 'Transfer race fixture never reached authorized execution.');
$transferWrites = count($writes);
$checkOverrides['race-transfer-demo'] = ['available' => true, 'transferable' => true];
check($operations->execute($transferOperation->id)->state === 'rejected' && count($writes) === $transferWrites, 'Became-unregistered transfer still reached provider submission.');
// A verified empty getter permits only the controlled first add. Other and unknown shapes stay unavailable.
$dnssecShape = 'empty';
$domain = $domain->fresh();
$originalDomainName = $domain->domain;
$originalEmail = $user->email;
$originalOrderId = $domain->provider_order_id;
$ordinaryEmpty = $operations->refreshSnapshot($user, $domain->id);
check(!$ordinaryEmpty->capabilities['dnssec_add'] && !$ordinaryEmpty->capabilities['dnssec_delete'], 'Empty DNSSEC enabled an uncontrolled first write.');
$user->update(['email' => 'demo@example.com']);
check(!$operations->refreshSnapshot($user, $domain->id)->capabilities['dnssec_add'], 'First DNSSEC add allowed a non-demo domain.');
$domain->update(['domain' => 'demo-domain-0123456789abcdef.com']);
$controlledEmpty = $operations->refreshSnapshot($user, $domain->id);
check($controlledEmpty->capabilities['dnssec_add'] && !$controlledEmpty->capabilities['dnssec_delete'], 'Controlled empty DNSSEC did not split add and delete permissions.');
$domain->update(['provider_order_id' => null]);
check(!$operations->refreshSnapshot($user, $domain->id)->capabilities['dnssec_add'], 'First DNSSEC add allowed an unbound demo domain.');
$domain->update(['provider_order_id' => $originalOrderId]);
$dnssecShape = 'unverified_nonempty';
$unknownDs = $operations->refreshSnapshot($user, $domain->id);
check(!$unknownDs->capabilities['dnssec_add'] && !$unknownDs->capabilities['dnssec_delete'], 'Unverified nonempty DNSSEC enabled mutations.');
$dnssecShape = 'flat';
$verifiedDs = $operations->refreshSnapshot($user, $domain->id);
check($verifiedDs->capabilities['dnssec_add'] && $verifiedDs->capabilities['dnssec_delete'], 'Previously verified nonempty DNSSEC lost mutation permissions.');
$dnssecShape = 'observed_nonempty';
$observedDs = $operations->refreshSnapshot($user, $domain->id);
$ds = $observedDnssec['dnssecdata']['keydata'];
$observedTuple = ['algorithm' => (int) $ds['algorithm'], 'digest_type' => (int) $ds['digesttype'], 'key_tag' => (int) $ds['keytag'], 'digest' => $ds['digest']];
check($observedDs->capabilities['dnssec_add'] && $observedDs->capabilities['dnssec_delete'], 'Actual single KeyData getter did not enable owned demo add/delete.');
check($operations::canonicalRecords('dnssec', $observedDs->snapshot['dnssec']) === $operations::canonicalRecords('dnssec', [$observedTuple]), 'Actual DNSSEC getter did not preserve the submitted public tuple.');
$user->update(['email' => $originalEmail]);
$wrongDemoOwner = $operations->refreshSnapshot($user, $domain->id);
check(!$wrongDemoOwner->capabilities['dnssec_add'] && !$wrongDemoOwner->capabilities['dnssec_delete'], 'Verified nonempty DNSSEC escaped the DNSSEC validation rule.');
$user->update(['email' => 'demo@example.com']);
$domain->update(['provider_order_id' => null]);
$unboundDs = $operations->refreshSnapshot($user, $domain->id);
check(!$unboundDs->capabilities['dnssec_add'] && !$unboundDs->capabilities['dnssec_delete'], 'Verified nonempty DNSSEC allowed an unbound domain.');
$domain->update(['provider_order_id' => $originalOrderId]);
$domain->update(['status' => 'registration_pending']);
$inactiveDs = $operations->refreshSnapshot($user, $domain->id);
check(!$inactiveDs->capabilities['dnssec_add'] && !$inactiveDs->capabilities['dnssec_delete'], 'Verified nonempty DNSSEC allowed an inactive domain.');
$domain->update(['status' => 'active']);
$dnssecShape = 'wrong_count';
$badDsCount = $operations->refreshSnapshot($user, $domain->id);
check(!$badDsCount->capabilities['dnssec_add'] && !$badDsCount->capabilities['dnssec_delete'], 'Single DS tuple with a conflicting count enabled writes.');
$dnssecShape = 'observed_nonempty';
// Read-only reconciliation confirms the existing first add. Wrong authorization stays uncertain.
$dsReconcile = Paymenter\Extensions\Servers\Enom\Models\Operation::create(['domain_id' => $domain->id, 'service_id' => $domain->service_id,
    'operation_key' => 'observed-ds-reconcile', 'action' => 'dnssec_add', 'term' => 1, 'funding_basis' => 'non_billable_management',
    'state' => 'uncertain', 'currency' => 'USD', 'amount' => 0, 'authorized_by' => $other->id, 'payload' => ['ds' => $observedTuple]]);
$dsWritesBefore = count($writes);
$dsInvoicesBefore = App\Models\Invoice::count();
$dsIntentsBefore = Paymenter\Extensions\Servers\Enom\Models\OrderIntent::count();
check($operations->reconcile($dsReconcile->id)->state === 'uncertain', 'Wrong-owner authorization confirmed DNSSEC add.');
$dsReconcile->update(['authorized_by' => $user->id]);
check($operations->reconcile($dsReconcile->id)->state === 'succeeded' && count($writes) === $dsWritesBefore, 'DNSSEC reconciliation resent the first add or failed to confirm the actual tuple.');
check(App\Models\Invoice::count() === $dsInvoicesBefore && Paymenter\Extensions\Servers\Enom\Models\OrderIntent::count() === $dsIntentsBefore, 'DNSSEC readback altered billing/order records.');
$dnssecShape = 'empty';
$afterEmptyDs = $operations->refreshSnapshot($user, $domain->id);
check($afterEmptyDs->capabilities['dnssec_add'] && !$afterEmptyDs->capabilities['dnssec_delete'], 'Verified empty DNSSEC still offered deletion.');
$dsDeleteReconcile = Paymenter\Extensions\Servers\Enom\Models\Operation::create(['domain_id' => $domain->id, 'service_id' => $domain->service_id,
    'operation_key' => 'observed-ds-delete-reconcile', 'action' => 'dnssec_delete', 'term' => 1, 'funding_basis' => 'non_billable_management',
    'state' => 'uncertain', 'currency' => 'USD', 'amount' => 0, 'authorized_by' => $user->id, 'payload' => ['ds' => $observedTuple]]);
check($operations->reconcile($dsDeleteReconcile->id)->state === 'succeeded' && count($writes) === $dsWritesBefore, 'Verified empty readback failed to reconcile deletion without resending.');
$dnssecShape = 'flat';
$domain->update(['domain' => $originalDomainName]);
$user->update(['email' => $originalEmail]);
// Nameserver RRsets are unordered, case-insensitive complete sets, not raw arrays.
$nsPair = ['ns1.example.com', 'ns2.example.com'];
$nsCases = [
    'equivalent' => ['NS2.Example.COM.', 'NS1.EXAMPLE.COM.'],
    'missing' => ['ns1.example.com'],
    'extra' => [...$nsPair, 'ns3.example.com'],
    'duplicate' => ['ns1.example.com', 'NS1.Example.COM.'],
    'malformed' => ['ns1.example.com', 'bad_.example.com'],
    'nested' => null,
    'too-many' => array_map(fn ($i) => 'ns'.$i.'.example.com', range(1, 13)),
    'legacy' => array_map(fn ($i) => 'dns'.$i.'.name-services.com', range(1, 5)),
    'reconcile' => ['NS2.Example.COM.', 'NS1.EXAMPLE.COM.'],
];
foreach ($nsCases as $case => $readback) {
    $nsOrder = new App\Models\Order(['user_id' => $user->id, 'currency_code' => 'USD']);
    $nsOrder->send_create_email = false; $nsOrder->save();
    $nsService = $nsOrder->services()->create(['user_id' => $user->id, 'currency_code' => 'USD', 'product_id' => $product->id,
        'plan_id' => $plan->id, 'price' => 15, 'quantity' => 1, 'coupon_id' => null, 'status' => App\Models\Service::STATUS_ACTIVE,
        'expires_at' => '2028-09-12']);
    $nsDomain = Paymenter\Extensions\Servers\Enom\Models\Domain::create(['service_id' => $nsService->id, 'user_id' => $user->id,
        'server_id' => $server->id, 'domain' => 'ns-comparison-'.$case.'.com', 'sld' => 'ns-comparison-'.$case, 'tld' => 'com',
        'status' => 'active', 'provider_expires_at' => '2028-10-12', 'capabilities' => ['nameservers' => true]]);
    $nsCalls = [];
    $nsClient = new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('synthetic', 'synthetic', function ($command) use (&$nsCalls, $readback, $case) {
        $nsCalls[] = $command;
        if ($command === 'ModifyNS') {
            if ($case === 'reconcile') { return '<interface-response><Command>ModifyNS</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>520</RRPCode></interface-response>'; }
            return xml($command, '<RRPCode>200</RRPCode>');
        }
        if ($command === 'GetDNS') {
            return xml($command, $readback === null ? '<DNS><Name>ns1.example.com</Name></DNS>'
                : implode('', array_map(fn ($name) => '<DNS>'.htmlspecialchars($name, ENT_XML1).'</DNS>', $readback)));
        }
        throw new RuntimeException('Unexpected nameserver test command.');
    });
    $nsOperations = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(new Paymenter\Extensions\Servers\Enom\Api\EnomApi($nsClient), fn () => ['uid' => 'synthetic', 'token' => 'synthetic']);
    $nsRequested = $case === 'legacy' ? ['dns1.name-services.com', 'dns2.name-services.com'] : $nsPair;
    $nsOperation = $nsOperations->enqueueManagement($user, $nsDomain->id, 'nameservers', ['confirmed' => true, 'nameservers' => $nsRequested], hash('sha256', 'ns-'.$case));
    $nsResult = $nsOperations->execute($nsOperation->id);
    if ($case === 'equivalent') {
        check($nsResult->state === 'succeeded', 'Reversed uppercase dotted nameserver set did not confirm.');
        $nsOperations->execute($nsOperation->id);
        check(count(array_filter($nsCalls, fn ($command) => $command === 'ModifyNS')) === 1, 'Equivalent confirmed nameservers were resent.');
    } elseif ($case === 'reconcile') {
        check($nsResult->state === 'uncertain', 'Ambiguous nameserver send was not preserved.');
        check($nsOperations->reconcile($nsOperation->id)->state === 'succeeded', 'Read-only reconciliation failed to confirm equivalent NS RRset.');
        check($nsCalls === ['ModifyNS', 'GetDNS'], 'Nameserver reconciliation resent a mutation.');
    } else {
        check($nsResult->state === 'uncertain', 'Invalid or incomplete nameserver set was confirmed: '.$case);
        check($nsOperations->reconcile($nsOperation->id)->state === 'uncertain'
            && count(array_filter($nsCalls, fn ($command) => $command === 'ModifyNS')) === 1, 'Invalid NS reconciliation changed history or resent: '.$case);
        if ($case === 'legacy') {
            $blockedRows = Illuminate\Support\Facades\DB::table('enom_operations')->orderBy('id')->get()->toArray();
            $blockedJobs = Illuminate\Support\Facades\DB::table('jobs')->orderBy('id')->get()->toArray();
            $refused = false;
            try { $nsOperations->enqueueManagement($user, $nsDomain->id, 'nameservers', ['confirmed' => true, 'nameservers' => $nsRequested], hash('sha256', 'ns-legacy-blocked')); }
            catch (InvalidArgumentException) { $refused = true; }
            check($refused && $nsOperation->fresh()->state === 'uncertain'
                && Illuminate\Support\Facades\DB::table('enom_operations')->orderBy('id')->get()->toArray() == $blockedRows
                && Illuminate\Support\Facades\DB::table('jobs')->orderBy('id')->get()->toArray() == $blockedJobs
                && count(array_filter($nsCalls, fn ($command) => $command === 'ModifyNS')) === 1, 'Legacy two-vs-five uncertainty accepted a new request or altered history.');
        }
    }
}
// Preserve bounded rejection diagnostics without changing state, funding or replay rules.
$historyIds = [$dsOperation->id, $transferOperation->id];
$historyBefore = Illuminate\Support\Facades\DB::table('enom_operations')->whereIn('id', $historyIds)->orderBy('id')->get()->toArray();
$financialBefore = [
    Illuminate\Support\Facades\DB::table('invoices')->orderBy('id')->get()->toArray(),
    Illuminate\Support\Facades\DB::table('invoice_transactions')->orderBy('id')->get()->toArray(),
];
$fundDomain = $fundDomain->fresh();
$fundDomain->update(['capabilities' => array_merge($fundDomain->capabilities ?? [], ['lock' => true])]);
$diagnosticDueBefore = $fundDomain->service->expires_at->format('Y-m-d');
foreach (['530', '304151'] as $refusalCode) {
    $refusalCalls = 0;
    $refusalClient = new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('synthetic', 'synthetic', function ($command) use (&$refusalCalls, $refusalCode) {
        $refusalCalls++;
        return '<interface-response><Command>'.$command.'</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>'.$refusalCode.'</RRPCode><Err1>Private provider description must not be retained</Err1></interface-response>';
    });
    $refusalOperations = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(new Paymenter\Extensions\Servers\Enom\Api\EnomApi($refusalClient), fn () => ['uid' => 'synthetic', 'token' => 'synthetic']);
    $refusalOperation = $operations->enqueueManagement($user, $fundDomain->id, 'lock', ['confirmed' => true, 'locked' => '1'], hash('sha256', 'diagnostic-'.$refusalCode));
    check($operations->funded($refusalOperation), 'Diagnostic refusal fixture was not authorized.');
    $refused = $refusalOperations->execute($refusalOperation->id);
    check($refused->state === 'rejected' && $refused->safe_code === 'provider_rejected:'.$refusalCode, 'Numeric rejection code was not retained with the rejected outcome.');
    check($refused->completed_at !== null && $refused->payload === null && $refused->lease_expires_at === null, 'Diagnostic rejection changed terminal clearing.');
    $refusalOperations->execute($refused->id);
    check($refusalCalls === 1, 'Rejected operation was resent to retain diagnostics.');
}
$lateRefusalCalls = 0;
$lateRefusalClient = new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('synthetic', 'synthetic', function ($command) use (&$lateRefusalCalls) {
    $lateRefusalCalls++;
    return xml($command);
}, static function () { throw new RuntimeException('Private guard configuration'); });
$lateRefusalOperations = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(new Paymenter\Extensions\Servers\Enom\Api\EnomApi($lateRefusalClient), fn () => ['uid' => 'synthetic', 'token' => 'synthetic']);
$lateRefusalOperation = $operations->enqueueManagement($user, $fundDomain->id, 'lock', ['confirmed' => true, 'locked' => '1'], hash('sha256', 'diagnostic-late-guard'));
$lateRefused = $lateRefusalOperations->execute($lateRefusalOperation->id);
check($lateRefused->state === 'rejected' && $lateRefused->safe_code === 'guard_refused' && $lateRefusalCalls === 0, 'Late local refusal was indistinguishable from a provider rejection or reached transport.');
check($lateRefused->completed_at !== null && $lateRefused->payload === null, 'Local refusal changed terminal lifecycle.');
$lateRefusalOperations->execute($lateRefused->id);
check($lateRefusalCalls === 0, 'Local rejection was resent after a second execute.');
// Missing, malformed and secret-bearing strings must never become retained provider diagnostics.
$codeBoundary = new ReflectionMethod(Paymenter\Extensions\Servers\Enom\Services\DomainOperations::class, 'rejectionCode');
foreach ([null, '', '1234567', '+530', '５３０', "530\nSECRET", 'guard_refused SECRET'] as $unsafeCode) {
    check($codeBoundary->invoke($operations, $unsafeCode) === 'provider_rejected', 'Malformed rejection diagnostic was persisted.');
}
$ambiguousCalls = 0;
$ambiguousClient = new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('synthetic', 'synthetic', function ($command) use (&$ambiguousCalls) {
    $ambiguousCalls++;
    return '<interface-response><Command>'.$command.'</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>530</RRPCode><OrderID>999</OrderID></interface-response>';
});
$ambiguousOperations = new Paymenter\Extensions\Servers\Enom\Services\DomainOperations(new Paymenter\Extensions\Servers\Enom\Api\EnomApi($ambiguousClient), fn () => ['uid' => 'synthetic', 'token' => 'synthetic']);
$ambiguousOperation = $operations->enqueueManagement($user, $fundDomain->id, 'lock', ['confirmed' => true, 'locked' => '1'], hash('sha256', 'diagnostic-ambiguous'));
$ambiguous = $ambiguousOperations->execute($ambiguousOperation->id);
check($ambiguous->state === 'uncertain' && $ambiguous->safe_code === 'provider_unknown' && $ambiguous->provider_order_id === '999', 'Error with order evidence became a definitive rejection.');
check($ambiguous->payload !== null && $ambiguous->completed_at === null, 'Ambiguous reply lost recovery data.');
$ambiguousOperations->execute($ambiguous->id);
check($ambiguousCalls === 1, 'Ambiguous operation was resent for diagnostics.');
check(Illuminate\Support\Facades\DB::table('enom_operations')->whereIn('id', $historyIds)->orderBy('id')->get()->toArray() == $historyBefore, 'Diagnostic change rewrote old uncertain or rejected history.');
check([
    Illuminate\Support\Facades\DB::table('invoices')->orderBy('id')->get()->toArray(),
    Illuminate\Support\Facades\DB::table('invoice_transactions')->orderBy('id')->get()->toArray(),
] == $financialBefore && $fundDomain->fresh()->service->expires_at->format('Y-m-d') === $diagnosticDueBefore, 'Rejected diagnostics changed financial data or billing due dates.');
// Runtime factory must reject the synthetic app even when caller changes configuration.
// Disabled automation cannot mutate stale claims/drift, enqueue work or call the provider.
$server->settings()->where('key', 'operations_enabled')->firstOrFail()->update(['value' => false]);
$resetSweep = function (): void {
    foreach (['operations', 'costs', 'domains'] as $part) { Illuminate\Support\Facades\Cache::forget('enom:sweep:'.$part); }
};
$reconciliationJobs = fn () => Illuminate\Support\Facades\DB::table('jobs')->get()->filter(fn ($job) =>
    (json_decode($job->payload, true)['displayName'] ?? null) === Paymenter\Extensions\Servers\Enom\Jobs\ReconcileOperation::class)->count();
$resetSweep();
$beforeDisabled = [
    Illuminate\Support\Facades\DB::table('enom_operations')->orderBy('id')->get()->toArray(),
    Illuminate\Support\Facades\DB::table('enom_domains')->orderBy('id')->get()->toArray(),
    Illuminate\Support\Facades\DB::table('jobs')->orderBy('id')->get()->toArray(),
];
$beforeProviderCalls = $providerCalls;
check($operations->sweep() === ['queued' => 0, 'uncertain' => 0, 'drift' => 0], 'Disabled provider automation reported work.');
$afterDisabled = [
    Illuminate\Support\Facades\DB::table('enom_operations')->orderBy('id')->get()->toArray(),
    Illuminate\Support\Facades\DB::table('enom_domains')->orderBy('id')->get()->toArray(),
    Illuminate\Support\Facades\DB::table('jobs')->orderBy('id')->get()->toArray(),
];
check($beforeDisabled == $afterDisabled && $beforeProviderCalls === $providerCalls,
    'Disabled sweep mutated history/drift/jobs or called the provider.');
$readsSetting = $server->settings()->create(['key' => 'reads_enabled', 'value' => true, 'type' => 'boolean']);
$resetSweep();
$beforeReconcileJobs = $reconciliationJobs();
$summary = $operations->sweep();
check($summary['queued'] === 0 && $summary['uncertain'] === 0 && $reconciliationJobs() > $beforeReconcileJobs
    && $beforeProviderCalls === $providerCalls, 'Explicit reads-only permission failed to enqueue reconciliation without spending.');
$readsSetting->update(['value' => false]);
$server->settings()->where('key', 'operations_enabled')->firstOrFail()->update(['value' => true]);
$resetSweep();
$beforeReconcileJobs = $reconciliationJobs();
$operations->sweep();
check($reconciliationJobs() === $beforeReconcileJobs && $beforeProviderCalls === $providerCalls,
    'Explicit disabled reads were overridden by operations-enabled during sweep.');
$readsSetting->delete();
try { Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard::assertProvider($server->id); check(false, 'Unconfigured runtime accepted.'); } catch (RuntimeException) { check(true, 'Runtime guard rejected isolated test app.'); }
$operationCountBeforeLifecycle = Paymenter\Extensions\Servers\Enom\Models\Operation::count();
$extension->installed(); $extension->uninstalled();
check(Paymenter\Extensions\Servers\Enom\Models\Operation::count() === $operationCountBeforeLifecycle, 'Reinstall/remove discarded operation history.');
// Managed privacy keeps its own paid obligation usable across domain renewal revisions.
Carbon\Carbon::setTestNow('2026-10-12 12:00:00');Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
$managedPrivacyService=$domain->fresh()->service->replicate();$managedPrivacyService->expires_at=null;$managedPrivacyService->status='active';$managedPrivacyService->save();
$managedPrivacy=$domain->fresh()->replicate();$managedPrivacy->service_id=$managedPrivacyService->id;$managedPrivacy->domain='managed-privacy.com';$managedPrivacy->sld='managed-privacy';
$managedPrivacy->billing_managed=true;$managedPrivacy->authority_generation=1;$managedPrivacy->confirmed_revision=1;$managedPrivacy->capabilities=['privacy_purchase'=>true];$managedPrivacy->save();
$privacyExists=false;$privacyExpiry='2027-10-12';$privacyRenewAdvances=true;
foreach(['purchase','renew'] as $privacyAction){
    if($privacyAction==='renew')$managedPrivacy->update(['snapshot'=>['privacy'=>['expires_at'=>$privacyExpiry]],'capabilities'=>['privacy_renew'=>true]]);
    $managedPrivacyInvoice=$orders->privacy($user,$managedPrivacy->id,$privacyAction,hash('sha256','managed-'.$privacyAction));
    $managedPrivacyOp=Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id',$managedPrivacyInvoice->id)->sole();
    check($managedPrivacyOp->policy===['dispatch_marker_version'=>1]&&$managedPrivacyOp->state==='awaiting_payment','New managed privacy did not retain marker-only policy.');
    $managedPrivacy->increment('confirmed_revision');
    App\Helpers\ExtensionHelper::addPayment($managedPrivacyInvoice,'Stripe',amount:5,transactionId:'synthetic-managed-privacy-'.$privacyAction);
    check($operations->funded($managedPrivacyOp->fresh())&&$managedPrivacyOp->fresh()->state==='queued','Paid managed privacy became unfunded after domain renewal revision.');
    check($operations->execute($managedPrivacyOp->id)->state==='succeeded'&&$managedPrivacyService->fresh()->expires_at===null,'Managed privacy failed or fabricated native recurring billing date.');
}
require_once $testRoot.'/app/Classes/helpers.php';
Illuminate\Support\Facades\Auth::login($user);
$app['view']->share('errors', new Illuminate\Support\ViewErrorBag);
$app['view']->addLocation($release.'/themes/default/views');
app('router')->getRoutes()->refreshNameLookups();
$manager = app(Paymenter\Extensions\Servers\Enom\Services\DomainManager::class);
$snapshot = $manager->snapshot($user, $domain->id);
$keys = array_fill_keys([...$manager::ACTIONS, 'refresh', 'renew', 'privacy_purchase', 'privacy_renew', 'renewal_preference'], str_repeat('a', 64));
$rendered = view('enom::show', ['snapshot' => $snapshot, 'keys' => $keys])->render();
check(strlen($rendered) > 100 && str_contains($rendered, 'name="_token"'), 'Full native theme/domain page failed to render.');
echo json_encode(['passed' => $checks, 'provider_writes_fake' => count($writes), 'database' => 'sqlite::memory:', 'runtime' => $testRoot], JSON_PRETTY_PRINT).PHP_EOL;
$successful = true;
