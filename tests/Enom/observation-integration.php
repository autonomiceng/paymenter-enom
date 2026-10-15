<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real stock Laravel/models, synthetic environment, in-memory SQLite, injected fake provider.
// Never load an installed app or any .env. Run: php tests/Enom/observation-integration.php
$repo = dirname(__DIR__, 2);
$successful = false;
register_shutdown_function(function () use (&$successful) { if (!$successful) { exit(1); } });
$release = paymenterTestApp();
if (!is_file($release.'/vendor/autoload.php') || is_file($release.'/.env')) { throw new RuntimeException('A pristine release with vendor and no environment file is required.'); }
$testRoot = $repo.'/.scratch/enom-observation-test-'.bin2hex(random_bytes(5));
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Services\DomainObservation;
use Paymenter\Extensions\Servers\Enom\Services\RegistrarMoney;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;

App\Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$']);
$user = App\Models\User::create(['email' => 'observe@example.invalid', 'password' => 'synthetic']);
$other = App\Models\User::create(['email' => 'other@example.invalid', 'password' => 'synthetic']);
$server = App\Models\Server::create(['name' => 'Offline Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$category = App\Models\Category::create(['name' => 'Offline', 'slug' => 'offline']);
$product = App\Models\Product::create(['name' => 'Offline existing domains', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'Year', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$sequence = 0;
function fixture(string $tld = 'com'): Domain {
    global $sequence, $user, $server, $product, $plan;
    $sequence++;
    return Illuminate\Database\Eloquent\Model::withoutEvents(function () use ($tld) {
        global $sequence, $user, $server, $product, $plan;
        $order = App\Models\Order::create(['user_id' => $user->id, 'currency_code' => 'USD']);
        $service = $order->services()->create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => '15.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => 'active', 'expires_at' => '2027-09-05']);
        $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id,
            'domain' => 'observe'.$sequence.'.'.$tld, 'sld' => 'observe'.$sequence, 'tld' => $tld,
            'status' => 'active', 'provider_expires_at' => '2027-10-05',
            'snapshot' => ['nameservers' => ['ns1.example.invalid'], 'contacts' => ['registrant' => ['email_address' => 'retained@example.invalid']], 'reserved' => ['preserve' => true]]]);
        Operation::create(['domain_id' => $domain->id, 'service_id' => $service->id, 'operation_key' => 'uncertain-'.$sequence,
            'action' => 'renew', 'state' => 'uncertain', 'currency' => 'USD', 'amount' => '15.00', 'payload' => ['private' => 'retained']]);
        return $domain;
    });
}
function envelope(string $command, string $body): string { return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>'; }
function reader(Domain $domain, array $overrides = [], ?Closure $race = null, ?array &$configuration = null): array {
    $configuration ??= ['uid' => 'offline-account', 'token' => 'offline-token', 'sandbox' => true, 'reads_enabled' => true, 'operations_enabled' => false];
    $commands = new ArrayObject;
    $configReads = new ArrayObject;
    $transport = function (string $command, array $parameters) use ($domain, $overrides, $race, $commands): string {
        $commands->append($command);
        if ($command !== 'GetExpiredDomains') { verify(($parameters['SLD'] ?? null) === $domain->sld && ($parameters['TLD'] ?? null) === $domain->tld, 'Observer queried an unbound domain.'); }
        if ($race && $command === 'GetDomainExp') { $race(); }
        if (isset($overrides[$command])) { return $overrides[$command]; }
        return envelope($command, match ($command) {
            'GetDomainInfo' => '<GetDomainInfo><domainname sld="'.$domain->sld.'" tld="'.$domain->tld.'" domainnameid="456">'.$domain->domain.'</domainname><status><expiration UTC="2027-10-05T00:00:00Z">10/4/2027 5:00:00 PM</expiration><registrationstatus>Registered</registrationstatus><purchase-status>Paid</purchase-status><belongs-to>offline-account</belongs-to><restorable>True</restorable></status></GetDomainInfo>',
            'GetDomainExp' => '<ExpirationDate>2027-10-05</ExpirationDate>',
            'GetRenew' => '<auto-renew>False</auto-renew>',
            'GetExtendInfo' => '<Expiration>2027-10-05</Expiration><RegistrarHold>False</RegistrarHold><MaxExtension>9</MaxExtension><Price>10.25</Price>',
            'Check' => '<Domains><Domain><Name>'.$domain->domain.'</Name><RRPCode>211</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Prices><Currency>USD</Currency><Renewal>10.25</Renewal></Prices></Domain></Domains>',
            'GetExpiredDomains' => '<DomainCount>1</DomainCount><DomainDetail><DomainName>'.$domain->domain.'</DomainName><DomainNameID>456</DomainNameID><Status>Expired</Status><Expiration-Date>2027-10-05</Expiration-Date></DomainDetail>',
            default => throw new RuntimeException('Observer attempted a registrar mutation.'),
        });
    };
    $guard = function (int $serverId, bool $readOnly) use (&$configuration, $configReads): array {
        $configReads->append([$serverId, $readOnly]);
        return $configuration;
    };
    return [new DomainObservation(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport, null, true)), $guard), $commands, $configReads];
}
function finances(): array {
    $tables = ['services', 'invoices', 'invoice_items', 'invoice_transactions', 'service_cancellations', 'enom_operations', 'enom_order_intents', 'jobs', 'failed_jobs'];
    $result = [];
    foreach ($tables as $table) { $result[$table] = json_encode(DB::table($table)->orderBy('id')->get()); }
    return $result;
}
foreach (['com', 'net', 'us'] as $tld) {
    Cache::flush();
    $domain = fixture($tld);
    [$observer, $commands, $configReads] = reader($domain);
    $before = finances();
    verify($observer->observe($domain->id) === 'observed', 'Bound existing registration was not observed.');
    $fresh = $domain->fresh();
    verify($commands->getArrayCopy() === ['GetDomainInfo', 'GetDomainExp', 'GetRenew', 'GetExtendInfo'], 'Read-only observer changed its command path.');
    verify(count($configReads) >= 6 && count(array_filter($configReads->getArrayCopy(), fn ($row) => $row[1] === true)) === count($configReads), 'Read permissions were not rechecked.');
    verify(finances() === $before, 'Observation changed native money, operations, billing or jobs.');
    verify($fresh->provider_expires_at->format('Y-m-d') === '2027-10-05' && $fresh->status === 'active', 'Observation changed confirmed state.');
    verify($fresh->observation['status'] === 'fresh' && $fresh->observation['grace_eligible'] === null, 'Restorable flag fabricated grace eligibility.');
    verify($fresh->snapshot['reserved']['preserve'] && $fresh->snapshot['nameservers'] === ['ns1.example.invalid'], 'Observation replaced management snapshots.');
    verify($fresh->observation['renewal_quote']['complete'] === false && $fresh->observation['renewal_quote']['currency'] === null, 'Base TLD price fabricated complete cost.');
    verify(!array_key_exists('observation', $fresh->toArray()) && !str_contains(json_encode($fresh), '10.25'), 'Private observation cost serialized.');
    verify(!str_contains(DB::table('enom_domains')->where('id', $domain->id)->value('observation'), 'offline-account'), 'Private observation was stored without encryption.');
    $good = $fresh->observation['sources']['expiry'];
    [$failed] = reader($domain, ['GetDomainExp' => '<invalid']);
    verify($failed->observe($domain->id) === 'partial', 'Failed expiry read appeared complete.');
    $health = $domain->fresh()->observation['sources']['expiry'];
    verify($health['value'] === $good['value'] && $health['succeeded_at'] === $good['succeeded_at'] && $health['status'] === 'stale', 'Failure erased last good expiry.');
}
// Matching authenticated expiry drift remains advisory, including an earlier date.
foreach (['2026-10-05' => 'earlier', '2028-10-05' => 'later'] as $expiry => $drift) {
    Cache::flush(); $domain = fixture();
    [$observer] = reader($domain, ['GetDomainExp' => envelope('GetDomainExp', '<ExpirationDate>'.$expiry.'</ExpirationDate>')]);
    verify($observer->observe($domain->id) === 'observed' && $domain->fresh()->observation['expiry_drift'] === $drift, 'Observed expiry drift was lost.');
    verify($domain->fresh()->provider_expires_at->format('Y-m-d') === '2027-10-05', 'Drift rewrote confirmed expiry.');
}
// Failed membership blocks every other read and cannot declare transfer-away.
Cache::flush(); $domain = fixture();
[$observer, $commands] = reader($domain, ['GetDomainInfo' => envelope('GetDomainInfo', '<GetDomainInfo><domainname domainnameid="456">'.$domain->domain.'</domainname><status><belongs-to>foreign-account</belongs-to></status></GetDomainInfo>')]);
verify($observer->observe($domain->id) === 'identity_unconfirmed' && $commands->getArrayCopy() === ['GetDomainInfo'], 'Out-of-account read reached expiry or writes.');
verify($domain->fresh()->status === 'active', 'Missing membership declared transfer or cancellation.');
// Service ownership, product/provider, canonical name and archived lifecycle refuse all reads.
foreach (['owner', 'server', 'canonical', 'retired'] as $fault) {
    Cache::flush(); $domain = fixture();
    if ($fault === 'owner') { $domain->update(['user_id' => $other->id]); }
    if ($fault === 'server') { $domain->update(['server_id' => $server->id + 100]); }
    if ($fault === 'canonical') { $domain->update(['domain' => strtoupper($domain->domain)]); }
    if ($fault === 'retired') { $domain->update(['status' => 'transferred_away']); }
    [$observer, $commands] = reader($domain);
    verify($observer->observe($domain->id) === 'ineligible' && count($commands) === 0, 'Invalid binding made provider reads.');
}
// A read begun before a confirmed renewal or transfer must not apply afterward.
foreach (['revision', 'authority', 'anchor', 'owner', 'lifecycle', 'account'] as $race) {
    Cache::flush(); $domain = fixture(); $configuration = null;
    $callback = function () use ($domain, $race, $other, &$configuration) {
        if ($race === 'revision') { $domain->update(['confirmed_revision' => 1, 'provider_expires_at' => '2028-10-05', 'snapshot' => ['new_confirmed' => true]]); }
        if ($race === 'authority') { $domain->update(['authority_generation' => 1]); }
        if ($race === 'anchor') { $domain->update(['provider_domain_id' => '999']); }
        if ($race === 'owner') { $domain->update(['user_id' => $other->id]); }
        if ($race === 'lifecycle') { $domain->service->update(['status' => 'cancelled']); }
        if ($race === 'account') { $configuration['uid'] = 'replacement-account'; }
    };
    [$observer] = reader($domain, [], $callback, $configuration);
    verify($observer->observe($domain->id) === 'stale', 'Delayed observation crossed a changed binding or confirmed revision.');
    verify(!isset($domain->fresh()->observation['sources']['expiry']['value']), 'Stale read overwrote observation values.');
    if ($race === 'revision') { verify($domain->fresh()->snapshot === ['new_confirmed' => true], 'Delayed read replaced renewal snapshot.'); }
}
// Ordinary reads still cover do-not-renew, sponsored/free and suspended services.
Cache::flush(); $domain = fixture();
$domain->service->update(['status' => 'suspended', 'price' => 0]);
App\Models\ServiceCancellation::withoutEvents(fn () => App\Models\ServiceCancellation::create(['service_id' => $domain->service_id, 'type' => 'end_of_period', 'reason' => 'fixture']));
[$observer] = reader($domain);
verify($observer->observe($domain->id) === 'observed' && $domain->service->fresh()->cancellation !== null, 'Observer lost continuing registration responsibility.');
// One account budget cannot be bypassed with different native server IDs.
Cache::flush(); $domain = fixture(); $configuration = ['uid' => 'offline-account', 'token' => 'offline-token', 'reads_enabled' => true, 'operations_enabled' => false, 'observer_requests_per_minute' => 4];
[$observer] = reader($domain, [], null, $configuration);
verify($observer->observe($domain->id) === 'observed', 'Four-read configured budget was refused.');
$alias = App\Models\Server::create(['name' => 'Duplicate account', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
$aliasProduct = $product->replicate(); $aliasProduct->server_id = $alias->id; $aliasProduct->save();
$next = fixture(); $next->service->update(['product_id' => $aliasProduct->id]); $next->update(['server_id' => $alias->id]);
[$limited, $commands] = reader($next, [], null, $configuration);
verify($limited->observe($next->id) === 'rate_limited' && count($commands) === 0, 'Duplicate configuration escaped the account request budget.');
$configuration['observer_requests_per_minute'] = 300;
verify($limited->observe($next->id) === 'rate_limited' && count($commands) === 0, 'Higher alias limit bypassed the remembered account minimum.');
// Explicit false read permission wins over the old operations flag.
Cache::flush(); $domain = fixture(); $configuration = ['uid' => 'offline-account', 'token' => 'offline-token', 'reads_enabled' => false, 'operations_enabled' => true];
[$observer, $commands] = reader($domain, [], null, $configuration);
verify($observer->observe($domain->id) === 'guard_refused' && count($commands) === 0, 'Explicit disabled reads inherited write permission.');
$configuration = ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true];
[$observer] = reader($domain, [], null, $configuration);
verify($observer->observe($domain->id) === 'observed', 'Legacy read permission was not preserved.');
// Command rejection happens before even the injected transport and guard.
$transportCalls = $guardCalls = 0;
$client = new RegistrarClient('synthetic', 'secret', function () use (&$transportCalls) { $transportCalls++; return ''; }, function () use (&$guardCalls) { $guardCalls++; }, true);
foreach (['Extend', 'Purchase', 'SetRenew', 'SynchAuthInfo', 'Contacts', 'TP_CreateOrder', 'SetHosts'] as $command) {
    verify($client->execute($command)->code === 'read_only', 'Read-only client admitted a write.');
}
verify($transportCalls === 0 && $guardCalls === 0, 'Write refusal reached a transport or guard.');
$writeApi = new EnomApi(new RegistrarClient('synthetic', 'secret', fn ($command) => envelope($command, '<RRPCode>200</RRPCode>')));
// 0.3: the addressable endings are the server catalogue plus endings of existing registrations (com/net/us by default).
// Anything else is refused before the transport; adding it to the server's suffixes is what admits it.
foreach (['register', 'renew', 'search'] as $method) {
    try { $method === 'register' ? $writeApi->register('existing.org', []) : $writeApi->$method('existing.org'); verify(false, 'Ending outside the server catalogue was addressed.'); }
    catch (InvalidArgumentException) { verify(true, 'Catalogue remains bounded.'); }
}
$sent = [];
$countingClient = function () use (&$sent) { return new RegistrarClient('synthetic', 'secret', function ($command, $params) use (&$sent) { $sent[] = $params['SLD'].'.'.$params['TLD']; return envelope($command, '<RRPCode>200</RRPCode>'); }); };
(new EnomApi($countingClient()))->search('existing.net');
verify($sent === ['existing.net'], 'Retained existing-registration ending was refused.');
$catalogueApi = (new EnomApi($countingClient()))->withSuffixes(['ORG', '.io', 'bad suffix!', 'co.uk']);
verify($catalogueApi->suffixes() === ['com', 'net', 'us', 'org', 'io', 'co.uk'], 'Catalogue suffixes were not normalized or deduplicated.');
$catalogueApi->search('existing.org'); $catalogueApi->search('name.co.uk');
verify($sent === ['existing.net', 'existing.org', 'name.co.uk'], 'Catalogue ending was refused or split at the wrong label.');
try { $catalogueApi->search('xn--bcher-kva.org'); verify(false, 'International name was addressed.'); } catch (InvalidArgumentException) { verify(true, 'IDN remains unavailable.'); }
verify((new EnomApi($client))->existingRenew('existing.us')->code === 'read_only', 'Explicit existing-domain renewal bypassed read-only mode.');
// A server without encrypted credentials still refuses even reads.
try { RuntimeGuard::assertProvider($server->id, true); verify(false, 'Server without encrypted credentials passed the runtime guard.'); }
catch (RuntimeException $e) { verify(str_contains($e->getMessage(), 'account ID and API token'), 'Credential refusal lacks its explanation.'); }
// Actual money needs exact successful order/item/domain/action/term and known USD.
$binding = ['provider_order_id' => '12345', 'domain' => 'charged.net', 'action' => 'renew', 'term' => 1];
$costBinding = array_replace($binding, ['action' => 'register']);
$item = ['producttype' => 'Register', 'description' => 'charged.net', 'quantity' => '1', 'status' => 'Successful', 'orderstatus' => 'Closed', 'amountpaid' => '10.25'];
$header = ['orderid' => '12345', 'result' => 'True', 'orderbillamount' => '$10.25', 'orderdetail' => $item];
$execution = new RegistrarResult('success', null, ['command' => 'purchase', 'orderid' => '12345']);
$cost = RegistrarMoney::actualCost(new RegistrarResult('success', null, ['order' => $header]), $costBinding, $execution);
verify($cost['state'] === 'confirmed' && $cost['amount'] === '10.25' && $cost['currency'] === 'USD', 'Documented singleton USD actual cost was not retained.');
foreach (['domain', 'term', 'action', 'order', 'multiple', 'currency', 'amount', 'pending', 'allocation', 'execution'] as $fault) {
    $candidateHeader = $header; $candidateBinding = $costBinding; $candidateExecution = $execution;
    if ($fault === 'domain') { $candidateBinding['domain'] = 'foreign.net'; }
    if ($fault === 'term') { $candidateBinding['term'] = 2; }
    if ($fault === 'action') { $candidateBinding['action'] = 'renew'; }
    if ($fault === 'order') { $candidateHeader['orderid'] = '9999'; }
    if ($fault === 'multiple') { $candidateHeader['orderdetail'] = [$item, $item]; }
    if ($fault === 'currency') { $candidateHeader['currency'] = 'EUR'; }
    if ($fault === 'amount') { $candidateHeader['orderdetail']['amountpaid'] = 'Not billed'; }
    if ($fault === 'pending') { $candidateHeader['orderdetail']['orderstatus'] = 'Open'; }
    if ($fault === 'allocation') { $candidateHeader['orderbillamount'] = '11.25'; }
    if ($fault === 'execution') { $candidateExecution = null; }
    verify(RegistrarMoney::actualCost(new RegistrarResult('success', null, ['order' => $candidateHeader]), $candidateBinding, $candidateExecution)['state'] === 'unconfirmed', 'Ambiguous actual charge was accepted: '.$fault);
}
verify(RegistrarMoney::decimal('1e2') === null && RegistrarMoney::decimal('10.255') === null && RegistrarMoney::decimal('000.00') === null && RegistrarMoney::decimal('0') === '0.00', 'Money normalization rounded or accepted ambiguous decimals.');
// Actual ordinary renewal uses only its captured Extend acknowledgement and exact Renew item.
$renewBinding = $binding + ['provider_command' => 'Extend'];
$renewItem = array_replace($item, ['producttype' => 'Renew', 'amountpaid' => '8.95']);
$renewHeader = array_replace($header, ['orderdetail' => $renewItem, 'orderbillamount' => '$8.95']);
$renewExecution = new RegistrarResult('success', null, ['command' => 'extend', 'orderid' => '12345']);
$renewCost = RegistrarMoney::actualCost(new RegistrarResult('success', null, ['order' => $renewHeader]), $renewBinding, $renewExecution);
verify($renewCost['state'] === 'confirmed' && $renewCost['amount'] === '8.95' && $renewCost['currency'] === 'USD', 'Evidenced exact ordinary Renew cost was refused.');
foreach (['missing_command','expired_command','wrong_ack','wrong_order','wrong_domain','quantity','open','failed','product','multiple','foreign_currency','mismatched_total'] as $fault) {
    $candidateHeader=$renewHeader;$candidateBinding=$renewBinding;$candidateExecution=$renewExecution;
    if($fault==='missing_command')unset($candidateBinding['provider_command']);
    if($fault==='expired_command')$candidateBinding['provider_command']='UpdateExpiredDomains';
    if($fault==='wrong_ack')$candidateExecution=new RegistrarResult('success',null,['command'=>'purchase','orderid'=>'12345']);
    if($fault==='wrong_order')$candidateHeader['orderid']='999';
    if($fault==='wrong_domain')$candidateHeader['orderdetail']['description']='foreign.net';
    if($fault==='quantity')$candidateHeader['orderdetail']['quantity']='2';
    if($fault==='open')$candidateHeader['orderdetail']['orderstatus']='Open';
    if($fault==='failed')$candidateHeader['orderdetail']['status']='Failed';
    if($fault==='product')$candidateHeader['orderdetail']['producttype']='Register';
    if($fault==='multiple')$candidateHeader['orderdetail']=[$renewItem,$renewItem];
    if($fault==='foreign_currency')$candidateHeader['orderdetail']['currency']='EUR';
    if($fault==='mismatched_total')$candidateHeader['orderbillamount']='$9.95';
    verify(RegistrarMoney::actualCost(new RegistrarResult('success',null,['order'=>$candidateHeader]),$candidateBinding,$candidateExecution)['state']==='unconfirmed','Unsafe ordinary renewal actual cost accepted: '.$fault);
}
// Immutable provider IDs refuse replacement in daily and quote observations.
Cache::flush(); $anchored = fixture(); $anchored->update(['provider_domain_id' => '999']);
[$observer, $commands] = reader($anchored);
verify($observer->observe($anchored->id) === 'identity_unconfirmed' && count($commands) === 1 && $anchored->fresh()->provider_domain_id === '999', 'Daily observation replaced its immutable provider anchor.');
$commands->exchangeArray([]);
verify($observer->observeRenewalQuote($anchored->id) === 'identity_or_guard_unconfirmed' && count($commands) === 1 && $anchored->fresh()->provider_domain_id === '999', 'Quote read bypassed the immutable provider anchor.');
// Full pricing observations carry bounded read-only identity and base-expiry evidence.
Cache::flush(); $quotedDomain = fixture(); $quotedDomain->update(['provider_domain_id' => '456']);
[$observer, $commands] = reader($quotedDomain);
$before = finances(); $beforeSnapshot = $quotedDomain->snapshot;
verify($observer->observeRenewalQuote($quotedDomain->id) === 'quoted', 'Bound ordinary read-only renewal quote was not observed.');
$quote = $quotedDomain->fresh()->observation['renewal_quote'];
verify($commands->getArrayCopy() === ['GetDomainInfo', 'GetExtendInfo', 'Check'], 'Ordinary quote invoked a write or expired query.');
verify($quote['state'] === 'quoted' && $quote['complete'] && $quote['amount'] === '10.25' && $quote['currency'] === 'USD'
    && $quote['base_expires_at'] === '2027-10-05' && $quote['provider_domain_id'] === '456'
    && $quote['confirmed_revision'] === 0 && $quote['authority_generation'] === 0 && is_string($quote['observed_at']), 'Quote lost its immutable obligation binding.');
verify(finances() === $before && $quotedDomain->fresh()->snapshot === $beforeSnapshot && $quotedDomain->fresh()->provider_expires_at->format('Y-m-d') === '2027-10-05', 'Quote observation changed native finances or confirmed snapshot.');
$observer->observe($quotedDomain->id);
verify($quotedDomain->fresh()->observation['renewal_quote'] === $quote, 'Daily advisory quote overwrote a complete internal quote.');
[$failedQuote] = reader($quotedDomain, ['Check' => '<invalid']);
verify($failedQuote->observeRenewalQuote($quotedDomain->id) === 'quote_unconfirmed' && $quotedDomain->fresh()->observation['renewal_quote'] === $quote, 'Failed pricing read erased or accepted replacement quote.');
Cache::flush(); $raceQuote = fixture();
[$observer] = reader($raceQuote, [], fn () => null);
$raceRead = new EnomApi(new RegistrarClient('offline-account', 'offline-token', function ($command, $parameters) use ($raceQuote) {
    if ($command === 'GetDomainInfo') { return envelope($command, '<GetDomainInfo><domainname domainnameid="456">'.$raceQuote->domain.'</domainname><status><expiration>2027-10-05</expiration><belongs-to>offline-account</belongs-to></status></GetDomainInfo>'); }
    if ($command === 'GetExtendInfo') { $raceQuote->update(['confirmed_revision' => 1, 'provider_expires_at' => '2028-10-05']); return envelope($command, '<Expiration>2027-10-05</Expiration><RegistrarHold>False</RegistrarHold><MaxExtension>9</MaxExtension><Price>10.25</Price>'); }
    return envelope($command, '<Domains><Domain><Name>'.$raceQuote->domain.'</Name><RRPCode>211</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Prices><Currency>USD</Currency><Renewal>10.25</Renewal></Prices></Domain></Domains>');
}, null, true));
$observer = new DomainObservation($raceRead, fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'reads_enabled' => true]);
verify($observer->observeRenewalQuote($raceQuote->id) === 'stale' && !isset($raceQuote->fresh()->observation['renewal_quote']), 'Quote crossed a renewal completion revision.');
Cache::flush(); $graceQuote = fixture(); $graceQuote->update(['provider_domain_id' => '456']);
Carbon\CarbonImmutable::setTestNow('2027-10-06 00:00:00 UTC');
[$observer, $commands] = reader($graceQuote);
verify($observer->observeRenewalQuote($graceQuote->id) === 'quoted' && $graceQuote->fresh()->observation['renewal_quote']['grace_eligible'] === true
    && $graceQuote->fresh()->observation['renewal_quote']['command'] === 'UpdateExpiredDomains', 'Expired quote refresh ignored the app business clock or exact Expired contract.');
verify($commands->getArrayCopy() === ['GetDomainInfo', 'GetExtendInfo', 'Check', 'GetExpiredDomains'], 'Expired pricing did not use its bounded four-read path.');
Carbon\CarbonImmutable::setTestNow();
// Authenticated GetDomainInfo supplies precision missing from GetExtendInfo date-only views.
Cache::flush();$calendarDomain=fixture();$calendarDomain->update(['provider_domain_id'=>'456','provider_expires_at'=>'2028-10-13']);
$calendarInfo='<GetDomainInfo><domainname domainnameid="456">'.$calendarDomain->domain.'</domainname><status><expiration>10/12/2028 11:14:00 PM</expiration><belongs-to>offline-account</belongs-to></status></GetDomainInfo>';
$calendarExtend='<Expiration>10/12/2028</Expiration><RegistrarHold>False</RegistrarHold><MaxExtension>8</MaxExtension><Price>8.95</Price>';
$calendarCheck='<Domains><Domain><Name>'.$calendarDomain->domain.'</Name><RRPCode>211</RRPCode><IsPremium>False</IsPremium><IsPlatinum>False</IsPlatinum><IsEAP>False</IsEAP><Prices><Currency></Currency><Renewal>8.95</Renewal></Prices></Domain></Domains>';
[$calendarObserver,$calendarCommands]=reader($calendarDomain,['GetDomainInfo'=>envelope('GetDomainInfo',$calendarInfo),'GetExtendInfo'=>envelope('GetExtendInfo',$calendarExtend),'Check'=>envelope('Check',$calendarCheck)]);
$beforeCalendarFinance=finances();$beforeCalendarExpiry=$calendarDomain->provider_expires_at->format('Y-m-d');$beforeCalendarService=$calendarDomain->service->getRawOriginal();
verify($calendarObserver->observeRenewalQuote($calendarDomain->id)==='quoted','Authenticated date-only quote observer failed.');
$calendarQuote=$calendarDomain->fresh()->observation['renewal_quote'];
verify($calendarQuote['complete']&&$calendarQuote['currency']==='USD'&&$calendarQuote['amount']==='8.95'&&$calendarQuote['base_expires_at']==='2028-10-13'&&$calendarQuote['expiry_source']==='GetDomainInfo_precise_la_date_reconciled'
    &&$calendarQuote['expires_at']==='2028-10-13T06:14:00.000000+00:00',
    'Observer altered UTC obligation day or lost real Check currency evidence.');
verify($calendarCommands->getArrayCopy()===['GetDomainInfo','GetExtendInfo','Check']&&finances()===$beforeCalendarFinance
    &&$calendarDomain->fresh()->provider_expires_at->format('Y-m-d')===$beforeCalendarExpiry&&$calendarDomain->fresh()->service->getRawOriginal()===$beforeCalendarService,
    'Date-only pricing observation changed native finances, provider obligation or service.');
// Domain-specific explicit USD ordinary renewal evidence, plus exact expired lifecycle.
$ordinaryCheck = new RegistrarResult('success', null, ['domains' => ['domain' => ['name' => 'charged.net', 'rrpcode' => '211',
    'ispremium' => 'false', 'isplatinum' => 'false', 'iseap' => 'false', 'prices' => ['currency' => 'USD', 'renewal' => '10.25']]]]);
$ordinaryExtend = new RegistrarResult('success', null, ['expiration' => '2027-10-05', 'price' => '10.25', 'registrarhold' => 'false', 'maxextension' => '9']);
$quote = RegistrarMoney::renewalQuote($ordinaryExtend, $binding, $ordinaryCheck);
verify($quote['complete'] === true && $quote['command'] === 'Extend' && $quote['grace_eligible'] === false, 'Documented nonpremium ordinary quote was refused.');
verify($quote['base_expires_at'] === '2027-10-05' && is_string($quote['observed_at']), 'Complete quote omitted base expiry or business time.');
// Actual Check shape has empty currency; omitted currency shares its documented USD default.
foreach(['empty','missing'] as $shape){
    $checkData=$ordinaryCheck->data;$checkData['domains']['domain']['prices']['renewal']='8.95';
    if($shape==='empty')$checkData['domains']['domain']['prices']['currency']='';else unset($checkData['domains']['domain']['prices']['currency']);
    $actualExtend=new RegistrarResult('success',null,['expiration'=>'2027-10-05','price'=>'8.95','registrarhold'=>'False','maxextension'=>'8']);
    $actualQuote=RegistrarMoney::renewalQuote($actualExtend,$binding,new RegistrarResult('success',null,$checkData));
    verify($actualQuote['complete']&&$actualQuote['currency']==='USD'&&$actualQuote['amount']==='8.95'&&$actualQuote['currency_source']==='documented_check_prices_usd_default','Actual Check currency shape lost documented USD: '.$shape);
}
foreach([null,' ','unknown','EUR'] as $explicit){
    verify(RegistrarMoney::checkPriceCurrency(['currency'=>$explicit])!=='USD','Explicit malformed or foreign currency became default USD.');
}
verify(RegistrarMoney::checkPriceCurrency(null)===null,'Missing Prices node became a default USD quote.');
$expiredBinding = $binding + ['provider_domain_id' => '456', 'base_expires_at' => '2026-10-01'];
$expiredExtend = new RegistrarResult('success', null, ['expiration' => '2026-10-01', 'price' => '10.25', 'registrarhold' => 'false', 'maxextension' => '9']);
$expiredItem = ['domainname' => 'charged.net', 'domainnameid' => '456', 'status' => 'Expired', 'expiration-date' => '2026-10-01'];
$expiredInventory = new RegistrarResult('success', null, ['domaincount' => '1', 'domaindetail' => $expiredItem]);
$quote = RegistrarMoney::renewalQuote($expiredExtend, $expiredBinding, $ordinaryCheck, $expiredInventory);
verify($quote['complete'] && $quote['grace_eligible'] === true && $quote['command'] === 'UpdateExpiredDomains', 'Confirmed Expired state used wrong renewal command.');
$heldExpired = new RegistrarResult('success', null, array_replace($expiredExtend->data, ['registrarhold' => 'True']));
verify(RegistrarMoney::renewalQuote($heldExpired, $expiredBinding, $ordinaryCheck, $expiredInventory)['complete'] === true, 'Confirmed Expired registrar hold was incorrectly treated as a pre-expiry hold.');
$heldActive = new RegistrarResult('success', null, array_replace($ordinaryExtend->data, ['registrarhold' => 'True']));
verify(RegistrarMoney::renewalQuote($heldActive, $binding, $ordinaryCheck)['complete'] === false, 'Pre-expiry registrar hold allowed ordinary renewal.');
$clockBinding = $binding + ['provider_domain_id' => '456', 'base_expires_at' => '2027-10-05', 'observed_at' => '2028-01-01T00:00:00Z'];
$clockInventory = new RegistrarResult('success', null, ['domaincount' => '1', 'domaindetail' => array_replace($expiredItem, ['expiration-date' => '2027-10-05'])]);
$clockQuote = RegistrarMoney::renewalQuote($ordinaryExtend, $clockBinding, $ordinaryCheck, $clockInventory);
verify($clockQuote['complete'] && $clockQuote['command'] === 'UpdateExpiredDomains' && str_starts_with($clockQuote['observed_at'], '2028-01-01'), 'Quote used real wall clock instead of captured business time.');
verify(RegistrarMoney::renewalQuote($ordinaryExtend, $binding + ['observed_at' => 'invalid'], $ordinaryCheck)['complete'] === false, 'Malformed quote business time was accepted.');
foreach (['missing_inventory', 'rgp', 'extended_rgp', 'wrong_id', 'wrong_domain', 'wrong_expiry', 'duplicate', 'truncated', 'foreign_currency', 'malformed_currency', 'premium', 'price_mismatch', 'wrong_term'] as $fault) {
    $candidateCheck = $ordinaryCheck->data; $candidateInventory = $expiredInventory->data; $candidateBinding = $expiredBinding;
    if ($fault === 'rgp') { $candidateInventory['domaindetail']['status'] = 'RGP'; }
    if ($fault === 'extended_rgp') { $candidateInventory['domaindetail']['status'] = 'Extended RGP'; }
    if ($fault === 'wrong_id') { $candidateInventory['domaindetail']['domainnameid'] = '999'; }
    if ($fault === 'wrong_domain') { $candidateInventory['domaindetail']['domainname'] = 'foreign.net'; }
    if ($fault === 'wrong_expiry') { $candidateInventory['domaindetail']['expiration-date'] = '2026-10-02'; }
    if ($fault === 'duplicate') { $candidateInventory['domaindetail'] = [$expiredItem, $expiredItem]; $candidateInventory['domaincount'] = '2'; }
    if ($fault === 'truncated') { $candidateInventory['domaincount'] = '2'; }
    if ($fault === 'foreign_currency') { $candidateCheck['domains']['domain']['prices']['currency'] = 'EUR'; }
    if ($fault === 'malformed_currency') { $candidateCheck['domains']['domain']['prices']['currency'] = ' '; }
    if ($fault === 'premium') { $candidateCheck['domains']['domain']['ispremium'] = 'true'; }
    if ($fault === 'price_mismatch') { $candidateCheck['domains']['domain']['prices']['renewal'] = '11.00'; }
    if ($fault === 'wrong_term') { $candidateBinding['term'] = 2; }
    $candidate = RegistrarMoney::renewalQuote($expiredExtend, $candidateBinding, new RegistrarResult('success', null, $candidateCheck), $fault === 'missing_inventory' ? null : new RegistrarResult('success', null, $candidateInventory));
    verify($candidate['complete'] === false && $candidate['grace_eligible'] === null, 'Unsupported expired/cost evidence admitted automatic grace: '.$fault);
}
$expiredClient = new RegistrarClient('synthetic', 'secret', fn ($command, $parameters) => envelope($command, '<ReactivateDomainName><Status>True</Status><OrderID>12345</OrderID></ReactivateDomainName>'));
$acknowledgement = (new EnomApi($expiredClient))->expiredRenew('charged.net');
verify($acknowledgement->status === 'pending' && $acknowledgement->confirmedExpiry() === null && $acknowledgement->value('reactivatedomainname.orderid') === '12345', 'Expired renewal acknowledgement prematurely confirmed expiry.');
verify(RegistrarMoney::actualCost(new RegistrarResult('success', null, ['order' => $header]), $binding + ['provider_command' => 'UpdateExpiredDomains'], $acknowledgement)['state'] === 'unconfirmed', 'Undocumented expired-renewal order-item type confirmed cost.');
verify(RegistrarMoney::actualCost(new RegistrarResult('success', null, ['order' => $header]), $binding, $acknowledgement)['state'] === 'unconfirmed', 'Expired charge bypassed its captured mutation command.');
verify($client->execute('UpdateExpiredDomains')->code === 'read_only', 'Read-only client admitted expired renewal.');
foreach ([['DomainName' => 'charged.net', 'NumYears' => 2, 'UseCreditCard' => 'no'], ['DomainName' => 'charged.net,foreign.net', 'NumYears' => 1, 'UseCreditCard' => 'no'], ['DomainName' => 'charged.net', 'NumYears' => 1], ['DomainName' => 'charged.net', 'NumYears' => 1, 'UseCreditCard' => 'no', 'CustomerSuppliedPrice' => '1.00']] as $parameters) {
    try { $expiredClient->execute('UpdateExpiredDomains', $parameters); verify(false, 'Unsafe expired renewal request reached transport.'); }
    catch (InvalidArgumentException) { verify(true, 'Expired mutation remains exact and bounded.'); }
}
$successful = true;
echo json_encode(['status' => 'ok', 'checks' => $checks, 'provider' => 'synthetic-read-only', 'native_schema' => 'stock', 'database' => 'in-memory SQLite']).PHP_EOL;
