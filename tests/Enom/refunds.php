<?php
// 0.4 refunds as account credit: a paid registration, transfer, renewal or ID Protect request that Enom definitely did
// not carry out is credited once (idempotent, retried), never for uncertain/pending/complimentary/unpaid/partly paid or
// mismatched lines; registrations and transfers cancel their service; the client is notified (a failed notification is
// tolerated); staff refunds of held never-sent requests need manage + invoice permission; and the credit waits for an
// enclosing transaction to commit. Disposable native app; injected Check only.
$enomTestLabel = 'refunds';
require dirname(__DIR__).'/enom-native-app.php';

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
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
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;
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
        .'<Properties><Transferable>'.(str_starts_with($name, 'move') && !$free ? 'true' : 'false').'</Transferable></Properties><Prices><Currency>USD</Currency><Registration>8.00</Registration><Renewal>8.00</Renewal><Transfer>8.00</Transfer></Prices></Domain></Domains>');
};
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport)),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true]);
$app->instance(DomainOperations::class, $operations);
$orders = new DomainOrders($operations);
$refunds = app(Refunds::class);
$staffDomains = new StaffDomains($operations);
$client = User::create(['email' => 'refund-client@example.invalid', 'password' => 'synthetic']);
$freeClient = User::create(['email' => 'refund-free@example.invalid', 'password' => 'synthetic']);
$managerRole = App\Models\Role::create(['name' => 'Domain manager', 'permissions' => ['admin.enom.manage']]);
$invoiceRole = App\Models\Role::create(['name' => 'Invoice clerk', 'permissions' => ['admin.invoices.update']]);
$bothRole = App\Models\Role::create(['name' => 'Domain refunds', 'permissions' => ['admin.enom.manage', 'admin.invoices.update', 'admin.enom.fund']]);
$manager = User::create(['email' => 'manager@example.invalid', 'password' => 'synthetic', 'role_id' => $managerRole->id, 'first_name' => 'Manager']);
$clerk = User::create(['email' => 'clerk@example.invalid', 'password' => 'synthetic', 'role_id' => $invoiceRole->id]);
$refunder = User::create(['email' => 'refunder@example.invalid', 'password' => 'synthetic', 'role_id' => $bothRole->id, 'first_name' => 'Refund', 'last_name' => 'Staff']);
$server = App\Models\Server::create(['name' => 'Refund Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => '1', 'billing_enabled' => '1'] as $key => $value) { $server->settings()->create(['key' => $key, 'value' => $value]); }
Tld::create(['server_id' => $server->id, 'tld' => 'com', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '12.00', 'renew_price' => '14.00', 'transfer_price' => '13.00']);
$product = app(Catalogue::class)->ensureProduct($server->id);
$plan = $product->plans()->sole();
$contacts = ['registrant' => ['first_name' => 'Refund', 'last_name' => 'Client', 'address1' => '1 Main Street', 'city' => 'Seattle', 'postal_code' => '98101',
    'country' => 'US', 'email_address' => 'refund.client@example.com', 'phone' => '+1.2065550100']];
function credit(User $user): string { return number_format((float) (Credit::where('user_id', $user->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', ''); }
function cents(string $amount): int { return (int) round((float) $amount * 100); }
function ledger(): array { return tableRows(['invoices', 'invoice_items', 'invoice_transactions']); }
/** Queued native notification emails (SendQueuedMailable reports the mailable class). */
function mailJobs(): int { return count(queuedJobs('App\\Mail\\Mail')); }
/** A paid request built directly: $action, its invoice line and payment can be varied to test every refusal. */
$counter = 0;
function paidRequest(string $action, array $options = []): Operation {
    global $counter, $client, $server, $product, $plan;
    $counter++;
    $user = $options['user'] ?? $client;
    $service = Service::create(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '14.00', 'quantity' => 1, 'currency_code' => 'USD',
        'status' => in_array($action, ['register', 'transfer'], true) ? Service::STATUS_PENDING : Service::STATUS_ACTIVE]);
    $domain = Domain::create(['service_id' => $service->id, 'user_id' => $user->id, 'server_id' => $server->id, 'domain' => 'direct'.$counter.'.com', 'sld' => 'direct'.$counter, 'tld' => 'com',
        'status' => match ($action) { 'register' => 'registration_pending', 'transfer' => 'transfer_pending', default => 'active' }, 'capabilities' => []]);
    $amount = $options['amount'] ?? '20.00';
    $invoice = Invoice::create(['user_id' => $user->id, 'currency_code' => $options['invoice_currency'] ?? 'USD', 'status' => $options['invoice_status'] ?? Invoice::STATUS_PAID]);
    $serviceLine = in_array($action, ['register', 'transfer'], true);
    $item = $invoice->items()->create(['reference_type' => array_key_exists('reference_type', $options) ? $options['reference_type'] : ($serviceLine ? Service::class : null),
        'reference_id' => array_key_exists('reference_id', $options) ? $options['reference_id'] : ($serviceLine ? $service->id : null), 'quantity' => $options['quantity'] ?? 1, 'price' => $options['price'] ?? $amount, 'description' => 'Direct '.$action]);
    foreach ($options['payments'] ?? [$amount] as $index => $paid) {
        $invoice->transactions()->create(['amount' => $paid, 'status' => InvoiceTransactionStatus::Succeeded, 'is_credit_transaction' => false, 'transaction_id' => 'direct-'.$counter.'-'.$index]);
    }
    return Operation::create(['domain_id' => $domain->id, 'service_id' => $options['operation_service'] ?? $service->id, 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id,
        'operation_key' => 'direct-'.$counter, 'action' => $action, 'term' => 1, 'funding_basis' => $options['funding'] ?? 'paid_invoice',
        'state' => $options['state'] ?? 'rejected', 'amount' => $amount, 'currency' => 'USD', 'payload' => [], 'policy' => $options['policy'] ?? [],
        'completed_at' => $options['completed_at'] ?? now(), 'started_at' => $options['started_at'] ?? null]);
}

// ---------------------------------------------------------------- automatic: a paid registration Enom definitely refused
$invoice = $orders->place($client, $orders->quote($client, 'refused-name.com')['intent_id'], $contacts);
ExtensionHelper::addPayment($invoice->id, null, amount: 12, transactionId: 'refund-register');
$operation = Operation::where('invoice_id', $invoice->id)->sole();
$taken[] = 'refused-name.com';
$ledger = ledger(); $mail = mailJobs(); $notes = Notification::where('user_id', $client->id)->count();
$rejected = $operations->execute($operation->id);
verify($rejected->state === 'rejected' && $rejected->safe_code === 'domain_unavailable_or_premium', 'Fixture expected a definite preflight rejection.');
$refund = $rejected->fresh()->cost['refund'] ?? null;
verify(credit($client) === '12.00' && ($refund['amount'] ?? null) === '12.00' && ($refund['currency'] ?? null) === 'USD' && ($refund['to'] ?? null) === 'account_credit' && is_string($refund['at'] ?? null),
    'Refused paid registration was not credited exactly: '.credit($client).' '.json_encode($refund));
verify(ledger() == $ledger && $invoice->fresh()->status === Invoice::STATUS_PAID, 'Refund changed the paid invoice, its lines or payments.');
verify(Service::find($operation->service_id)->status === 'cancelled' && Domain::find($operation->domain_id)->status === 'registration_rejected', 'Refused registration kept a billable service.');
verify(Notification::where('user_id', $client->id)->count() === $notes + 1 && Notification::where('user_id', $client->id)->latest('id')->first()->title === '12.00 USD added to your credit'
    && mailJobs() === $mail + (config('settings.mail_disable') ? 0 : 1), 'Refund notification (forced email and in-app) was not sent once.');
// Exactly once: direct retries, the hourly retry and replays add nothing.
verify($refunds->creditRejected($rejected) === null && $refunds->retryPending() === 0 && $operations->execute($operation->id)->state === 'rejected' && credit($client) === '12.00'
    && Notification::where('user_id', $client->id)->count() === $notes + 1, 'Refund was repeated.');
// The page shows only the refund summary.
$page = (new Paymenter\Extensions\Servers\Enom\Services\DomainManager($operations))->snapshot($client, $operation->domain_id);
$row = collect($page['operations'])->firstWhere('id', $operation->id);
verify(($row['refund'] ?? null) === ['amount' => '12.00', 'currency' => 'USD', 'at' => $refund['at']] && $row['term'] === 1, 'Page refund summary differs or carries more than amount, currency and time.');

// Transfer refused at preflight: credited, service cancelled.
$taken[] = 'move-refused.com';
$transferInvoice = $orders->place($client, $orders->quote($client, 'move-refused.com', 'transfer')['intent_id'], $contacts, 'EPP-REFUND');
ExtensionHelper::addPayment($transferInvoice->id, null, amount: 13, transactionId: 'refund-transfer');
$taken = array_diff($taken, ['move-refused.com']); // Now available: no longer transferable.
$transfer = $operations->execute(Operation::where('invoice_id', $transferInvoice->id)->sole()->id);
verify($transfer->state === 'rejected' && credit($client) === '25.00' && ($transfer->fresh()->cost['refund']['amount'] ?? null) === '13.00'
    && Service::find($transfer->service_id)->status === 'cancelled', 'Refused paid transfer was not credited with its service cancelled.');

// ---------------------------------------------------------------- direct: renewals and ID Protect, and every refusal
$before = credit($client);
$renew = paidRequest('renew', ['amount' => '14.00']);
verify($refunds->creditRejected($renew) === ['amount' => '14.00', 'currency' => 'USD'] && Service::find($renew->service_id)->status === Service::STATUS_ACTIVE,
    'Rejected paid renewal was not credited or cancelled the active service.');
foreach (['privacy_purchase', 'privacy_renew'] as $action) {
    verify($refunds->creditRejected(paidRequest($action, ['amount' => '5.00'])) === ['amount' => '5.00', 'currency' => 'USD'], 'Rejected paid '.$action.' was not credited.');
}
// A renewal line may also reference its own service.
$renewServiceLine = paidRequest('renew', ['amount' => '3.00', 'reference_type' => Service::class]);
Invoice::find($renewServiceLine->invoice_id)->items()->update(['reference_id' => $renewServiceLine->service_id]);
verify($refunds->creditRejected($renewServiceLine) !== null, 'Renewal line referencing its own service was refused.');
verify(cents(credit($client)) === cents($before) + 1400 + 500 + 500 + 300, 'Direct refunds did not add up.');
$before = credit($client); $ledger = ledger();
$refusals = [
    'uncertain' => ['state' => 'uncertain'], 'pending' => ['state' => 'pending'], 'held' => ['state' => 'held'], 'succeeded' => ['state' => 'succeeded'],
    'complimentary' => ['funding' => 'complimentary', 'payments' => []], 'sponsored' => ['funding' => 'sponsored'], 'unpaid' => ['invoice_status' => Invoice::STATUS_PENDING, 'payments' => []],
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
// Complimentary orders end rejected without any credit.
$staffDomains->setClientComplimentary($refunder, $freeClient, true, 3);
$freeDomain = $orders->place($freeClient, $orders->quote($freeClient, 'free-refused.com')['intent_id'], $contacts);
$taken[] = 'free-refused.com';
$freeOp = $operations->execute(Operation::where('domain_id', $freeDomain->id)->sole()->id);
verify($freeOp->state === 'rejected' && credit($freeClient) === '0.00' && !isset($freeOp->fresh()->cost['refund']), 'Complimentary rejection was credited.');

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

// ---------------------------------------------------------------- the credit waits for an enclosing transaction
$deferredInvoice = $orders->place($client, $orders->quote($client, 'deferred-name.com')['intent_id'], $contacts);
ExtensionHelper::addPayment($deferredInvoice->id, null, amount: 12, transactionId: 'refund-deferred');
$taken[] = 'deferred-name.com';
$deferredId = Operation::where('invoice_id', $deferredInvoice->id)->sole()->id;
$before = credit($client);
DB::beginTransaction();
$inside = $operations->execute($deferredId);
$insideCredit = credit($client);
DB::commit();
verify($inside->state === 'rejected' && $insideCredit === $before && cents(credit($client)) === cents($before) + 1200, 'Refund ran inside the enclosing transaction or not after it.');
$rolledInvoice = $orders->place($client, $orders->quote($client, 'rolled-back.com')['intent_id'], $contacts);
ExtensionHelper::addPayment($rolledInvoice->id, null, amount: 12, transactionId: 'refund-rolled');
$taken[] = 'rolled-back.com';
$rolledId = Operation::where('invoice_id', $rolledInvoice->id)->sole()->id;
$before = credit($client);
DB::beginTransaction();
$operations->execute($rolledId);
DB::rollBack();
verify(credit($client) === $before && Operation::find($rolledId)->state === 'queued', 'A rolled-back rejection still credited the client.');

// ---------------------------------------------------------------- staff refunds of held, never-sent requests
$held = fn (array $options = []) => paidRequest('register', $options + ['state' => 'held', 'completed_at' => null]);
$candidate = $held();
verify($refunds->canRefundHeld($candidate) && $staffDomains->canRefundHeld($candidate), 'Held, never-started paid request is not refundable.');
foreach (['dispatch attempted' => ['policy' => ['dispatch_attempted_at' => now()->toIso8601String()]], 'started without marker' => ['started_at' => now()],
    'unpaid' => ['invoice_status' => Invoice::STATUS_PENDING, 'payments' => []], 'complimentary' => ['funding' => 'complimentary'], 'not held' => ['state' => 'queued']] as $case => $options) {
    verify(!$refunds->canRefundHeld($held($options)), 'Held request refundable when '.$case.'.');
}
$withOrder = $held(); $withOrder->update(['provider_order_id' => '123']);
$withExecution = $held(); $withExecution->update(['cost' => ['execution' => ['command' => 'Purchase']]]);
verify(!$refunds->canRefundHeld($withOrder->fresh()) && !$refunds->canRefundHeld($withExecution->fresh()), 'Held request with provider evidence was refundable.');
// Claimed by the managed worker (dispatch marker) but never sent: refundable even though it started.
verify($refunds->canRefundHeld($held(['policy' => ['dispatch_marker_version' => 1], 'started_at' => now()])), 'Claimed-but-unsent managed request was not refundable.');
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

echo 'Refunds: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; injected Check only; SQLite in memory.'.PHP_EOL;
$successful = true;
