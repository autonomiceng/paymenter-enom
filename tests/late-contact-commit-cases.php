<?php
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Enom\Services\RegistrationSetup;

// Fresh paid native fixtures: observe the customer recovery callback after an actual commit.
$contactBase = Carbon\CarbonImmutable::now();
foreach ([29, 30, 31] as $days) {
    Carbon\Carbon::setTestNow($contactBase); Carbon\CarbonImmutable::setTestNow($contactBase);
    $lateTransfer = setupOrder($owner, 'committed-contact-'.$days.'.com', $invalid + ['epp' => 'SYNTHETIC-CODE', 'registry' => []], 'transfer');
    RegistrationSetup::hold($lateTransfer); $lateTransfer->refresh();
    $originalPaid = $lateTransfer->policy['paid_at'];
    $originalDeadline = Paymenter\Extensions\Servers\Enom\Services\TransferOrders::deadline($lateTransfer)->toIso8601String();
    $originalPolicy = $lateTransfer->policy;
    $originalPayload = $lateTransfer->payload; unset($originalPayload['contacts']);
    $moneyBefore = tableRows(['invoices', 'invoice_items', 'invoice_transactions', 'credits']);
    $jobsBefore = count(queuedJobs(ExecuteOperation::class));
    $contactAt = $contactBase->addDays($days);
    Carbon\Carbon::setTestNow($contactAt); Carbon\CarbonImmutable::setTestNow($contactAt);
    DB::beginTransaction();
    try {
        RegistrationSetup::replaceContacts($owner, $lateTransfer->domain_id);
        verify(count(queuedJobs(ExecuteOperation::class)) === $jobsBefore, 'Contact recovery enqueued before commit.');
        DB::commit();
    } catch (Throwable $error) { if (DB::transactionLevel()) { DB::rollBack(); } throw $error; }
    $updated = $lateTransfer->fresh(); $late = $days >= 30;
    verify($updated->state === ($late ? 'held' : 'queued')
        && $updated->safe_code === ($late ? 'transfer_setup_review_required' : null), 'Committed contact recovery bypassed deadline staff review: '.$days);
    verify(count(queuedJobs(ExecuteOperation::class)) === $jobsBefore + ($late ? 0 : 1), 'Committed late contact recovery enqueued a transfer: '.$days);
    verify($updated->policy === $originalPolicy && $updated->policy['paid_at'] === $originalPaid
        && Paymenter\Extensions\Servers\Enom\Services\TransferOrders::deadline($updated)->toIso8601String() === $originalDeadline,
        'Committed correction changed transfer payment/deadline/funding policy.');
    $afterPayload = $updated->payload; unset($afterPayload['contacts']);
    verify($afterPayload === $originalPayload && $moneyBefore === tableRows(array_keys($moneyBefore)), 'Committed recovery changed frozen code/registry/native funds.');
    RegistrationSetup::validate($updated);
    verify($commands === [], 'Committed recovery made a provider request.');
    if ($late) {
        $operations->resumeInitial($updated->id);
        $operations->acceptPaidInvoice(Invoice::findOrFail($updated->invoice_id));
        $operations->execute($updated->id);
        verify($updated->fresh()->safe_code === 'transfer_setup_review_required'
            && count(queuedJobs(ExecuteOperation::class)) === $jobsBefore && $commands === [], 'Later payment/worker replay bypassed late staff review.');
    }
}
Carbon\Carbon::setTestNow($contactBase); Carbon\CarbonImmutable::setTestNow($contactBase);
