<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real stock Laravel/models, synthetic environment, in-memory SQLite, injected fake provider.
// Never load an installed app or any .env. Run: php tests/Enom/domain-administration.php
$repo = dirname(__DIR__, 2);
$successful = false;
register_shutdown_function(function () use (&$successful) { if (!$successful) { exit(1); } });
$release = paymenterTestApp();
if (!is_file($release.'/vendor/autoload.php') || is_file($release.'/.env')) { throw new RuntimeException('A pristine release with vendor and no environment file is required.'); }
$testRoot = $repo.'/.scratch/enom-domain-administration-test-'.bin2hex(random_bytes(5));
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
function checkBilling(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function refusedBilling(callable $action, string $message): void {
    try { $action(); }
    catch (RuntimeException) { checkBilling(true, $message); return; }
    throw new RuntimeException($message);
}
function atBilling(string $day): void { Carbon\Carbon::setTestNow($day.' 12:00:00'); }
function billingJobCount(string $class): int {
    return DB::table('jobs')->get()->filter(fn ($job) => (json_decode($job->payload, true)['displayName'] ?? null) === $class)->count();
}
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
$server->settings()->create(['key' => 'uid', 'value' => 'test-account', 'type' => 'text']);
$category = App\Models\Category::create(['name' => 'Domain billing test', 'slug' => 'domain-billing-test']);
$product = App\Models\Product::create(['name' => 'Managed domain', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'One year', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$plan->prices()->create(['currency_code' => 'USD', 'price' => 15, 'setup_fee' => 0]);
App\Models\Gateway::create(['name' => 'Synthetic gateway', 'type' => 'gateway', 'extension' => 'Stripe', 'enabled' => true]);
$operations = new DomainOperations;
$billing = new DomainBilling($operations);
$guard = fn (int $id, bool $readOnly) => ['uid' => 'test-account', 'token' => 'offline', 'reads_enabled' => true];
$admin = new Paymenter\Extensions\Servers\Enom\Services\DomainAdministration($operations, $billing, null, $guard);
$costRole = App\Models\Role::create(['name' => 'Synthetic costs staff', 'permissions' => ['admin.enom.manage', 'admin.enom.costs']]);
$costStaff = App\Models\User::create(['email' => 'cost-staff@example.invalid', 'password' => bcrypt('synthetic'), 'role_id' => $costRole->id]);
$make = function (string $name, int $price = 15) use ($user, $server, $product, $plan): Domain {
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
        'price' => $price, 'quantity' => 1, 'currency_code' => 'USD', 'status' => 'active', 'expires_at' => '2026-11-01']);
    $source = fn ($value) => ['status' => 'fresh', 'succeeded_at' => now()->toIso8601String(), 'value' => $value];
    return Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id,
        'domain' => $name.'.com', 'sld' => $name, 'tld' => 'com', 'status' => 'active', 'provider_expires_at' => '2026-12-01',
        // 0.3 backfills existing domains to a custom price so their native price is kept exactly.
        'billing_managed' => false, 'authority_generation' => 1, 'confirmed_revision' => 1, 'price_mode' => 'custom',
        'observation' => ['status' => 'fresh', 'account_fingerprint' => hash('sha256', 'sandbox:test-account'), 'expiry_drift' => 'none',
            'sources' => ['info' => $source(['domain_name_id' => '456', 'registration_status' => 'registered', 'purchase_status' => 'paid', 'expires_at' => '2026-12-01T00:00:00Z']), 'renewal' => $source(['auto_renew' => false]),
                'expiry' => $source(['expires_at' => '2026-12-01T00:00:00Z'])],
            'renewal_quote' => ['amount' => '11.99', 'currency' => 'USD', 'private' => 'internal-cost-marker']]]);
};
$policy = ['billing_managed' => '1', 'enabled' => '1', 'issue_days' => '21', 'due_days' => '7', 'credits_auto_use' => '0'];
function rejectedAdmin(callable $action, string $message): void {
    try { $action(); }
    catch (Paymenter\Extensions\Servers\Enom\Services\InputValidationException | Symfony\Component\HttpKernel\Exception\HttpException $error) { checkBilling(true, $message); return; }
    throw new RuntimeException($message);
}
function unchangedFinance(): array {
    $result = [];
    foreach (['invoices', 'invoice_items', 'invoice_transactions', 'enom_operations', 'jobs', 'failed_jobs'] as $table) { $result[$table] = json_encode(DB::table($table)->get()); }
    return $result;
}
atBilling('2026-10-13');
$domain = $make('enrollment');
$before = unchangedFinance();
$admin->savePolicy($staff, $domain->id, $policy);
$domain->refresh();
checkBilling($domain->billing_managed && $domain->service->expires_at === null && $domain->provider_domain_id === '456' && $domain->renewal_policy['account_fingerprint'] === hash('sha256', 'sandbox:test-account'), 'Enrollment did not atomically bind registrar identity and null native date.');
checkBilling(unchangedFinance() === $before, 'Enrollment created invoice, transaction, operation or job.');
$calendar = $admin->get($staff, $domain->id);
checkBilling($calendar['calendar'] === ['issue_at' => '2026-11-10', 'due_at' => '2026-11-24'], 'Admin calendar differs from21/7.');
checkBilling(!isset($calendar['costs']) && $calendar['permissions']['costs'] === false, 'No-cost staff DTO includes private costs.');
$queries = [];
DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });
$admin->get($staff, $domain->id);
checkBilling(!array_filter($queries, fn ($sql) => str_contains($sql, 'enom_operations') && (str_contains($sql, '"cost"') || str_contains($sql, '"policy"'))), 'No-cost staff query hydrated encrypted operation financial evidence.');
$notApproved = $make('unchecked');
rejectedAdmin(fn () => $admin->savePolicy($staff, $notApproved->id, array_replace($policy, ['billing_managed' => '0'])), 'Unselected mapping silently converted.');
checkBilling(!$notApproved->fresh()->billing_managed && $notApproved->service->expires_at !== null, 'Refused mapping changed native date or enrollment.');
foreach (['expired-read', 'wrong-account', 'wrong-anchor', 'renew-enabled', 'expiry-drift', 'info-expiry-conflict', 'provider-pending'] as $case) {
    $held = $make($case); $observation = $held->observation;
    if ($case === 'expired-read') { $observation['sources']['info']['succeeded_at'] = '2026-10-11T00:00:00Z'; }
    if ($case === 'wrong-account') { $observation['account_fingerprint'] = str_repeat('f', 64); }
    if ($case === 'wrong-anchor') { $held->provider_domain_id = '999'; }
    if ($case === 'renew-enabled') { $observation['sources']['renewal']['value']['auto_renew'] = true; }
    if ($case === 'info-expiry-conflict') { $observation['sources']['info']['value']['expires_at'] = '2027-12-01T00:00:00Z'; }
    if ($case === 'provider-pending') { $observation['sources']['info']['value']['registration_status'] = 'processing'; }
    if ($case === 'expiry-drift') { $observation['sources']['expiry']['value']['expires_at'] = '2027-12-01T00:00:00Z'; }
    $held->observation = $observation; $held->save();
    rejectedAdmin(fn () => $admin->savePolicy($staff, $held->id, $policy), 'Unsafe enrollment admitted '.$case); 
    checkBilling(!$held->fresh()->billing_managed, 'Refused enrollment changed mode for '.$case);
}
foreach (['awaiting_payment', 'queued', 'running', 'pending', 'uncertain', 'held'] as $state) {
    $held = $make('blocking-'.str_replace('_', '-', $state));
    Operation::create(['domain_id' => $held->id, 'service_id' => $held->service_id, 'operation_key' => 'blocking-'.$state,
        'action' => 'renew', 'state' => $state, 'currency' => 'USD', 'amount' => '15.00']);
    rejectedAdmin(fn () => $admin->savePolicy($staff, $held->id, $policy), 'Outstanding operation admitted '.$state);
}
rejectedAdmin(fn () => $admin->savePolicy($staff, $domain->id, array_replace($policy, ['issue_days' => '7'])), 'Issue on due date admitted.');
rejectedAdmin(fn () => $admin->savePolicy($staff, $domain->id, array_replace($policy, ['enabled' => ['1']])), 'Array policy boolean admitted.');
$badOwner = $make('wrong-owner');
$admin->savePolicy($staff, $badOwner->id, $policy);
$badOwner->service->update(['user_id' => $other->id]);
rejectedAdmin(fn () => $admin->savePolicy($staff, $badOwner->id, array_replace($policy, ['enabled' => '0'])), 'Disabling a mismatched mapping changed another owner service.');
$invoice = $billing->invoice($domain->id, $user);
$issued = Operation::where('domain_id', $domain->id)->where('action', 'renew')->first();
$locked = $issued->policy; $invoiceDue = $invoice->due_at->format('Y-m-d');
$admin->savePolicy($staff, $domain->id, array_replace($policy, ['issue_days' => '30', 'due_days' => '10']));
checkBilling($issued->fresh()->policy === $locked && $invoice->fresh()->due_at->format('Y-m-d') === $invoiceDue && $domain->fresh()->renewal_policy['account_fingerprint'] === hash('sha256', 'sandbox:test-account'), 'Future calendar edit changed issued financial policy.');
$admin->savePolicy($staff, $domain->id, array_replace($policy, ['enabled' => '0']));
checkBilling($domain->fresh()->billing_managed && $domain->fresh()->service->expires_at === null && $issued->fresh()->policy === $locked, 'Policy disabling handed managed billing to native date or changed issued cycle.');
$zero = $make('sponsored', 0);
$admin->savePolicy($staff, $zero->id, $policy);
$sponsorship = ['enabled' => '1', 'standing' => '0', 'limit_amount' => '20.25', 'currency' => 'USD', 'term' => '1', 'reason' => 'Individual reviewed operator-owned domain', 'approved_by' => $other->id];
$before = unchangedFinance();
$admin->saveSponsorship($staff, $zero->id, $sponsorship);
$approval = $zero->fresh()->sponsorship;
checkBilling($approval['approved_by'] === $staff->id && $approval['limit_minor'] === 2025 && $approval['standing'] === false, 'Approval actor/decimal budget/single-cycle scope is incorrect.');
checkBilling(unchangedFinance() === $before, 'Saving sponsorship purchased a renewal or fabricated funding.');
rejectedAdmin(fn () => $admin->saveSponsorship($costStaff, $zero->id, $sponsorship), 'Costs permission authorized funding.');
rejectedAdmin(fn () => $admin->saveSponsorship($staff, $domain->id, $sponsorship), 'Positive customer price admitted zero-price sponsorship.');
rejectedAdmin(fn () => $admin->saveSponsorship($staff, $zero->id, array_replace($sponsorship, ['limit_amount' => '2.005'])), 'Fractional minor-unit sponsorship admitted.');
rejectedAdmin(fn () => $admin->saveSponsorship($staff, $zero->id, array_replace($sponsorship, ['term' => '2'])), 'Unsupported sponsored term admitted.');
$costOperation = Operation::create(['domain_id' => $zero->id, 'service_id' => $zero->service_id, 'operation_key' => 'cost-evidence',
    'action' => 'renew', 'state' => 'succeeded', 'amount' => '0.00', 'currency' => 'USD',
    'cost' => ['quote' => ['state' => 'quoted', 'amount' => '11.23', 'currency' => 'USD'], 'actual' => ['state' => 'unconfirmed', 'amount' => '0.00', 'currency' => 'USD']]]);
$costData = $admin->get($costStaff, $zero->id);
checkBilling($costData['costs'][0]['quoted']['amount'] === '11.23' && $costData['costs'][0]['confirmed'] === null && !isset($costData['sponsorship']), 'Quote/unconfirmed cost distinction or fund-only sponsorship privacy failed.');
$costOperation->update(['cost' => ['quote' => ['state' => 'quoted', 'amount' => '11.23', 'currency' => 'USD'], 'actual' => ['state' => 'confirmed', 'amount' => '11.25', 'currency' => 'USD']]]);
checkBilling($admin->get($costStaff, $zero->id)['costs'][0]['confirmed']['amount'] === '11.25', 'Authoritative actual expense omitted.');
$safe = Paymenter\Extensions\Servers\Enom\Services\DomainAdministration::customer($zero->fresh());
checkBilling(!str_contains(json_encode($safe), '11.99') && !str_contains(json_encode($safe), 'limit_minor') && !str_contains(json_encode($safe), 'approved_by') && !str_contains(json_encode($safe), 'internal-cost-marker'), 'Customer DTO serialized cost or sponsorship authority.');
$foreignInvoice = Invoice::create(['user_id' => $other->id, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => '2026-11-24']);
Operation::create(['domain_id' => $zero->id, 'service_id' => $zero->service_id, 'invoice_id' => $foreignInvoice->id, 'operation_key' => 'foreign-invoice', 'action' => 'renew', 'state' => 'succeeded', 'amount' => '15.00', 'currency' => 'USD']);
checkBilling(Paymenter\Extensions\Servers\Enom\Services\DomainAdministration::customer($zero->fresh())['invoices'] === [], 'Customer domain linked a foreign invoice.');
checkBilling(Paymenter\Extensions\Servers\Enom\Services\DomainAdministration::customer($domain->fresh())['invoices'][0]['id'] === $invoice->id, 'Customer domain omitted native renewal invoice.');
$readCommands = new ArrayObject;
$failQuote = false;
$transport = function (string $command, array $parameters) use ($zero, $readCommands, &$failQuote): string {
    $readCommands->append($command);
    if ($failQuote && $command === 'Check') { return '<interface-response><Command>Check</Command><ErrCount>1</ErrCount><Done>true</Done></interface-response>'; }
    $body = match ($command) {
        'GetDomainInfo' => '<GetDomainInfo><domainname sld="'.$zero->sld.'" tld="com" domainnameid="456">'.$zero->domain.'</domainname><status><expiration UTC="2026-12-01T00:00:00Z">12/1/2026</expiration><registrationstatus>Registered</registrationstatus><purchase-status>Paid</purchase-status><belongs-to>test-account</belongs-to></status></GetDomainInfo>',
        'GetDomainExp' => '<ExpirationDate>2026-12-01</ExpirationDate>',
        'GetRenew' => '<auto-renew>False</auto-renew>',
        'GetExtendInfo' => '<Expiration>2026-12-01</Expiration><RegistrarHold>False</RegistrarHold><MaxExtension>9</MaxExtension><Price>11.23</Price>',
        'Check' => '<Domains><Domain><Name>'.$zero->domain.'</Name><RRPCode>211</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Prices><Currency>USD</Currency><Renewal>11.23</Renewal></Prices></Domain></Domains>',
        default => throw new RuntimeException('Administration attempted a provider mutation.'),
    };
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
$observer = new Paymenter\Extensions\Servers\Enom\Services\DomainObservation(new Paymenter\Extensions\Servers\Enom\Api\EnomApi(
    new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('test-account', 'offline', $transport, null, true)), $guard);
$observingAdmin = new Paymenter\Extensions\Servers\Enom\Services\DomainAdministration($operations, $billing, $observer, $guard);
$before = unchangedFinance();
checkBilling($observingAdmin->refreshObservation($staff, $zero->id) === 'observed', 'Explicit admin observation failed.');
checkBilling($readCommands->getArrayCopy() === ['GetDomainInfo', 'GetDomainExp', 'GetRenew', 'GetExtendInfo'] && unchangedFinance() === $before, 'Read-only admin refresh changed native finances, approvals or operation queue.');
$approvalBefore = $zero->fresh()->sponsorship;
$explicit = $observingAdmin->requestSponsoredRenewal($staff, $zero->id);
checkBilling($explicit && $explicit->funding_basis === 'sponsored' && $explicit->invoice_id === null && $explicit->amount === '0.00', 'Explicit sponsor request fabricated customer funding or failed complete quote.');
checkBilling($zero->fresh()->sponsorship === $approvalBefore, 'Explicit purchase silently altered individual approval.');
checkBilling(!array_intersect($readCommands->getArrayCopy(), ['Extend', 'UpdateExpiredDomains', 'Purchase']), 'Controller read/queue flow performed a registrar purchase.');
$operationCount = Operation::count(); $jobCount = DB::table('jobs')->count();
$observingAdmin->requestSponsoredRenewal($staff, $zero->id);
checkBilling(Operation::count() === $operationCount && DB::table('jobs')->count() === $jobCount, 'Duplicate explicit sponsorship created another request or job.');
$failQuote = true;
$before = unchangedFinance();
checkBilling($observingAdmin->requestSponsoredRenewal($staff, $zero->id) === null && unchangedFinance() === $before, 'Failed latest quote reused a previous complete quote to authorize a request.');
$server->settings()->create(['key' => 'issue_days', 'value' => 30, 'type' => 'number']);
$server->settings()->create(['key' => 'due_days', 'value' => 10, 'type' => 'number']);
$zero->refresh()->update(['renewal_policy' => ['enabled' => true]]);
$accountCalendar = Paymenter\Extensions\Servers\Enom\Services\DomainAdministration::customer($zero->fresh());
checkBilling($accountCalendar['calendar'] === ['issue_at' => '2026-11-01', 'due_at' => '2026-11-21'], 'Customer calendar ignored configured account defaults.');
$zero->refresh()->update(['renewal_policy' => ['enabled' => true, 'issue_days' => 0, 'due_days' => 7]]);
checkBilling(Paymenter\Extensions\Servers\Enom\Services\DomainAdministration::customer($zero->fresh())['calendar'] === ['issue_at' => null, 'due_at' => null], 'Invalid calendar displayed invented default dates.');
checkBilling(json_encode(DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name")) === json_encode($nativeSchema), 'Administration changed native schema.');
fwrite(STDOUT, "Domain administration native admission: {$checks} passed\n");
$successful = true;
(new Illuminate\Filesystem\Filesystem)->deleteDirectory($testRoot);
