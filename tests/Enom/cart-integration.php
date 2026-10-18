<?php
$enomTestLabel = 'cart-integration';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-client-fixture.php';

use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Service;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\CartOrdering;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;
use Paymenter\Extensions\Servers\Enom\Services\DomainReservations;
use Paymenter\Extensions\Servers\Enom\Services\Rdap;
use Paymenter\Extensions\Servers\Enom\Services\TransferNotifications;
use Paymenter\Extensions\Servers\Enom\Services\TransferOrders;

// Core invoice-created mail is unrelated here; the native flag avoids repeated shutdown mail in one test process.
Invoice::creating(function (Invoice $invoice) { $invoice->send_create_email = false; });
function integrationClock(string $at): void { Carbon::setTestNow($at); CarbonImmutable::setTestNow(Carbon::now()); }
function integrationPay(array $order): void { ExtensionHelper::addPayment($order['invoice']->id, null, amount: (float) $order['invoice']->fresh()->total, isCreditTransaction: true); }
function codeMail(string $domain): array {
    return EmailLog::where('subject', 'like', '%'.$domain.'%')->where(function ($query) {
        $query->where('subject', 'like', 'Enter the transfer code%')->orWhere('subject', 'like', 'Reminder: transfer code%');
    })->get()->all();
}
$user = client('integration@example.com');
$other = client('other-integration@example.com');
$transfers = new TransferOrders(new Rdap(fn () => throw new RuntimeException('offline')));
app()->instance(TransferOrders::class, $transfers);
$writes = []; $cost = '8.9500'; $response = 'pending'; $duringPrice = null;
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', function ($command, $params) use (&$transport, &$writes, &$cost, &$response, &$duringPrice) {
    if ($command === 'Check') { return $transport($command, $params); }
    if ($command === 'PE_GetProductPrice') {
        if ($duringPrice) { $duringPrice(); }
        return enomXml($command, '<productprice><productenabled>true</productenabled><price>'.$cost.'</price></productprice>');
    }
    if (in_array($command, ['Purchase', 'TP_CreateOrder'], true)) {
        $writes[] = $command;
        if ($response === 'uncertain') { return '<invalid'; }
        if ($response === 'rejected') { return '<interface-response><Command>'.$command.'</Command><ErrCount>1</ErrCount><RRPCode>541</RRPCode><errors><Err1>Refused</Err1></errors><Done>true</Done></interface-response>'; }
        return enomXml($command, $command === 'TP_CreateOrder' ? '<TransferOrderID>30000</TransferOrderID>' : '<RRPCode>1300</RRPCode><OrderID>40000</OrderID>');
    }
    if ($command === 'GetBalance') { return enomXml($command, '<Balance>1000.00</Balance><Currency>USD</Currency>'); }
    if ($command === 'GetOrderDetail') { return enomXml($command, '<RRPCode>1300</RRPCode>'); }
    throw new RuntimeException('Unexpected isolated provider command '.$command);
})), fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true, 'environment' => 'test']);
app()->instance(DomainOperations::class, $operations);

// I1: registrar rejection releases only the reservation; both attempts keep financial history.
$availability['retry-transfer.com'] = 'transferable';
$first = enomCartOrder($user, 'retry-transfer.com', 'transfer');
verify($first['item']->description === 'Transfer retry-transfer.com (adds 1 year)', 'Transfer invoice line kept product/dates wording.');
$transfers->submitCode($user, $first['domain']->id, 'synthetic-code');
integrationPay($first);
$response = 'rejected';
$rejected = $operations->execute($first['operation']->id);
verify($rejected->state === 'rejected' && isset($rejected->fresh()->cost['refund']), 'Definite rejection did not finish/refund: '.$rejected->state.' '.$rejected->safe_code);
$history = tableRows(['invoice_items', 'invoice_transactions']);
$second = enomCartOrder($user, 'retry-transfer.com', 'transfer');
verify($first['domain']->fresh()->reservation_name === null && $second['domain']->reservation_name === 'retry-transfer.com'
    && $first['domain']->fresh()->domain === $second['domain']->domain && $first['operation']->fresh()->state === 'rejected', 'Retry erased history or reused an old request.');
foreach ($history as $table => $rows) { verify(array_slice(tableRows([$table])[$table], 0, count($rows)) === $rows, 'Retry changed old invoice history.'); }
verify(DomainReservations::blocking('retry-transfer.com')->id === $second['domain']->id, 'Current reservation was not authoritative.');
verify(!(new DomainOrders($operations))->check($user, 'retry-transfer.com')['transferable'], 'Search ignored a current reservation.');
$uncertain = enomCartOrder($user, 'uncertain-retry.com');
integrationPay($uncertain); $response = 'uncertain';
verify($operations->execute($uncertain['operation']->id)->state === 'uncertain', 'Uncertain response became terminal.');
refuses(fn () => app(CartOrdering::class)->checkName($com, 'register', 'uncertain-retry.com', 1), 'already has an order', 'Uncertain attempt permitted a reorder.', [Throwable::class]);
$uncertain['domain']->fresh()->update(['status' => 'registration_rejected']);
verify(DomainReservations::blocking('uncertain-retry.com') !== null, 'A status label overrode uncertain request evidence.');
$cancelled = enomCartOrder($user, 'cancelled-retry.com');
$cancelled['invoice']->update(['status' => Invoice::STATUS_CANCELLED]);
$operations->retireCancelledInitials();
$cancelRetry = enomCartOrder($other, 'cancelled-retry.com');
verify($cancelled['domain']->fresh()->reservation_name === null && $cancelRetry['domain']->user_id === $other->id, 'Unsent cancellation prevented a new order.');
$operations->resumeInitial($cancelled['operation']->id);
verify($cancelled['operation']->fresh()->state === 'rejected', 'An old cancelled request revived after retry.');

// I2: sale flags, allowlist, holds and missing sale prices do not revoke paid fulfillment.
$response = 'pending';
$availability['paid-transfer.com'] = 'transferable';
$paidTransfer = enomCartOrder($user, 'paid-transfer.com', 'transfer');
integrationPay($paidTransfer);
verify($paidTransfer['operation']->fresh()->state === 'awaiting_code', 'Paid transfer without code did not wait.');
$com->update(['enabled' => false, 'register_enabled' => false, 'transfer_enabled' => false, 'renew_enabled' => false, 'sale_holds' => ['transfer' => 'margin']]);
serverSetting('transfer_allowlist', 'net');
app(Catalogue::class)->publish($com->fresh());
$transfers->submitCode($user, $paidTransfer['domain']->id, 'synthetic-code');
verify($paidTransfer['operation']->fresh()->state === 'queued', 'Sale controls stopped a paid transfer at resume.');
$before = count($writes);
verify($operations->execute($paidTransfer['operation']->id)->state === 'pending' && count($writes) === $before + 1 && end($writes) === 'TP_CreateOrder', 'Paid transfer did not dispatch after sale controls changed.');
$com->update(['enabled' => true, 'register_enabled' => true, 'transfer_enabled' => true, 'renew_enabled' => true, 'sale_holds' => null]);
serverSetting('transfer_allowlist', 'com,net,org'); app(Catalogue::class)->publish($com->fresh());
$paidRegistration = enomCartOrder($user, 'paid-register.com', 'register', 3);
integrationPay($paidRegistration);
$com->update(['register_enabled' => false, 'renew_enabled' => false]); app(Catalogue::class)->publish($com->fresh());
$before = count($writes); $cost = '8.0000';
verify($operations->execute($paidRegistration['operation']->id)->state === 'pending' && count($writes) === $before + 1, 'Missing sale plans held a paid multi-year registration.');
$com->update(['register_enabled' => true, 'renew_enabled' => true]); app(Catalogue::class)->publish($com->fresh());
$overCost = enomCartOrder($user, 'cost-still-blocks.com'); integrationPay($overCost); $cost = '12.0001';
$before = count($writes);
verify($operations->execute($overCost['operation']->id)->state === 'held' && count($writes) === $before, 'Paid-order continuation bypassed the exact cost cap.');
$cost = '8.9500';
$seal = enomCartOrder($user, 'seal-contacts.com'); integrationPay($seal);
$duringPrice = function () use ($seal) { $payload = $seal['operation']->fresh()->payload; $payload['contacts']['tech']['phone'] = ''; $seal['operation']->update(['payload' => $payload]); };
$before = count($writes);
verify($operations->execute($seal['operation']->id)->safe_code === 'contact_details_required' && count($writes) === $before, 'Final dispatch seal skipped the contact gate.');
$duringPrice = null;

// U1: exactly two native emails, both durable, plain and free of the code and private payload.
integrationClock('2026-10-17 12:00:00');
$availability['mail-transfer.com'] = 'transferable';
$mailOrder = enomCartOrder($user, 'mail-transfer.com', 'transfer');
verify(codeMail('mail-transfer.com') === [], 'Unpaid checkout sent a code email.');
integrationPay($mailOrder);
verify(count(codeMail('mail-transfer.com')) === 1, 'Payment did not send exactly one code email.');
$transfers->paymentAccepted($mailOrder['operation']); $operations->acceptPaidInvoice($mailOrder['invoice']->fresh()); $transfers->expireMissingCodes();
verify(count(codeMail('mail-transfer.com')) === 1, 'Payment/sweep replay repeated the code email.');
integrationClock('2026-11-09 11:59:59'); $transfers->expireMissingCodes();
verify(count(codeMail('mail-transfer.com')) === 1, 'Reminder sent before seven days remained.');
integrationClock('2026-11-09 12:00:00'); $transfers->expireMissingCodes(); $transfers->expireMissingCodes();
$mails = codeMail('mail-transfer.com');
verify(count($mails) === 2 && str_starts_with($mails[1]->subject, 'Reminder: transfer code for mail-transfer.com'), 'Reminder was missing or repeated.');
verify(isset($mailOrder['operation']->fresh()->policy['transfer_code_paid_notified_at'], $mailOrder['operation']->fresh()->policy['transfer_code_reminder_notified_at']), 'Email markers were not durable.');
foreach ($mails as $mail) {
    verify(str_contains($mail->body, '#transfer-code') && str_contains($mail->body, 'Nov 16, 2026') && !str_contains($mail->body, 'UTC') && str_contains($mail->body, '$13.00'), 'Email omitted the domain link, deadline or line credit amount.');
    verify(!preg_match('/\\b(Enom|RDAP|EPP|operation)\\b/i', str_replace(parse_url(config('app.url'), PHP_URL_HOST), '', strip_tags($mail->subject.' '.$mail->body)), $internalWord), 'Email used internal wording: '.($internalWord[1] ?? 'none'));
}
$availability['code-before.com'] = 'transferable';
$codeBefore = enomCartOrder($user, 'code-before.com', 'transfer');
$transfers->submitCode($user, $codeBefore['domain']->id, 'NEVER-IN-MAIL'); integrationPay($codeBefore);
verify(codeMail('code-before.com') === [], 'Code supplied before payment still triggered a code email.');
$availability['mail-after-code.com'] = 'transferable';
$afterCode = enomCartOrder($user, 'mail-after-code.com', 'transfer'); integrationPay($afterCode);
$transfers->submitCode($user, $afterCode['domain']->id, 'NEVER-IN-MAIL');
integrationClock('2026-12-02 12:00:00'); $transfers->expireMissingCodes();
verify(count(codeMail('mail-after-code.com')) === 1 && codeMail('code-before.com') === [], 'A saved code did not suppress reminders.');
foreach (EmailLog::all() as $mail) { verify(!str_contains($mail->body, 'NEVER-IN-MAIL'), 'Transfer code leaked to native mail.'); }
// Rollback keeps the queue, mail log and durable marker together.
$availability['mail-rollback.com'] = 'transferable';
$rollbackMail = enomCartOrder($user, 'mail-rollback.com', 'transfer');
$before = tableRows(['jobs', 'email_logs']);
DB::beginTransaction();
integrationPay($rollbackMail);
verify(isset($rollbackMail['operation']->fresh()->policy['transfer_code_paid_notified_at']), 'Payment transaction did not create the durable marker.');
DB::rollBack();
verify(tableRows(['jobs', 'email_logs']) === $before && !isset($rollbackMail['operation']->fresh()->policy['transfer_code_paid_notified_at']), 'Rollback retained a mail or marker.');
integrationPay($rollbackMail);
verify(count(codeMail('mail-rollback.com')) === 1, 'Committed payment after rollback lost its email.');
$policy = $rollbackMail['operation']->fresh()->policy; unset($policy['transfer_code_paid_notified_at']);
$rollbackMail['operation']->update(['policy' => $policy]);
NotificationTemplate::where('key', 'enom_transfer_code_paid')->update(['subject' => '{{ $missing->property }}']);
$before = tableRows(['jobs', 'email_logs']);
refuses(fn () => app(TransferNotifications::class)->sendDue($rollbackMail['operation']), '', 'Broken template was marked sent.', [Throwable::class]);
verify(tableRows(['jobs', 'email_logs']) === $before && !isset($rollbackMail['operation']->fresh()->policy['transfer_code_paid_notified_at']), 'Failed rendering retained a sent marker or queue row.');
NotificationTemplate::where('key', 'enom_transfer_code_paid')->update(['subject' => 'Enter the transfer code for {{ $domain }}']);
app(TransferNotifications::class)->sendDue($rollbackMail['operation']);
verify(isset($rollbackMail['operation']->fresh()->policy['transfer_code_paid_notified_at']), 'Notification retry failed.');
$availability['mail-disabled.com'] = 'transferable';
$disabledMail = enomCartOrder($user, 'mail-disabled.com', 'transfer');
config(['settings.mail_disable' => true]); integrationPay($disabledMail);
verify(codeMail('mail-disabled.com') === [] && !isset($disabledMail['operation']->fresh()->policy['transfer_code_paid_notified_at']), 'Disabled native mail consumed its sent marker.');
config(['settings.mail_disable' => false]);
NotificationTemplate::where('key', 'enom_transfer_code_paid')->update(['enabled' => false]);
app(TransferNotifications::class)->sendDue($disabledMail['operation']);
verify(!isset($disabledMail['operation']->fresh()->policy['transfer_code_paid_notified_at']), 'Disabled template consumed its sent marker.');
NotificationTemplate::where('key', 'enom_transfer_code_paid')->update(['enabled' => true]);
app(TransferNotifications::class)->sendDue($disabledMail['operation']);
verify(count(codeMail('mail-disabled.com')) === 1, 'Re-enabled native mail did not retry.');
echo 'Cart integration: '.$checks.' passed; I1 retries, I2 paid fulfillment, U1 durable native email; SQLite, injected registrar.'.PHP_EOL;
$successful = true;
