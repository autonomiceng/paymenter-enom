<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real stock Laravel/models, synthetic environment, in-memory SQLite, injected fake provider.
// Never load an installed app or any .env. Run: php tests/Enom/billing-integration.php
$repo = dirname(__DIR__, 2);
$successful = false;
register_shutdown_function(function () use (&$successful) { if (!$successful) { exit(1); } });
$release = paymenterTestApp();
if (!is_file($release.'/vendor/autoload.php') || is_file($release.'/.env')) { throw new RuntimeException('A pristine release with vendor and no environment file is required.'); }
$testRoot = $repo.'/.scratch/enom-domain-billing-test-'.bin2hex(random_bytes(5));
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
$graceSetting = $server->settings()->create(['key' => 'grace_renewals_enabled', 'value' => true, 'type' => 'boolean']);
$category = App\Models\Category::create(['name' => 'Domain billing test', 'slug' => 'domain-billing-test']);
$product = App\Models\Product::create(['name' => 'Managed domain', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'One year', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$plan->prices()->create(['currency_code' => 'USD', 'price' => 15, 'setup_fee' => 0]);
App\Models\Gateway::create(['name' => 'Synthetic gateway', 'type' => 'gateway', 'extension' => 'Stripe', 'enabled' => true]);
$operations = new DomainOperations;
$billing = new DomainBilling($operations);
$app->instance(DomainBilling::class, $billing);
$app->instance(DomainOperations::class, $operations);
Service::updating(fn (Service $service) => $billing->preserveNativeDate($service));
Event::listen(App\Events\Invoice\Paid::class, fn ($event) => $operations->acceptPaidInvoice($event->invoice));
$make = function (string $name, int $price = 15, string $status = 'active', array $policy = []) use ($user, $server, $product, $plan): Domain {
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
        'price' => $price, 'quantity' => 1, 'currency_code' => 'USD', 'status' => $status, 'expires_at' => null]);
    return Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id,
        'domain' => $name.'.com', 'sld' => $name, 'tld' => 'com', 'status' => 'active', 'provider_expires_at' => '2026-12-01',
        'billing_managed' => true, 'authority_generation' => 1, 'confirmed_revision' => 1,
        'renewal_policy' => array_replace(['enabled' => true], $policy)]);
};
atBilling('2026-11-09');
$domain = $make('automatic');
checkBilling($billing->calendar($domain) === ['expiry' => '2026-12-01', 'issue' => '2026-11-10', 'due' => '2026-11-24'], 'Default calendar differs from 21/7.');
checkBilling($billing->bill($domain->id) === 'not_due' && Invoice::count() === 0, 'Invoice issued before E-21.');
$credit = App\Models\Credit::create(['user_id' => $user->id, 'currency_code' => 'USD', 'amount' => 30]);
atBilling('2026-11-10');
checkBilling($billing->bill($domain->id) === 'invoiced', 'Due billing job did not create native invoice.');
$invoice = Invoice::firstOrFail();
$operation = Operation::where('invoice_id', $invoice->id)->firstOrFail();
checkBilling($invoice->due_at->format('Y-m-d') === '2026-11-24' && $invoice->status === 'pending', 'Issued due/status differs from policy.');
checkBilling((float) $credit->fresh()->amount === 30.0, 'Default domain policy consumed unapproved credits.');
checkBilling($invoice->items->first()->reference_type === null && $invoice->items->first()->reference_id === null, 'Managed renewal used linked native item.');
checkBilling($operation->operation_key === 'renew:'.$domain->id.':2026-12-01' && $operation->state === 'awaiting_payment', 'Durable obligation binding is wrong.');
checkBilling($operation->policy['original_net_minor'] === 1500 && $operation->policy['retail_minor'] === 1500, 'Original native money was not locked.');
checkBilling($operation->policy['authority_generation'] === 1 && $operation->policy['confirmed_revision'] === 1, 'Authority revision was not locked.');
checkBilling($billing->invoice($domain->id, $user, str_repeat('a', 64))->id === $invoice->id, 'Manual request duplicated automatic invoice.');
checkBilling($billing->invoice($domain->id, $user, str_repeat('b', 64))->id === $invoice->id, 'Different nonce duplicated same expiry obligation.');
refusedBilling(fn () => $billing->invoice($domain->id, $other), 'Foreign user received renewal invoice.');
$domain->update(['renewal_policy' => ['enabled' => true, 'issue_days' => 35, 'due_days' => 12]]);
checkBilling($billing->invoice($domain->id, $user)->due_at->format('Y-m-d') === '2026-11-24', 'Policy edit changed existing obligation.');
checkBilling($operation->fresh()->policy['issue_days'] === 21, 'Policy edit changed locked issue offset.');
foreach (['2026-11-24', '2026-11-27', '2026-12-01', '2026-12-09', '2026-12-20'] as $day) {
    atBilling($day);
    checkBilling(Artisan::call('app:cron-job') === 0, 'Official native cron failed.');
    checkBilling($billing->bill($domain->id) === 'invoiced' && Invoice::count() === 1, 'Repeated billing duplicated obligation.');
    checkBilling($invoice->fresh()->status === 'pending' && $domain->fresh()->service->status === 'active'
        && $domain->fresh()->service->expires_at === null, 'Native cron closed the managed grace lifecycle.');
}
ExtensionHelper::addPayment($invoice, 'Stripe', 15, transactionId: 'synthetic-grace-settlement');
checkBilling($invoice->fresh()->status === 'paid' && (float) $invoice->fresh()->remaining === 0.0, 'Native settlement did not complete.');
checkBilling($domain->fresh()->provider_expires_at->format('Y-m-d') === '2026-12-01' && $domain->fresh()->service->expires_at === null,
    'Settlement fabricated registrar expiry or a native billing date.');
// Root's execution/funding suite proves provider admission; this test executes
// no jobs and leaves provider execution deferred to the native database queue.
checkBilling($operations->funded($operation->fresh()) && $operation->fresh()->state === 'queued', 'Native payment did not authorize the bound managed obligation.');
checkBilling(billingJobCount(Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation::class) === 1, 'Native payment queued wrong number of registrar executions.');

// A second real obligation uses the next explicitly confirmed fixture expiry.
$operation->update(['state' => 'succeeded']);
$domain->update(['provider_expires_at' => '2027-12-01', 'confirmed_revision' => 2]);
atBilling('2027-10-27');
checkBilling($billing->bill($domain->id) === 'invoiced' && Invoice::count() === 2, 'Second configured cycle was not issued.');
checkBilling(Invoice::orderByDesc('id')->first()->due_at->format('Y-m-d') === '2027-11-19', 'Second cycle did not adopt new future timing.');

atBilling('2026-11-10');
$creditDomain = $make('approved-credit', policy: ['credits_auto_use' => true]);
$creditInvoice = $billing->invoice($creditDomain->id, $user);
checkBilling($creditInvoice->status === 'paid' && (float) $credit->fresh()->amount === 15.0, 'Explicit credit-use policy did not settle through native transaction.');
checkBilling($creditInvoice->transactions->count() === 1 && $creditInvoice->transactions->first()->is_credit_transaction,
    'Credit use fabricated a gateway transaction.');

foreach (['suspended', 'cancelled', 'pending'] as $status) {
    $bad = $make('reject-'.$status, status: $status);
    refusedBilling(fn () => $billing->invoice($bad->id, $user), 'Billing accepted '.$status.' native service.');
}
$bad = $make('foreign-binding');
$bad->service->update(['user_id' => $other->id]);
refusedBilling(fn () => $billing->invoice($bad->id, $user), 'Billing accepted foreign service binding.');
$bad = $make('unsupported-term');
$bad->update(['renewal_policy' => ['enabled' => true, 'term' => 2]]);
refusedBilling(fn () => $billing->invoice($bad->id, $user), 'Billing silently shortened unsupported multiyear term.');
$bad = $make('invalid-offsets', policy: ['issue_days' => 7, 'due_days' => 7]);
refusedBilling(fn () => $billing->invoice($bad->id, $user), 'Billing accepted equal issue and due offsets.');
$bad = $make('uncertain-peer');
Operation::create(['domain_id' => $bad->id, 'service_id' => $bad->service_id, 'operation_key' => 'uncertain-peer',
    'action' => 'renew', 'state' => 'uncertain', 'amount' => 15, 'currency' => 'USD']);
refusedBilling(fn () => $billing->invoice($bad->id, $user), 'Billing created an obligation beside an uncertain registrar operation.');

$free = $make('free-unapproved', 0);
refusedBilling(fn () => $billing->invoice($free->id, $user), 'Free domain created a funded zero invoice.');
refusedBilling(fn () => $billing->sponsor($free->id), 'Free domain received implicit standing sponsorship.');
$sponsorship = ['enabled' => true, 'standing' => true, 'approved_by' => $staff->id, 'reason' => 'Isolated sponsorship test',
    'limit_minor' => 1200, 'currency' => 'USD', 'term' => 1];
$free->update(['sponsorship' => $sponsorship]);
checkBilling($billing->sponsor($free->id) === null, 'Sponsorship spent without complete fresh quote evidence.');
$quote = ['state' => 'validated', 'complete' => true, 'domain' => $free->domain, 'action' => 'renew', 'term' => 1,
    'base_expires_at' => '2026-12-01', 'amount' => '10.00', 'currency' => 'USD', 'grace_eligible' => true,
    'observed_at' => now()->toIso8601String(), 'source' => 'isolated-fixture'];
$free->update(['observation' => ['renewal_quote' => $quote]]);
$beforeInvoices = Invoice::count();
$sponsored = $billing->sponsor($free->id);
checkBilling($sponsored?->funding_basis === 'sponsored' && $sponsored->state === 'queued' && $sponsored->invoice_id === null,
    'Approved fixture sponsorship failed admission.');
checkBilling(Invoice::count() === $beforeInvoices && $sponsored->policy['sponsorship'] === $sponsorship,
    'Sponsorship fabricated invoice/payment or lost approval evidence.');
checkBilling($operations->funded($sponsored), 'Specific admin funding permission did not authorize sponsorship.');
checkBilling($billing->sponsor($free->id)->id === $sponsored->id, 'Sponsorship rerun created another obligation.');
$overCap = $make('free-overcap', 0);
$overCap->update(['sponsorship' => $sponsorship, 'observation' => ['renewal_quote' => array_replace($quote,
    ['domain' => $overCap->domain, 'amount' => '12.01'])]]);
checkBilling($billing->sponsor($overCap->id) === null, 'Sponsorship admitted a quote over its exact approved cap.');
$missingTime = $make('free-missing-time', 0);
$missingQuote = array_replace($quote, ['domain' => $missingTime->domain]);
unset($missingQuote['observed_at']);
$missingTime->update(['sponsorship' => $sponsorship, 'observation' => ['renewal_quote' => $missingQuote]]);
checkBilling($billing->sponsor($missingTime->id) === null, 'Sponsorship treated a missing observation time as fresh.');

// Native linked initial registration can advance its date during payment. The
// supported Eloquent Updating hook removes that date before the native SQL.
$initial = $make('initial-linked', status: 'pending');
$initialInvoice = new Invoice(['user_id' => $user->id, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => now()]);
$initialInvoice->send_create_email = false; $initialInvoice->save();
$initialInvoice->items()->create(['reference_type' => Service::class, 'reference_id' => $initial->service_id,
    'price' => 15, 'quantity' => 1, 'description' => 'Synthetic initial order']);
ExtensionHelper::addPayment($initialInvoice, 'Stripe', 15, transactionId: 'initial-linked-proof');
checkBilling($initial->fresh()->service->expires_at === null && $initial->fresh()->service->status === 'active',
    'Updating hook did not persist null after native initial payment.');
$ordinary = $make('unmanaged'); $ordinary->update(['billing_managed' => false]);
$ordinary->service->update(['expires_at' => '2030-01-01']);
checkBilling($ordinary->fresh()->service->expires_at->format('Y-m-d') === '2030-01-01', 'Managed date hook changed an unmarked service.');

// End-period cancellation changes only explicit native cancellation lifecycle.
// No invoice or uncertain registrar history is overwritten by a scheduler.
atBilling('2026-11-10');
$cancel = $make('cancel-at-expiry');
$cancelInvoice = $billing->invoice($cancel->id, $user);
$cancelOperation = Operation::where('invoice_id', $cancelInvoice->id)->firstOrFail();
App\Models\ServiceCancellation::create(['service_id' => $cancel->service_id, 'type' => 'end_of_period', 'reason' => 'Test cancellation']);
checkBilling($billing->bill($cancel->id) === 'cancellation_pending', 'End-period cancellation completed before confirmed expiry.');
atBilling('2026-12-01');
checkBilling($billing->bill($cancel->id) === 'cancelled' && $cancel->fresh()->service->status === 'cancelled', 'Confirmed end-period cancellation did not complete.');
checkBilling($cancelInvoice->fresh()->status === Invoice::STATUS_CANCELLED
    && $cancelOperation->fresh()->state === 'rejected' && $cancelInvoice->transactions()->count() === 0,
    'Native cancellation left a clean unpaid renewal invoice payable.');
$uncertainCancel = $make('cancel-uncertain');
Operation::create(['domain_id' => $uncertainCancel->id, 'service_id' => $uncertainCancel->service_id,
    'operation_key' => 'cancel-uncertain', 'action' => 'renew', 'state' => 'uncertain', 'amount' => 15, 'currency' => 'USD']);
App\Models\ServiceCancellation::create(['service_id' => $uncertainCancel->service_id, 'type' => 'end_of_period', 'reason' => 'Test cancellation']);
checkBilling($billing->bill($uncertainCancel->id) === 'cancellation_review' && $uncertainCancel->fresh()->service->status === 'active',
    'Cancellation ignored an uncertain registrar obligation.');
$driftCancel = $make('cancel-observed-drift');
$driftCancel->update(['observation' => ['expiry_drift' => 'later']]);
App\Models\ServiceCancellation::create(['service_id' => $driftCancel->service_id, 'type' => 'end_of_period', 'reason' => 'Test cancellation']);
checkBilling($billing->bill($driftCancel->id) === 'cancellation_review' && $driftCancel->fresh()->service->status === 'active',
    'Cancellation used a confirmed period contradicted by provider observation.');

// Only existing issued obligations can settle at/after expiry; no new overdue debt.
foreach (['2026-12-01', '2026-12-09'] as $day) {
    atBilling($day);
    $late = $make('late-'.str_replace('-', '', $day));
    $beforeInvoices = Invoice::count();
    refusedBilling(fn () => $billing->invoice($late->id, $user), 'New customer renewal was issued at/after confirmed expiry.');
    checkBilling($billing->bill($late->id) === 'review' && Invoice::count() === $beforeInvoices
        && $late->operations()->count() === 0, 'Late scheduled billing created an overdue payable obligation.');
}

// Unsent unrelated work cannot block annual billing; unresolved registrar work can.
atBilling('2026-11-10');
foreach ([['privacy_purchase', 'awaiting_payment'], ['hosts', 'held']] as [$action, $state]) {
    $candidate = $make('unrelated-'.$action);
    $peer = Operation::create(['domain_id' => $candidate->id, 'service_id' => $candidate->service_id,
        'operation_key' => 'unrelated-'.$action, 'action' => $action, 'state' => $state, 'amount' => 5, 'currency' => 'USD']);
    $peerBefore = $peer->fresh()->getRawOriginal();
    checkBilling($billing->bill($candidate->id) === 'invoiced' && $candidate->operations()->where('action', 'renew')->count() === 1,
        'Unrelated unpaid/held work stopped annual renewal billing.');
    checkBilling($peer->fresh()->getRawOriginal() === $peerBefore, 'Renewal issuance changed unrelated operation history.');
}
foreach ([['hosts', 'running'], ['privacy_purchase', 'pending'], ['lock', 'uncertain'],
    ['renew', 'awaiting_payment'], ['renew', 'queued'], ['renew', 'held']] as [$action, $state]) {
    $candidate = $make('blocking-'.$action.'-'.$state);
    Operation::create(['domain_id' => $candidate->id, 'service_id' => $candidate->service_id,
        'operation_key' => 'blocking-'.$action.'-'.$state, 'action' => $action, 'state' => $state, 'amount' => 15, 'currency' => 'USD']);
    $beforeInvoices = Invoice::count();
    refusedBilling(fn () => $billing->invoice($candidate->id, $user), 'Billing bypassed unresolved registrar work or another renewal obligation.');
    checkBilling(Invoice::count() === $beforeInvoices, 'Blocked renewal issuance added a customer debt.');
}

// Leap-day obligations use the registry's next non-leap anniversary.
atBilling('2028-02-08');
$leap = $make('leap-day');
$leap->update(['provider_expires_at' => '2028-02-29']);
$leapInvoice = $billing->invoice($leap->id, $user);
$leapOperation = Operation::where('invoice_id', $leapInvoice->id)->firstOrFail();
checkBilling($leapOperation->expected_expires_at->format('Y-m-d') === '2029-02-28'
    && $leapOperation->base_expires_at->format('Y-m-d') === '2028-02-29', 'Leap-day paid renewal overflowed into March.');
$leapFree = $make('leap-day-sponsored', 0);
$leapFree->update(['provider_expires_at' => '2028-02-29', 'sponsorship' => $sponsorship,
    'observation' => ['renewal_quote' => array_replace($quote, ['domain' => $leapFree->domain,
        'base_expires_at' => '2028-02-29', 'observed_at' => now()->toIso8601String(), 'grace_eligible' => false])]]);
checkBilling($billing->sponsor($leapFree->id)->expected_expires_at->format('Y-m-d') === '2029-02-28',
    'Leap-day sponsored renewal overflowed into March.');

// Native inclusive tax supplies a rounded net obligation, frozen against later tariff/tax changes.
atBilling('2026-11-10');
config(['settings.tax_enabled' => true, 'settings.tax_type' => 'inclusive']);
$tax = App\Models\TaxRate::create(['name' => 'Synthetic inclusive tax', 'country' => 'all', 'rate' => '7.25']);
Illuminate\Support\Once::flush();
$taxed = $make('tax-inclusive');
$taxed->service->update(['price' => '19.99']);
$taxInvoice = $billing->invoice($taxed->id, $user);
$taxOperation = Operation::where('invoice_id', $taxInvoice->id)->firstOrFail();
checkBilling($taxOperation->policy['original_net_minor'] === 1864 && $taxOperation->policy['retail_minor'] === 1999
    && (float) $taxInvoice->total === 19.99, 'Inclusive tax did not freeze native rounded net and gross invoice amounts.');
$taxed->service->update(['price' => '30.00']);
$tax->update(['rate' => '20.00']);
Illuminate\Support\Once::flush();
checkBilling($billing->invoice($taxed->id, $user)->id === $taxInvoice->id
    && $taxOperation->fresh()->policy['original_net_minor'] === 1864 && (float) $taxInvoice->fresh()->total === 19.99,
    'Later tax or tariff changes rewrote the original issued invoice/net budget.');
config(['settings.tax_enabled' => false]);
Illuminate\Support\Once::flush();

// A zero-price domain with no actionable standing approval needs no quote reads.
$quoteReads = 0;
$quoteGuards = 0;
$observer = new Paymenter\Extensions\Servers\Enom\Services\DomainObservation(
    new Paymenter\Extensions\Servers\Enom\Api\EnomApi(new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('synthetic', 'synthetic',
        function () use (&$quoteReads): string { $quoteReads++; return '<invalid'; }, null, true)),
    function () use (&$quoteGuards): array { $quoteGuards++; return ['uid' => 'synthetic', 'token' => 'synthetic', 'reads_enabled' => true, 'observations_enabled' => true]; });
$app->instance(Paymenter\Extensions\Servers\Enom\Services\DomainObservation::class, $observer);
foreach ([null, array_replace($sponsorship, ['enabled' => false]), array_replace($sponsorship, ['standing' => false]),
    array_replace($sponsorship, ['limit_minor' => 0]), array_replace($sponsorship, ['approved_by' => $other->id])] as $i => $approval) {
    $unapproved = $make('free-no-actionable-approval-'.$i, 0);
    $unapproved->update(['provider_domain_id' => '456', 'sponsorship' => $approval]);
    checkBilling($billing->bill($unapproved->id) === 'sponsorship_review', 'Unapproved zero-price domain did not receive local sponsorship review.');
}
checkBilling($quoteReads === 0 && $quoteGuards === 0, 'Unapproved zero-price domains wasted registrar quote reads.');
checkBilling($billing->bill($free->id) === 'sponsored' && $quoteReads === 0 && $quoteGuards === 0,
    'Existing sponsored cycle refreshed its quote or blocked itself during scheduled billing.');

// Resuming before expiry replaces a clean retired debt once and preserves its history.
$resume = $make('resume-renewal');
$retiredInvoice = $billing->invoice($resume->id, $user);
$retiredOperation = Operation::where('invoice_id', $retiredInvoice->id)->firstOrFail();
$operations->toggleRenewalPreference($user, $resume->id, false);
checkBilling($retiredInvoice->fresh()->status === Invoice::STATUS_CANCELLED
    && $retiredOperation->fresh()->safe_code === 'renewal_cancelled_unpaid', 'Toggle-off left an unpaid invoice payable.');
$retiredRows = [$retiredInvoice->fresh()->getRawOriginal(), $retiredOperation->fresh()->getRawOriginal()];
$operations->toggleRenewalPreference($user, $resume->id, true);
$replacement = $billing->invoice($resume->id, $user);
$replacementOperation = Operation::where('invoice_id', $replacement->id)->firstOrFail();
checkBilling($replacement->id !== $retiredInvoice->id && $replacement->status === Invoice::STATUS_PENDING
    && $replacementOperation->operation_key === 'renew:'.$resume->id.':2026-12-01:replacement:1',
    'Explicit resume returned a cancelled debt or lost the replacement cycle identity.');
checkBilling($billing->invoice($resume->id, $user)->id === $replacement->id
    && $billing->bill($resume->id) === 'invoiced' && $resume->operations()->where('action', 'renew')->count() === 2,
    'Repeated resumed-cycle requests created more than one replacement.');
checkBilling($retiredRows === [$retiredInvoice->fresh()->getRawOriginal(), $retiredOperation->fresh()->getRawOriginal()],
    'Resume rewrote retired invoice/operation history.');
$operations->toggleRenewalPreference($user, $resume->id, false);
$secondRetiredRows = [$replacement->fresh()->getRawOriginal(), $replacementOperation->fresh()->getRawOriginal()];
$operations->toggleRenewalPreference($user, $resume->id, true);
$secondReplacement = $billing->invoice($resume->id, $user);
checkBilling($secondReplacement->id !== $replacement->id
    && Operation::where('invoice_id', $secondReplacement->id)->value('operation_key') === 'renew:'.$resume->id.':2026-12-01:replacement:2',
    'Second explicit resume collided with prior replacement history.');
checkBilling($secondRetiredRows === [$replacement->fresh()->getRawOriginal(), $replacementOperation->fresh()->getRawOriginal()],
    'Second resume rewrote the previous retired replacement.');

// Retired rows with later money or send evidence require financial disposition.
foreach (['payment', 'send_evidence'] as $kind) {
    $retired = $make('retired-review-'.$kind);
    $reviewInvoice = $billing->invoice($retired->id, $user);
    $reviewOperation = Operation::where('invoice_id', $reviewInvoice->id)->firstOrFail();
    $operations->toggleRenewalPreference($user, $retired->id, false);
    $operations->toggleRenewalPreference($user, $retired->id, true);
    if ($kind === 'payment') { ExtensionHelper::addPayment($reviewInvoice, 'Stripe', '5.00', transactionId: 'retired-later-money'); }
    else { $reviewOperation->update(['policy' => array_replace($reviewOperation->policy, ['dispatch_attempted_at' => now()->toIso8601String()])]); }
    $beforeInvoices = Invoice::count();
    refusedBilling(fn () => $billing->invoice($retired->id, $user), 'Unsafe retired row received a new payable replacement.');
    checkBilling(Invoice::count() === $beforeInvoices, 'Retired financial review created a duplicate customer debt.');
}

// Confirmed observed drift refuses new debt and retires only transaction-free debt.
foreach (['earlier', 'later'] as $drift) {
    $newDrift = $make('new-observed-'.$drift);
    $newDrift->update(['observation' => ['expiry_drift' => $drift]]);
    $beforeInvoices = Invoice::count();
    refusedBilling(fn () => $billing->invoice($newDrift->id, $user), 'Observed expiry drift allowed a new customer renewal invoice.');
    checkBilling(Invoice::count() === $beforeInvoices, 'Drift review added a customer debt.');
}
foreach (['drift', 'grace_disabled'] as $reason) {
    foreach (['0.00', '5.00', '15.00'] as $settlement) {
        atBilling('2026-11-10');
        $candidate = $make('review-'.$reason.'-'.str_replace('.', '', $settlement));
        $reviewInvoice = $billing->invoice($candidate->id, $user);
        $reviewOperation = Operation::where('invoice_id', $reviewInvoice->id)->firstOrFail();
        if ($settlement !== '0.00') {
            ExtensionHelper::addPayment($reviewInvoice, 'Stripe', $settlement, transactionId: 'review-'.$reason.'-'.$settlement);
        }
        if ($reason === 'drift') { $candidate->update(['observation' => ['expiry_drift' => 'later']]); }
        else { $graceSetting->update(['value' => false]); atBilling('2026-12-01'); }
        $before = [$reviewInvoice->fresh()->getRawOriginal(), $reviewOperation->fresh()->getRawOriginal(),
            $reviewInvoice->transactions()->orderBy('id')->get()->map->getRawOriginal()->all()];
        checkBilling($billing->bill($candidate->id) === ($reason === 'drift' ? 'review' : 'grace_review'),
            'Unavailable renewal fulfillment did not receive explicit financial review.');
        if ($settlement === '0.00') {
            checkBilling($reviewInvoice->fresh()->status === Invoice::STATUS_CANCELLED
                && $reviewOperation->fresh()->safe_code === 'renewal_cancelled_unpaid', 'Unavailable clean unpaid debt stayed payable.');
        } else {
            checkBilling($before === [$reviewInvoice->fresh()->getRawOriginal(), $reviewOperation->fresh()->getRawOriginal(),
                $reviewInvoice->transactions()->orderBy('id')->get()->map->getRawOriginal()->all()],
                'Drift or unavailable grace rewrote a paid/part-paid obligation.');
        }
        $graceSetting->update(['value' => true]);
    }
}
// Cleanup needs the locked current native/account binding before touching money.
foreach (['owner', 'server', 'currency', 'account', 'quantity', 'native_date'] as $change) {
    atBilling('2026-11-10');
    $uidSetting = $change === 'account' ? $server->settings()->create(['key' => 'uid', 'value' => 'original-synthetic-account']) : null;
    $candidate = $make('cleanup-wrong-'.$change);
    $reviewInvoice = $billing->invoice($candidate->id, $user);
    $reviewOperation = Operation::where('invoice_id', $reviewInvoice->id)->firstOrFail();
    $candidate->update(['observation' => ['expiry_drift' => 'later']]);
    $wrongServer = null;
    if ($change === 'owner') { $candidate->service->update(['user_id' => $other->id]); }
    elseif ($change === 'server') {
        $wrongServer = App\Models\Server::create(['name' => 'Foreign synthetic cleanup', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
        $product->update(['server_id' => $wrongServer->id]);
    }
    elseif ($change === 'currency') { $candidate->service->update(['currency_code' => 'EUR']); }
    elseif ($change === 'account') { $uidSetting->update(['value' => 'replacement-synthetic-account']); }
    elseif ($change === 'quantity') { $candidate->service->update(['quantity' => 2]); }
    else { DB::table('services')->where('id', $candidate->service_id)->update(['expires_at' => '2026-11-24']); }
    $before = [$reviewInvoice->fresh()->getRawOriginal(), $reviewOperation->fresh()->getRawOriginal(),
        $candidate->fresh()->service->getRawOriginal(), $reviewInvoice->items()->get()->map->getRawOriginal()->all()];
    checkBilling($billing->bill($candidate->id) === ($change === 'server' ? 'disabled' : 'review'), 'Changed '.$change.' cleanup binding did not remain contained.');
    checkBilling($before === [$reviewInvoice->fresh()->getRawOriginal(), $reviewOperation->fresh()->getRawOriginal(),
        $candidate->fresh()->service->getRawOriginal(), $reviewInvoice->items()->get()->map->getRawOriginal()->all()]
        && $reviewInvoice->fresh()->status === Invoice::STATUS_PENDING && $reviewOperation->fresh()->state === 'awaiting_payment',
        'Wrong '.$change.' cleanup binding changed invoice, operation, native lifecycle or item history.');
    if ($wrongServer) { $product->update(['server_id' => $server->id]); }
    $uidSetting?->delete();
}


// The issue window limits scheduling. Explicit manual invoices/sponsorship may be early.
atBilling('2026-10-01');$early=$make('explicit-before-issue-window');
checkBilling($billing->bill($early->id)==='not_due'&&$early->operations()->count()===0,'Scheduler ignored E-21 issue window.');
$earlyInvoice=$billing->invoice($early->id,$user);
checkBilling($earlyInvoice->due_at->format('Y-m-d')==='2026-11-24'&&$early->operations()->count()===1,
    'Explicit early manual invoice changed E-7 or incorrectly required the scheduler issue date.');
$earlyFree=$make('explicit-early-sponsorship',0);$earlyFree->update(['sponsorship'=>$sponsorship,'observation'=>['renewal_quote'=>array_replace($quote,
    ['domain'=>$earlyFree->domain,'observed_at'=>now()->toIso8601String()])]]);
$earlyFinancial=hash('sha256',serialize(array_map(fn($table)=>DB::table($table)->get()->all(),['invoices','invoice_items','invoice_transactions','credits'])));
checkBilling($billing->bill($earlyFree->id)==='not_due'&&$earlyFree->operations()->count()===0,'Scheduled sponsorship ignored issue window.');
$earlySponsored=$billing->sponsor($earlyFree->id,$staff);
checkBilling($earlySponsored?->state==='queued'&&$earlySponsored->funding_basis==='sponsored'&&$earlySponsored->invoice_id===null
    &&$earlyFinancial===hash('sha256',serialize(array_map(fn($table)=>DB::table($table)->get()->all(),['invoices','invoice_items','invoice_transactions','credits']))),
    'Explicit early staff sponsorship was blocked by calendar or fabricated financial activity.');
// 0.3: every new order is billing-managed, so the enrolment switch is gone. Saving native defaults still sends nothing:
// registrar writes stay off, the environment defaults to test and credentials are stored encrypted.
$serverConfig = collect($extension->getConfig());
checkBilling($serverConfig->firstWhere('name','managed_billing')===null && $serverConfig->firstWhere('name','operations_enabled')['default']===false
    && $serverConfig->firstWhere('name','environment')['default']==='test' && $serverConfig->firstWhere('name','uid')['encrypted']===true
    && $serverConfig->firstWhere('name','token')['encrypted']===true && $serverConfig->firstWhere('name','dns_tools')['default']===false,
    'Native server form defaults enable registrar writes, live mode, DNS tools or plaintext credentials.');
// A disabled billing lifecycle is fully inert, including health and cancellation.
$allBillingRows = function (): array {
    $rows=[];foreach(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table){
        $values=[];foreach(DB::table($table->name)->cursor() as $row)$values[]=(array)$row;$rows[$table->name]=$values;
    }return $rows;
};
foreach(['server_disabled','billing_false','billing_absent'] as $off){
    atBilling('2026-11-10');$candidate=$make('disabled-cancellation-'.$off);$offInvoice=$billing->invoice($candidate->id,$user);
    App\Models\ServiceCancellation::create(['service_id'=>$candidate->service_id,'type'=>'end_of_period','reason'=>'Synthetic switch containment']);
    $billingSetting=$server->settings()->where('key','billing_enabled')->firstOrFail();
    if($off==='server_disabled')$server->update(['enabled'=>false]);
    elseif($off==='billing_false')$billingSetting->update(['value'=>false]);else $billingSetting->delete();
    $graceSetting->update(['value'=>false]);atBilling('2026-12-01');$before=$allBillingRows();
    checkBilling($billing->bill($candidate->id)==='disabled','Disabled billing processed cancellation or invoice retirement.');
    checkBilling($billing->sweep()===['queued'=>0],'Disabled billing sweep dispatched work.');
    checkBilling($before===$allBillingRows(),'Disabled billing changed business, history, health, or queue rows.');
    $server->update(['enabled'=>true]);if($off==='billing_absent')$server->settings()->create(['key'=>'billing_enabled','value'=>true,'type'=>'boolean']);else $billingSetting->update(['value'=>true]);
    $graceSetting->update(['value'=>true]);
}
// Native cancellation remains explicitly authorized by billing, independent of registrar writes.
atBilling('2026-11-10');$candidate=$make('billing-on-registrar-off');$offInvoice=$billing->invoice($candidate->id,$user);
App\Models\ServiceCancellation::create(['service_id'=>$candidate->service_id,'type'=>'end_of_period','reason'=>'Synthetic explicit cancellation']);
$operationsSetting=$server->settings()->where('key','operations_enabled')->firstOrFail();$operationsSetting->update(['value'=>false]);
atBilling('2026-12-01');checkBilling($billing->bill($candidate->id)==='cancelled'&&$candidate->fresh()->service->status==='cancelled',
    'Explicit billing-enabled cancellation incorrectly required registrar writes.');
checkBilling($offInvoice->fresh()->status===Invoice::STATUS_CANCELLED,'Billing-enabled cancellation left clean unpaid renewal debt.');$operationsSetting->update(['value'=>true]);
// Fixed E-7 terms permit first issuance through E-8; E-7 onward needs review.
atBilling('2026-11-23');$lastDay=$make('last-supported-issue-day');$lastInvoice=$billing->invoice($lastDay->id,$user);
checkBilling($lastInvoice->due_at->format('Y-m-d')==='2026-11-24','Late supported issue changed the agreed E-7 due date.');
foreach(['2026-11-24','2026-11-25','2026-11-30'] as $day){
    atBilling($day);$candidate=$make('missed-issue-'.str_replace('-','',$day));$beforeInvoices=Invoice::count();
    $reason=null;try{$billing->invoice($candidate->id,$user);}catch(RuntimeException $error){$reason=$error->getMessage();}
    checkBilling($reason===DomainBilling::LATE_INVOICE_MESSAGE,'Late first issuance missed its precise staff review reason.');
    checkBilling($billing->bill($candidate->id)==='invoice_deadline_missed'&&Invoice::count()===$beforeInvoices&&$candidate->operations()->count()===0,
        'Missed issue window created a backdated customer obligation.');
}
atBilling('2026-11-30');$invoiceBefore=$lastInvoice->fresh()->getRawOriginal();
checkBilling($billing->invoice($lastDay->id,$user)->id===$lastInvoice->id&&$lastInvoice->fresh()->getRawOriginal()===$invoiceBefore,
    'Past-due retrieval rewrote an already issued obligation.');
atBilling('2026-11-10');$candidate=$make('missed-replacement-window');$retired=$billing->invoice($candidate->id,$user);
App\Models\ServiceCancellation::create(['service_id'=>$candidate->service_id,'type'=>'end_of_period','reason'=>'Synthetic retirement']);
$billing->bill($candidate->id);$candidate->service->cancellation()->delete();$retiredBefore=$retired->fresh()->getRawOriginal();
atBilling('2026-11-24');refusedBilling(fn()=>$billing->invoice($candidate->id,$user),'Late replacement recreated an expired due-date debt.');
checkBilling($candidate->operations()->count()===1&&$retired->fresh()->getRawOriginal()===$retiredBefore,
    'Late replacement rewrote retired history or added another operation.');
// Staff fact disposition cannot be bypassed by toggling ordinary future policy back on.
atBilling('2026-11-10');foreach([15,0] as $price){$candidate=$make('staff-disposition-'.$price,$price,policy:['staff_disposition_hold'=>true,'enabled'=>true]);
    $before=$allBillingRows();refusedBilling(fn()=>$price===0?$billing->sponsor($candidate->id,$staff):$billing->invoice($candidate->id,$user),'Staff disposition hold allowed new renewal spending.');
    checkBilling($before===$allBillingRows(),'Staff disposition refusal changed an invoice or funding history.');}
// A persistent disposition hold fences expired debt retirement and native cancellation.
foreach(['expired','drift'] as $case){
    atBilling('2026-11-10');$candidate=$make('held-lifecycle-'.$case);$heldInvoice=$billing->invoice($candidate->id,$user);
    App\Models\ServiceCancellation::create(['service_id'=>$candidate->service_id,'type'=>'end_of_period','reason'=>'Held synthetic cancellation']);
    $candidate->update(['renewal_policy'=>array_replace($candidate->renewal_policy,['staff_disposition_hold'=>true]),
        'observation'=>$case==='drift'?['expiry_drift'=>'earlier']:[]]);
    $graceSetting->update(['value'=>false]);atBilling('2026-12-01');$before=$allBillingRows();
    checkBilling($billing->bill($candidate->id)==='staff_review','Persistent hold did not stop expired/drift lifecycle.');
    checkBilling($before===$allBillingRows()&&$heldInvoice->fresh()->status===Invoice::STATUS_PENDING&&$candidate->fresh()->service->status==='active',
        'Held lifecycle retired debt, cancelled service, changed health or queue.');
    $graceSetting->update(['value'=>true]);
}
checkBilling(DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name") == $nativeSchema,
    'Domain billing changed native table definitions.');
Carbon\Carbon::setTestNow();
$successful = true;
echo 'Domain billing: '.$checks." checks passed; isolated official v1.5.9, no provider calls.\n";
