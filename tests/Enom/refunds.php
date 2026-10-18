<?php
// Refunds as account credit: a paid registration, transfer, renewal or ID Protect request that Enom definitely did
// not carry out is credited once (idempotent, retried), never for uncertain/pending/complimentary/unpaid/partly paid or
// mismatched lines; registrations and transfers cancel their service; the client is notified (a failed notification is
// tolerated); staff refunds of held never-sent requests need manage + invoice permission; and the credit waits for an
// enclosing transaction to commit. Disposable native app; injected Check only.
$enomTestLabel = 'refunds';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-cart-order.php';

use App\Enums\InvoiceTransactionStatus;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Paymenter\Extensions\Servers\Enom\Services\Refunds;
use Paymenter\Extensions\Servers\Enom\Services\StaffDomains;

Carbon\Carbon::setTestNow('2026-10-14 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
// The harness boots the extension after the app, so its named routes need the lookup Paymenter's boot order provides.
app('router')->getRoutes()->refreshNameLookups();
$taken = []; // Names Check reports as registered (and not transferable).
$requests = [];
$transport = function (string $command, array $params) use (&$taken, &$requests): string {
    $requests[] = $command;
    if ($command !== 'Check') { throw new RuntimeException('Unexpected provider command '.$command); }
    $name = strtolower($params['SLD'].'.'.$params['TLD']);
    $free = !in_array($name, $taken, true);
    return enomXml($command, '<Domains><Domain><Name>'.$name.'</Name><RRPCode>'.($free ? '210' : '211').'</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP>'
        .'<Properties><Transferable>'.(!$free ? 'true' : 'false').'</Transferable></Properties><Prices><Currency>USD</Currency><Registration>8.00</Registration><Renewal>8.00</Renewal><Transfer>8.00</Transfer></Prices></Domain></Domains>');
};
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport)),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true]);
$app->instance(DomainOperations::class, $operations);
$refunds = app(Refunds::class);
$staffDomains = new StaffDomains($operations);
$client = User::create(['email' => 'refund-client@example.invalid', 'password' => 'synthetic']);
$managerRole = App\Models\Role::create(['name' => 'Domain manager', 'permissions' => ['admin.enom.manage']]);
$invoiceRole = App\Models\Role::create(['name' => 'Invoice clerk', 'permissions' => ['admin.invoices.update']]);
$bothRole = App\Models\Role::create(['name' => 'Domain refunds', 'permissions' => ['admin.enom.manage', 'admin.invoices.update', 'admin.enom.fund']]);
$manager = User::create(['email' => 'manager@example.invalid', 'password' => 'synthetic', 'role_id' => $managerRole->id, 'first_name' => 'Manager']);
$clerk = User::create(['email' => 'clerk@example.invalid', 'password' => 'synthetic', 'role_id' => $invoiceRole->id]);
$refunder = User::create(['email' => 'refunder@example.invalid', 'password' => 'synthetic', 'role_id' => $bothRole->id, 'first_name' => 'Refund', 'last_name' => 'Staff']);
$server = App\Models\Server::create(['name' => 'Refund Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => '1', 'billing_enabled' => '1'] as $key => $value) { $server->settings()->create(['key' => $key, 'value' => $value]); }
$tld = enomTestTld(['server_id' => $server->id, 'tld' => 'com']);
$product = app(Catalogue::class)->productFor($tld, 'register');
$plan = app(Catalogue::class)->billingPlan($product);
enomCartProfile($client);
$app->instance(Paymenter\Extensions\Servers\Enom\Services\TransferOrders::class, new Paymenter\Extensions\Servers\Enom\Services\TransferOrders(new Paymenter\Extensions\Servers\Enom\Services\Rdap(fn () => throw new RuntimeException('offline'))));
$contacts = ['registrant' => ['first_name' => 'Refund', 'last_name' => 'Client', 'address1' => '1 Main Street', 'city' => 'Seattle', 'postal_code' => '98101',
    'country' => 'US', 'email_address' => 'refund.client@example.com', 'phone' => '+1.2065550100']];
function credit(User $user): string { return number_format((float) (Credit::where('user_id', $user->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', ''); }
function cents(string $amount): int { return (int) round((float) $amount * 100); }
function ledger(): array { return tableRows(['invoices', 'invoice_items', 'invoice_transactions']); }
/** Queued native notification emails (SendQueuedMailable reports the mailable class). */
function mailJobs(): int { return count(queuedJobs('App\\Mail\\Mail')); }
/** Bind through real native checkout, then vary ledger evidence for refusal checks. */
// These fixtures deliberately corrupt invoice references after checkout; suppress only core creation mail.
Invoice::creating(function (Invoice $invoice) { $invoice->send_create_email = false; });
$counter = 0;
function paidRequest(string $action, array $options = []): Operation {
    global $counter, $client, $taken;
    $counter++;
    $user = $options['user'] ?? $client;
    $name = 'direct'.$counter.'.com';
    if ($action === 'transfer') { $taken[] = $name; }
    $order = enomCartOrder($user, $name, $action === 'transfer' ? 'transfer' : 'register');
    ['service' => $service, 'domain' => $domain, 'invoice' => $invoice, 'item' => $item, 'operation' => $operation] = $order;
    $serviceLine = in_array($action, ['register', 'transfer'], true);
    $amount = $options['amount'] ?? '20.00';
    $service->update(['status' => $serviceLine ? Service::STATUS_PENDING : Service::STATUS_ACTIVE]);
    $domain->update(['status' => match ($action) { 'register' => 'registration_pending', 'transfer' => 'transfer_pending', default => 'active' }]);
    if (isset($options['invoice'])) {
        $item->update(['invoice_id' => $options['invoice']->id]);
        $invoice->delete();
        $invoice = $options['invoice'];
    }
    // Keep malformed-ledger cases explicit; they must fail the runtime's unchanged funding proof.
    DB::table('invoices')->where('id', $invoice->id)->update(['currency_code' => $options['invoice_currency'] ?? 'USD', 'status' => $options['invoice_status'] ?? Invoice::STATUS_PAID]);
    DB::table('invoice_items')->where('id', $item->id)->update([
        'reference_type' => array_key_exists('reference_type', $options) ? $options['reference_type'] : ($serviceLine ? Service::class : null),
        'reference_id' => array_key_exists('reference_id', $options) ? $options['reference_id'] : ($serviceLine ? $service->id : null),
        'quantity' => $options['quantity'] ?? 1, 'price' => $options['price'] ?? $amount]);
    foreach ($options['payments'] ?? [$amount] as $index => $paid) {
        App\Models\InvoiceTransaction::withoutEvents(fn () => $invoice->transactions()->create(['amount' => $paid, 'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => false, 'transaction_id' => 'direct-'.$counter.'-'.$index]));
    }
    $operation->update(['service_id' => $options['operation_service'] ?? $service->id, 'invoice_id' => $invoice->id,
        'operation_key' => 'direct-'.$counter, 'action' => $action, 'term' => 1, 'funding_basis' => $options['funding'] ?? 'paid_invoice',
        'state' => $options['state'] ?? 'rejected', 'amount' => $amount, 'currency' => 'USD', 'payload' => [], 'cost' => $options['cost'] ?? null,
        'policy' => array_replace($operation->policy, ['retail_minor' => cents($amount)], $options['policy'] ?? []),
        'completed_at' => $options['completed_at'] ?? now(), 'started_at' => $options['started_at'] ?? null]);
    return $operation->fresh();
}

// ---------------------------------------------------------------- rejected initial lines
$rejected = paidRequest('register', ['amount' => '12.00']);
Domain::whereKey($rejected->domain_id)->update(['status' => 'registration_rejected']);
$invoice = Invoice::find($rejected->invoice_id);
$ledger = ledger(); $mail = mailJobs(); $notes = Notification::where('user_id', $client->id)->count();
$refunds->creditRejected($rejected);
$refund = $rejected->fresh()->cost['refund'] ?? null;
verify(credit($client) === '12.00' && ($refund['amount'] ?? null) === '12.00' && ($refund['currency'] ?? null) === 'USD' && ($refund['to'] ?? null) === 'account_credit',
    'Refused paid registration was not credited exactly.');
verify(ledger() == $ledger && $invoice->fresh()->status === Invoice::STATUS_PAID, 'Refund changed paid invoice history.');
verify(Service::find($rejected->service_id)->status === 'cancelled', 'Refused registration kept a billable service.');
verify(Notification::where('user_id', $client->id)->count() === $notes + 1 && mailJobs() === $mail + (config('settings.mail_disable') ? 0 : 1), 'Refund notification was not sent once.');
verify($refunds->creditRejected($rejected) === null && $refunds->retryPending() === 0 && credit($client) === '12.00', 'Refund repeated.');
$page = (new Paymenter\Extensions\Servers\Enom\Services\DomainManager($operations))->snapshot($client, $rejected->domain_id);
$row = collect($page['operations'])->firstWhere('id', $rejected->id);
verify(($row['refund'] ?? null) === ['amount' => '12.00', 'currency' => 'USD', 'at' => $refund['at']], 'Page refund summary leaked ledger data.');
$transfer = paidRequest('transfer', ['amount' => '13.00']);
verify($refunds->creditRejected($transfer) === ['amount' => '13.00', 'currency' => 'USD'] && credit($client) === '25.00'
    && Service::find($transfer->service_id)->status === 'cancelled', 'Refused paid transfer was not credited.');

// ---------------------------------------------------------------- direct: renewals and ID Protect, and every refusal
$before = credit($client);
$renew = paidRequest('renew', ['amount' => '14.00']);
verify($refunds->creditRejected($renew) === ['amount' => '14.00', 'currency' => 'USD'] && Service::find($renew->service_id)->status === Service::STATUS_ACTIVE,
    'Rejected paid renewal was not credited or cancelled the active service.');
foreach (['privacy_purchase', 'privacy_renew'] as $action) {
    verify($refunds->creditRejected(paidRequest($action, ['amount' => '5.00'])) === ['amount' => '5.00', 'currency' => 'USD'], 'Rejected paid '.$action.' was not credited.');
}
// Managed renewals must use their unreferenced line.
$renewServiceLine = paidRequest('renew', ['amount' => '3.00', 'reference_type' => Service::class]);
Invoice::find($renewServiceLine->invoice_id)->items()->update(['reference_id' => $renewServiceLine->service_id]);
verify($refunds->creditRejected($renewServiceLine) === null, 'Renewal line referencing a service was credited.');
verify(cents(credit($client)) === cents($before) + 1400 + 500 + 500, 'Direct refunds did not add up.');
$before = credit($client); $ledger = ledger();
$refusals = [
    'uncertain' => ['state' => 'uncertain'], 'pending' => ['state' => 'pending'], 'held' => ['state' => 'held'], 'succeeded' => ['state' => 'succeeded'],
    'complimentary' => ['funding' => 'complimentary', 'payments' => []], 'unexpected funding' => ['funding' => 'unexpected'], 'unpaid' => ['invoice_status' => Invoice::STATUS_PENDING, 'payments' => []],
    'partly paid' => ['payments' => ['5.00']], 'quantity 2' => ['quantity' => 2, 'price' => '10.00', 'payments' => ['20.00']],
    'currency mismatch' => ['invoice_currency' => 'EUR'], 'line price differs' => ['price' => '25.00', 'payments' => ['25.00']],
    'refunded payment' => ['payments' => ['20.00', '-20.00']], 'foreign line' => ['reference_id' => 999999],
    'domain moved service' => ['operation_service' => 999999],
];
foreach ($refusals as $case => $options) {
    $candidate = paidRequest($case === 'foreign line' ? 'register' : 'renew', $options);
    verify($refunds->creditRejected($candidate) === null && !isset($candidate->fresh()->cost['refund']), 'Refund issued for a '.$case.' request.');
}
$unrelated = paidRequest('nameservers');
verify($refunds->creditRejected($unrelated) === null, 'A management request was refunded.');
verify(credit($client) === $before, 'Refused refunds changed the credit.');
$lineToOther = paidRequest('register', ['reference_type' => null, 'reference_id' => null]);
verify($refunds->creditRejected($lineToOther) === null && Service::find($lineToOther->service_id)->status === Service::STATUS_PENDING, 'Registration refunded from an unreferenced line or its service cancelled.');
$detached = paidRequest('register');
Domain::whereKey($detached->domain_id)->update(['service_id' => 999998]);
verify($refunds->creditRejected($detached) === null, 'Detached domain service authorized a refund.');
$foreignOwner = paidRequest('transfer');
Service::whereKey($foreignOwner->service_id)->update(['user_id' => $manager->id]);
verify($refunds->creditRejected($foreignOwner) === null, 'Foreign service owner authorized a refund.');
$foreignCurrency = paidRequest('register');
Service::whereKey($foreignCurrency->service_id)->update(['currency_code' => 'EUR']);
verify($refunds->creditRejected($foreignCurrency) === null, 'Foreign service currency authorized a refund.');

// Zero-value requests never create an account-credit refund.
$zero = paidRequest('register', ['amount' => '0.00', 'payments' => []]);
verify($refunds->creditRejected($zero) === null, 'A zero-value request was credited.');

// ---------------------------------------------------------------- a failing notification never undoes the credit
NotificationTemplate::where('key', 'enom_domain_order_refunded')->update(['in_app_title' => '{{ $missing->property }}']);
$before = credit($client);
$noisy = paidRequest('renew', ['amount' => '7.00']);
verify($refunds->creditRejected($noisy) === ['amount' => '7.00', 'currency' => 'USD'] && cents(credit($client)) === cents($before) + 700 && isset($noisy->fresh()->cost['refund']),
    'A failed notification undid or hid the credit.');
NotificationTemplate::where('key', 'enom_domain_order_refunded')->update(['enabled' => false]);
$quiet = paidRequest('renew', ['amount' => '1.00']);
$notes = Notification::count();
verify($refunds->creditRejected($quiet) !== null && Notification::count() === $notes, 'A disabled template still notified or blocked the credit.');
NotificationTemplate::where('key', 'enom_domain_order_refunded')->update(['enabled' => true, 'in_app_title' => '{{ $amount }} {{ $currency }} added to your credit']);

// ---------------------------------------------------------------- retryPending: recent unrefunded rejections only
$missed = paidRequest('renew', ['amount' => '2.00']);
$old = paidRequest('renew', ['amount' => '4.00', 'completed_at' => now()->subDays(31)]);
$before = credit($client);
verify($refunds->retryPending() === 1 && isset($missed->fresh()->cost['refund']) && !isset($old->fresh()->cost['refund']) && cents(credit($client)) === cents($before) + 200,
    'Retry did not credit exactly the recent missed refund.');
verify($refunds->retryPending() === 0, 'Retry repeated a refund.');

// ---------------------------------------------------------------- transaction rollback and commit
$deferred = paidRequest('register', ['amount' => '12.00']);
$before = credit($client);
DB::beginTransaction();
DB::afterCommit(fn () => $refunds->creditRejected($deferred));
verify(credit($client) === $before, 'Credit ran before the enclosing commit.');
DB::commit();
verify(cents(credit($client)) === cents($before) + 1200, 'Deferred refund did not run after commit.');
$rolled = paidRequest('register', ['amount' => '12.00']);
$before = credit($client);
DB::beginTransaction();
$refunds->creditRejected($rolled);
DB::rollBack();
verify(credit($client) === $before && !isset($rolled->fresh()->cost['refund']), 'Rolled-back refund retained credit or marker.');
// Exclude this deliberately rolled-back rejection from the missed-credit scan below.
$rolled->update(['state' => 'held']);

// ---------------------------------------------------------------- staff refunds of held, never-sent requests
$held = fn (array $options = []) => paidRequest('register', $options + ['state' => 'held', 'completed_at' => null]);
$candidate = $held();
verify($refunds->canRefundHeld($candidate) && $staffDomains->canRefundHeld($candidate), 'Held, never-started paid request is not refundable.');
foreach (['dispatch attempted' => ['policy' => ['dispatch_attempted_at' => now()->toIso8601String()]], 'null seal' => ['policy' => ['dispatch_attempted_at' => null]], 'null execution' => ['cost' => ['execution' => null]],
    'unpaid' => ['invoice_status' => Invoice::STATUS_PENDING, 'payments' => []], 'complimentary' => ['funding' => 'complimentary'], 'not held' => ['state' => 'queued']] as $case => $options) {
    verify(!$refunds->canRefundHeld($held($options)), 'Held request refundable when '.$case.'.');
}
$withOrder = $held(); $withOrder->update(['provider_order_id' => '123']);
$withExecution = $held(); $withExecution->update(['cost' => ['execution' => ['command' => 'Purchase']]]);
verify(!$refunds->canRefundHeld($withOrder->fresh()) && !$refunds->canRefundHeld($withExecution->fresh()), 'Held request with provider evidence was refundable.');
// Claimed by the worker but never sent: refundable even though it started.
verify($refunds->canRefundHeld($held(['policy' => [], 'started_at' => now()])), 'Claimed-but-unsent managed request was not refundable.');
// Invalid paid evidence must leave the held request open and leave the ledger unchanged.
foreach ([['payments' => []], ['invoice_currency' => 'EUR'], ['price' => '25.00', 'payments' => ['25.00']]] as $invalidPayment) {
    $ineligible = $held($invalidPayment);
    $beforeRefund = tableRows(['enom_domains', 'enom_operations', 'services', 'credits', 'invoices', 'invoice_items', 'invoice_transactions']);
    refuses(fn () => $refunds->refundHeld($refunder, $ineligible->id, 'Customer asked to cancel'), '', 'Ineligible held request was refunded.');
    verify(tableRows(['enom_domains', 'enom_operations', 'services', 'credits', 'invoices', 'invoice_items', 'invoice_transactions']) == $beforeRefund,
        'Ineligible refund closed the held request or changed its ledger.');
}
// Permissions: admin.enom.manage and admin.invoices.update together.
$before = tableRows(['enom_operations', 'credits']);
refuses(fn () => $refunds->refundHeld($manager, $candidate->id, 'Customer asked to cancel'), 'permission', 'Manage-only staff refunded.', [AuthorizationException::class]);
refuses(fn () => $refunds->refundHeld($clerk, $candidate->id, 'Customer asked to cancel'), 'permission', 'Invoice-only staff refunded.', [AuthorizationException::class]);
refuses(fn () => $staffDomains->refundHeld($clerk, $candidate->id, 'Customer asked to cancel'), 'permission', 'StaffDomains let invoice-only staff refund.', [AuthorizationException::class]);
refuses(fn () => $refunds->refundHeld($client, $candidate->id, 'Customer asked to cancel'), 'permission', 'A client refunded.', [AuthorizationException::class]);
refuses(fn () => $refunds->refundHeld($refunder, $candidate->id, 'no'), 'short reason', 'A two-letter reason was accepted.', [InputValidationException::class]);
refuses(fn () => $refunds->refundHeld($refunder, $withOrder->id, 'Customer asked to cancel'), 'never sent', 'A request with a provider order was refunded by staff.');
verify(tableRows(['enom_operations', 'credits']) == $before, 'Refused staff refunds wrote state.');
$before = credit($client);
$staffRefund = $staffDomains->refundHeld($refunder, $candidate->id, "  Customer   asked\nto cancel ");
$closed = $candidate->fresh();
verify($staffRefund === ['amount' => '20.00', 'currency' => 'USD'] && cents(credit($client)) === cents($before) + 2000, 'Staff refund did not credit the paid amount.');
verify($closed->state === 'rejected' && $closed->safe_code === 'refunded_by_staff' && $closed->funding_reason === 'Refunded by Refund Staff: Customer asked to cancel'
    && Domain::find($closed->domain_id)->status === 'registration_rejected' && Service::find($closed->service_id)->status === 'cancelled', 'Staff-refunded request was not closed.');
refuses(fn () => $staffDomains->refundHeld($refunder, $candidate->id, 'Second attempt here'), 'never sent', 'A request was refunded twice.');
verify(cents(credit($client)) === cents($before) + 2000, 'Second staff refund changed the credit.');

// ---------------------------------------------------------------- mixed invoices and discounted line refunds
$before = credit($client);
$mixed = paidRequest('transfer', ['amount' => '9.00', 'payments' => ['40.00', '-9.00'], 'state' => 'awaiting_code',
    'policy' => ['paid_at' => '2026-09-14T12:00:00+00:00']]);
$shared = Invoice::find($mixed->invoice_id);
$second = paidRequest('transfer', ['invoice' => $shared, 'amount' => '12.00', 'payments' => [], 'state' => 'awaiting_code',
    'policy' => ['paid_at' => '2026-10-14T12:00:00+00:00']]);
$hostingProduct = App\Models\Product::create(['name' => 'Hosting', 'category_id' => $product->category_id]);
$hostingPlan = $hostingProduct->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
$hosting = Service::create(['user_id' => $client->id, 'product_id' => $hostingProduct->id, 'plan_id' => $hostingPlan->id, 'quantity' => 1,
    'price' => '10.00', 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE]);
$hosting->properties()->create(['key' => 'domain', 'value' => Domain::find($mixed->domain_id)->domain]);
App\Models\InvoiceItem::withoutEvents(fn () => $shared->items()->create(['reference_type' => Service::class, 'reference_id' => $hosting->id,
    'quantity' => 1, 'price' => '10.00', 'description' => 'Hosting']));
$ledger = ledger();
$untouched = tableRows(['enom_domains', 'services']);
verify($refunds->rejectUnsentTransfer($mixed->id) === ['amount' => '9.00', 'currency' => 'USD'], 'Discounted line on a fully funded mixed invoice was not refunded.');
verify(ledger() == $ledger && cents(credit($client)) === cents($before) + 900, 'Mixed refund changed invoice history or credited other lines.');
verify($mixed->fresh()->payload === null && $mixed->fresh()->state === 'rejected' && $mixed->fresh()->safe_code === 'transfer_code_timeout'
    && Domain::find($mixed->domain_id)->status === 'transfer_rejected' && Service::find($mixed->service_id)->status === 'cancelled', 'Timeout did not close only the unsent transfer.');
verify(Service::find($hosting->id)->status === Service::STATUS_ACTIVE && Service::find($second->service_id)->status === Service::STATUS_PENDING
    && Domain::find($second->domain_id)->status === 'transfer_pending' && $second->fresh()->state === 'awaiting_code', 'Another domain or hosting was cancelled.');
verify($refunds->rejectUnsentTransfer($mixed->id) === null && $refunds->creditRejected($mixed->fresh()) === null, 'Mixed invoice line was credited twice.');
$overpaid = paidRequest('renew', ['amount' => '5.00', 'payments' => ['6.00']]);
verify($refunds->creditRejected($overpaid) === ['amount' => '5.00', 'currency' => 'USD'], 'Overpayment stranded a refundable line.');
$adjustedInvoice = paidRequest('renew', ['amount' => '10.00', 'payments' => ['9.00']]);
App\Models\InvoiceItem::withoutEvents(fn () => Invoice::find($adjustedInvoice->invoice_id)->items()->create(['quantity' => 1, 'price' => '-1.00', 'description' => 'Invoice adjustment']));
verify($refunds->creditRejected($adjustedInvoice) === ['amount' => '10.00', 'currency' => 'USD'], 'Funding compared payments to a line instead of the whole invoice.');
$negative = paidRequest('renew', ['amount' => '5.00', 'payments' => ['10.00', '-6.00']]);
verify($refunds->creditRejected($negative) === null, 'Successful negative adjustment was ignored.');
// A line-sized payment cannot fund one domain while the shared invoice remains short.
$partial = paidRequest('transfer', ['amount' => '9.00', 'payments' => ['9.00'], 'state' => 'awaiting_code',
    'policy' => ['paid_at' => '2026-09-14T12:00:00+00:00']]);
App\Models\InvoiceItem::withoutEvents(fn () => Invoice::find($partial->invoice_id)->items()->create(['quantity' => 1, 'price' => '10.00', 'description' => 'Hosting']));
verify($refunds->rejectUnsentTransfer($partial->id) === null && $partial->fresh()->state === 'awaiting_code', 'Partial mixed-invoice payment authorized a refund.');
// Rejection survives a failed credit write, then the existing retry completes it exactly once.
$retry = paidRequest('transfer', ['amount' => '7.00', 'state' => 'awaiting_code',
    'policy' => ['paid_at' => '2026-09-14T12:00:00+00:00']]);
$before = credit($client);
DB::unprepared("CREATE TRIGGER refuse_credit BEFORE UPDATE ON credits BEGIN SELECT RAISE(ABORT, 'synthetic credit failure'); END");
refuses(fn () => $refunds->rejectUnsentTransfer($retry->id), 'synthetic credit failure', 'Credit-write failure did not propagate.', [Illuminate\Database\QueryException::class]);
DB::unprepared('DROP TRIGGER refuse_credit');
verify($retry->fresh()->state === 'rejected' && $retry->fresh()->payload === null && !isset($retry->fresh()->cost['refund']) && credit($client) === $before, 'Failed credit lost the retry or kept a code.');
$refunds->retryPending();
verify(isset($retry->fresh()->cost['refund']) && cents(credit($client)) === cents($before) + 700 && $refunds->creditRejected($retry->fresh()) === null, 'Credit retry failed or repeated.');
// Rollback restores both sides of the code-timeout decision.
$rollback = paidRequest('transfer', ['amount' => '8.00', 'state' => 'awaiting_code',
    'policy' => ['paid_at' => '2026-09-14T12:00:00+00:00']]);
$before = tableRows(['enom_domains', 'enom_operations', 'services', 'credits']);
DB::beginTransaction();
$refunds->rejectUnsentTransfer($rollback->id);
DB::rollBack();
verify(tableRows(['enom_domains', 'enom_operations', 'services', 'credits']) == $before, 'Rolled-back timeout retained rejection or credit.');

echo 'Refunds: '.$checks.' passed; injected Check only; SQLite in memory.'.PHP_EOL;
$successful = true;
