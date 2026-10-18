<?php
$enomBootExtension = false;
$enomCreateCurrency = false;
require dirname(__DIR__).'/enom-native-app.php';
require_once dirname(__DIR__).'/enom-cart-order.php';
// This proof installs only extension-owned tables. Proposed listeners below exist
// only in this disposable process; no installed extension/application is booted.
use App\Helpers\ExtensionHelper;
use App\Models\Service;
use App\Models\Invoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Paymenter\Extensions\Servers\Enom\Models\Domain;

$checks = 0;
$observations = [];
function proof(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function clockAt(string $time): void { Carbon\Carbon::setTestNow($time.' 12:00:00'); }
function nativeCron(): void {
    proof(Artisan::call('app:cron-job') === 0, 'Official native cron failed.');
}
function proofInvoice(Service $service, bool $linked = true, string $due = '2026-11-24'): Invoice {
    $invoice = new Invoice(['user_id' => $service->user_id, 'status' => 'pending', 'currency_code' => 'USD', 'due_at' => $due]);
    $invoice->send_create_email = false;
    $invoice->save();
    $invoice->items()->create(['reference_type' => $linked ? Service::class : null,
        'reference_id' => $linked ? $service->id : null, 'price' => $service->price,
        'quantity' => 1, 'description' => 'Renewal obligation for proof domain; registrar expiry December 1, 2026']);
    return $invoice->fresh();
}
function creditPay(Invoice $invoice): void {
    DB::transaction(function () use ($invoice) {
        $credit = $invoice->user->credits()->where('currency_code', 'USD')->lockForUpdate()->firstOrFail();
        $amount = $invoice->fresh()->remaining;
        proof($amount > 0 && $credit->amount >= $amount, 'Insufficient fixture credit.');
        $credit->amount -= $amount;
        $credit->save();
        ExtensionHelper::addPayment($invoice, null, $amount, isCreditTransaction: true);
    });
}
function jobCount(string $class): int {
    return DB::table('jobs')->get()->filter(fn ($job) => (json_decode($job->payload, true)['displayName'] ?? null) === $class)->count();
}

// The cron's update check is disabled through its native development branch.
// All HTTP access remains forbidden, including accidental registrar traffic.
config(['app.version' => 'development']);
Illuminate\Support\Facades\Http::preventStrayRequests();
$originalNativeDdl = DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name");
$sourceHashes = [];
foreach (['app/Console/Commands/CronJob.php', 'app/Services/Invoice/ProcessPaidInvoiceService.php',
    'app/Services/Service/RenewServiceService.php', 'app/Observers/InvoiceObserver.php',
    'app/Observers/ServiceObserver.php', 'app/Listeners/CancellationCreatedListener.php'] as $source) {
    proof(hash_file('sha256', $release.'/'.$source) === hash_file('sha256', $testRoot.'/'.$source), 'Native source differs: '.$source);
    $sourceHashes[$source] = hash_file('sha256', $release.'/'.$source);
}
$user = App\Models\User::create(['email' => 'lifecycle@example.invalid', 'password' => bcrypt('synthetic')]);
App\Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$']);
$server = App\Models\Server::create(['name' => 'Isolated Enom proof', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
enomTestTld(['server_id'=>$server->id,'tld'=>'com','renew_price'=>'15.00']);
$category = App\Models\Category::create(['name' => 'Lifecycle proof', 'slug' => 'lifecycle-proof']);
$product = App\Models\Product::create(['name' => 'Isolated domain', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'One year', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$plan->prices()->create(['currency_code' => 'USD', 'price' => 15, 'setup_fee' => 0]);
$gateway = App\Models\Gateway::create(['name' => 'Synthetic gateway', 'type' => 'gateway', 'extension' => 'Stripe', 'enabled' => true]);
$create = function (string $name, string $status = 'active', bool $managed = true, int $price = 15) use ($user, $product, $plan, $server): array {
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
        'currency_code' => 'USD', 'quantity' => 1, 'price' => $price, 'status' => $status, 'expires_at' => null]);
    $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id,
        'domain' => $name.'.com', 'sld' => $name, 'tld' => 'com', 'status' => 'active',
        'provider_expires_at' => '2026-12-01', 'snapshot' => ['proof_managed_billing' => $managed]]);
    return [$service, $domain];
};

// Proposed narrow date listener: a domain/owner/server marker is mandatory.
// An exact SQL row update is required: during Updated the model's original date
// is still null, so restoring null with saveQuietly alone performs no SQL write.
Event::listen(App\Events\Service\Updated::class, function ($event) use (&$observations) {
    $service = $event->service;
    $domain = Domain::where('service_id', $service->id)->first();
    if (!$domain || !($domain->snapshot['proof_managed_billing'] ?? false)
        || (int) $domain->user_id !== (int) $service->user_id || (int) $domain->server_id !== (int) $service->product->server_id
        || $service->product->server->extension !== 'Enom') { return; }
    if ($service->expires_at !== null) {
        $observations[] = ['event' => 'native_date_written', 'service_id' => $service->id,
            'native_date' => $service->expires_at->toDateString(), 'status' => $service->status];
        DB::table('services')->where('id', $service->id)->update(['expires_at' => null]);
        $service->expires_at = null;
    }
});
Event::listen(App\Events\Invoice\Paid::class, function ($event) use (&$observations) {
    foreach ($event->invoice->items as $item) {
        $observations[] = ['event' => 'invoice_paid', 'invoice_id' => $event->invoice->id,
            'service_id' => $item->reference_id, 'native_date' => $item->reference?->expires_at?->toDateString(),
            'service_status' => $item->reference?->status];
    }
});

clockAt('2026-11-09'); // E-22.
[$late, $lateDomain] = $create('late-grace');
[$early] = $create('early-payment');
[$suspended] = $create('staff-suspended', 'suspended');
[$cancelled] = $create('staff-cancelled', 'cancelled');
[$free] = $create('zero-no-approval', price: 0);
[$unmarked] = $create('unmarked', managed: false);
nativeCron();
proof(Invoice::count() === 0, 'Native cron billed a nullable domain.');

clockAt('2026-11-10'); // E-21: isolated extension-style invoice issue.
$earlyInvoice = proofInvoice($early);
$lateInvoice = proofInvoice($late);
$suspendedInvoice = proofInvoice($suspended);
$cancelledInvoice = proofInvoice($cancelled);
proof($lateInvoice->due_at->toDateString() === '2026-11-24', 'Native independent due date is wrong.');
nativeCron();
nativeCron();
proof(Invoice::count() === 4, 'Native cron duplicated early-issued obligations.');
App\Models\Credit::create(['user_id' => $user->id, 'currency_code' => 'USD', 'amount' => 30]);
creditPay($earlyInvoice);
proof($earlyInvoice->fresh()->status === 'paid' && (float) $earlyInvoice->fresh()->remaining === 0.0, 'Native credit settlement failed.');
proof($early->fresh()->expires_at === null, 'Service Updated failed to restore null.');
proof((float) $user->credits()->first()->amount === 15.0, 'Credit debit differs from invoice remaining.');
$earlyPaidEvents = array_values(array_filter($observations, fn ($event) => $event['event'] === 'invoice_paid' && $event['invoice_id'] === $earlyInvoice->id));
proof(count($earlyPaidEvents) === 1 && $earlyPaidEvents[0]['native_date'] === null, 'Paid event observed a native advanced date.');
$dateWrites = array_values(array_filter($observations, fn ($event) => $event['event'] === 'native_date_written' && $event['service_id'] === $early->id));
proof(count($dateWrites) === 1 && $dateWrites[0]['native_date'] === '2027-11-10', 'Native null-date calculation did not start at payment day.');

foreach (['2026-11-24', '2026-11-27', '2026-12-01', '2026-12-09', '2026-12-20'] as $day) {
    clockAt($day);
    nativeCron();
    proof($late->fresh()->status === 'active' && $late->fresh()->expires_at === null, 'Native lifecycle closed grace before provider eligibility.');
    proof($lateInvoice->fresh()->status === 'pending', 'Native cron cancelled a payable grace invoice.');
    proof($free->fresh()->expires_at === null && $free->fresh()->status === 'active', 'Native zero-price path advanced the zero-price fixture.');
    proof($suspended->fresh()->status === 'suspended', 'Native cron terminated a nullable suspended service.');
}
creditPay($lateInvoice);
proof($lateInvoice->fresh()->status === 'paid' && $late->fresh()->expires_at === null, 'Late credit settlement failed.');
proof($lateDomain->fresh()->provider_expires_at->toDateString() === '2026-12-01', 'Native payment fabricated provider expiry.');

// Suspended linked payment queues a real native unsuspend job before restoration.
$beforeUnsuspend = jobCount(App\Jobs\Server\UnsuspendJob::class);
ExtensionHelper::addPayment($suspendedInvoice, 'Stripe', 15, transactionId: 'proof-suspended');
proof($suspended->fresh()->status === 'active' && $suspended->fresh()->expires_at === null, 'Native suspended settlement did not run.');
proof(jobCount(App\Jobs\Server\UnsuspendJob::class) === $beforeUnsuspend + 1, 'Native unsuspend side effect was not captured.');

// Confirm the stock unconditional reactivation defect for cancelled linked items.
ExtensionHelper::addPayment($cancelledInvoice, 'Stripe', 15, transactionId: 'proof-cancelled-linked');
proof($cancelled->fresh()->status === 'active', 'Expected native cancelled linked-item reactivation was not reproduced.');

[$pending] = $create('initial-pending', 'pending');
$pendingInvoice = proofInvoice($pending);
$beforeCreate = jobCount(App\Jobs\Server\CreateJob::class);
ExtensionHelper::addPayment($pendingInvoice, 'Stripe', 15, transactionId: 'proof-initial-pending');
proof($pending->fresh()->status === 'active' && $pending->fresh()->expires_at === null, 'Managed initial linked payment did not restore null.');
proof(jobCount(App\Jobs\Server\CreateJob::class) === $beforeCreate + 1, 'Initial linked payment did not queue native CreateJob.');

// Unreferenced native items avoid date and lifecycle mutations for every status.
foreach (['active', 'suspended', 'pending', 'cancelled'] as $status) {
    [$service] = $create('unreferenced-'.$status, $status);
    $invoice = proofInvoice($service, linked: false);
    $serviceJobs = [App\Jobs\Server\CreateJob::class, App\Jobs\Server\UnsuspendJob::class,
        App\Jobs\Server\SuspendJob::class, App\Jobs\Server\TerminateJob::class];
    $beforeJobs = array_map('jobCount', $serviceJobs);
    ExtensionHelper::addPayment($invoice, 'Stripe', 15, transactionId: 'proof-unreferenced-'.$status);
    proof($invoice->fresh()->status === 'paid', 'Unreferenced native invoice did not settle.');
    proof($service->fresh()->status === $status && $service->fresh()->expires_at === null, 'Unreferenced settlement changed native lifecycle.');
    proof(array_map('jobCount', $serviceJobs) === $beforeJobs, 'Unreferenced settlement queued unexpected service jobs.');
    proof($service->invoices()->count() === 0, 'Expected native service invoice list limitation changed.');
}

// Native immediate cancellation remains available; queued listener is bounded,
// invoked directly with its real native event and never processes fixture workers.
[$immediate] = $create('immediate-cancellation');
$immediateInvoice = proofInvoice($immediate);
$request = App\Models\ServiceCancellation::create(['service_id' => $immediate->id, 'type' => 'immediate', 'reason' => 'Isolated proof']);
(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($request));
proof($immediate->fresh()->status === 'cancelled' && $immediateInvoice->fresh()->status === 'cancelled', 'Native immediate cancellation effects failed.');

[$endPeriod] = $create('end-period-cancellation');
$request = App\Models\ServiceCancellation::create(['service_id' => $endPeriod->id, 'type' => 'end_of_period', 'reason' => 'Isolated proof']);
(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($request));
clockAt('2027-01-05');
nativeCron();
proof($endPeriod->fresh()->status === 'active' && $endPeriod->fresh()->cancellation !== null,
    'Expected nullable end-of-period cancellation limitation was not reproduced.');

// The recommended renewal contract uses unreferenced native items and durable
// extension operation links. Exercise two issue/due/grace cycles through the
// real cron and native settled transactions, without any provider simulation.
[$managed, $managedDomain] = $create('recommended-managed');
$managedDomain->update(['provider_expires_at' => '2027-02-01']);
[$managedSuspended] = $create('recommended-suspended', 'suspended');
$managedSuspended->update(['created_at' => now()]);
foreach ([2027, 2028] as $year) {
    $expiry = $year.'-02-01';
    $due = $year.'-01-25';
    clockAt($year.'-01-10');
    nativeCron();
    $beforeInvoices = Invoice::count();
    clockAt($year.'-01-11');
    $obligation = DB::transaction(function () use ($managed, $managedDomain, $due, $expiry, $year) {
        $invoice = proofInvoice($managed, linked: false, due: $due);
        Paymenter\Extensions\Servers\Enom\Models\Operation::create(['domain_id' => $managedDomain->id,
            'service_id' => $managed->id, 'invoice_id' => $invoice->id, 'invoice_item_id' => $invoice->items->first()->id,
            'operation_key' => 'proof:renewal:'.$managedDomain->id.':'.$expiry, 'action' => 'renew',
            'state' => 'awaiting_payment', 'funding_basis' => 'paid_invoice', 'currency' => 'USD', 'amount' => 15,
            'base_expires_at' => $expiry, 'expected_expires_at' => ($year + 1).'-02-01']);
        return $invoice;
    });
    foreach ([$year.'-01-11', $year.'-01-25', $year.'-01-28', $year.'-02-01', $year.'-02-09', $year.'-02-20'] as $day) {
        clockAt($day);
        nativeCron();
        proof($managed->fresh()->status === 'active' && $managed->fresh()->expires_at === null, 'Recommended lifecycle moved before provider confirmation.');
        proof($managedSuspended->fresh()->status === 'suspended' && $managedSuspended->fresh()->expires_at === null, 'Staff-suspended recommended mode was terminated.');
        proof($obligation->fresh()->status === 'pending', 'Recommended grace obligation was cancelled by native cron.');
        proof(Invoice::count() === $beforeInvoices + 1, 'Native cron duplicated a recommended renewal.');
    }
    $credit = $user->credits()->first();
    $credit->amount = 15; $credit->save();
    creditPay($obligation);
    $binding = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $obligation->id)->firstOrFail();
    proof($obligation->fresh()->status === 'paid' && (float) $obligation->fresh()->remaining === 0.0, 'Recommended credit invoice failed to settle.');
    proof($binding->service_id == $managed->id && $binding->invoice_item_id == $obligation->items->first()->id,
        'Native payment damaged explicit extension links.');
    proof($managedDomain->fresh()->provider_expires_at->toDateString() === $expiry, 'Native payment advanced provider expiry.');
    proof($managed->fresh()->expires_at === null && $managed->fresh()->status === 'active', 'Recommended paid invoice changed service lifecycle.');
    // Only an explicit isolated fixture observation advances the next cycle.
    // This is no registrar acceptance claim and creates no executable operation.
    $managedDomain->update(['provider_expires_at' => ($year + 1).'-02-01']);
}

// Native immediate cancellation cannot find an unreferenced invoice. Preserve
// this evidence: the extension must fence funding and show its disposition.
[$unreferencedCancelled] = $create('unreferenced-immediate');
$unreferencedCancelledInvoice = proofInvoice($unreferencedCancelled, linked: false);
$request = App\Models\ServiceCancellation::create(['service_id' => $unreferencedCancelled->id, 'type' => 'immediate', 'reason' => 'Isolated proof']);
(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($request));
proof($unreferencedCancelled->fresh()->status === 'cancelled' && $unreferencedCancelledInvoice->fresh()->status === 'pending',
    'Expected unreferenced cancellation-disposition limitation changed.');

// Scope fence: ordinary, unmarked service still advances under native settlement.
$unmarkedInvoice = proofInvoice($unmarked);
ExtensionHelper::addPayment($unmarkedInvoice, 'Stripe', 15, transactionId: 'proof-unmarked');
proof($unmarked->fresh()->expires_at?->toDateString() === '2029-02-20', 'Listener touched an unmarked service.');
proof(DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name") == $originalNativeDdl, 'Native schema changed.');
Carbon\Carbon::setTestNow();
$successful = true;
echo json_encode(['checks' => $checks, 'native_release' => 'v1.5.9', 'database' => 'isolated-memory-sqlite',
    'core_hashes' => $sourceHashes, 'observations' => $observations,
    'proved_limitations' => ['linked_payment_reactivates_cancelled_service', 'linked_suspended_payment_queues_unsuspend',
        'nullable_end_of_period_cancellation_needs_extension_execution', 'unreferenced_items_absent_from_native_service_invoice_relation',
        'native_cancellation_does_not_cancel_unreferenced_invoice']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
