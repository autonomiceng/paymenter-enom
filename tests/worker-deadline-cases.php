<?php
// The real worker/claim/seal path; all registrar responses are synthetic and offline.
use Paymenter\Extensions\Servers\Enom\Api\{EnomApi, RegistrarClient};
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Illuminate\Support\Facades\DB;

$workerNow = 1000.0; $claimWait = 0; $preflightWait = 0; $writes = 0; $waitingForClaim = false; $privacyReads = 0;
$api = new EnomApi(new RegistrarClient('offline', 'offline', function ($command) use (&$workerNow, &$preflightWait, &$writes, &$privacyReads): string {
    if ($command === 'GetWPPSInfo') {
        if ($privacyReads++ > 0) { return ''; } // Lost readback preserves the captured order.
        $workerNow += $preflightWait;
        return enomXml($command, '<GetWPPSInfo><WPPSAllowed>true</WPPSAllowed><WPPSExists>false</WPPSExists><WPPSEnabled>false</WPPSEnabled></GetWPPSInfo>');
    }
    verify($command === 'PurchaseServices', 'Unexpected worker-deadline exchange: '.$command);
    $writes++; $workerNow += 25;
    return enomXml($command, '<OrderID>5678</OrderID><OrderStatus>pending</OrderStatus>');
}));
$timed = new DomainOperations($api, fn () => ['uid' => 'offline', 'token' => 'offline'], function () use (&$workerNow): float { return $workerNow; });
DB::listen(static function ($query) use (&$waitingForClaim, &$workerNow, &$claimWait): void {
    if ($waitingForClaim && str_contains($query->sql, '"users"')) {
        $waitingForClaim = false; $workerNow += $claimWait;
    }
});
foreach ([[0, 244], [0, 245], [0, 246], [0, 275], [44, 200], [46, 200]] as [$claimWait, $preflightWait]) {
    [$deadlineDomain] = activeFixture();
    // A paid privacy request has one admission read and one dispatch; lost readback retains uncertainty.
    $invoice = $orders->privacy($user, $deadlineDomain->id, 'purchase', hash('sha256', 'deadline-'.$deadlineDomain->id));
    App\Helpers\ExtensionHelper::addPayment($invoice->id, null, amount: (float) $invoice->total, isCreditTransaction: true);
    $op = Paymenter\Extensions\Servers\Enom\Models\Operation::where('invoice_id', $invoice->id)->sole();
    $workerNow = 1000; $privacyReads = 0; $waitingForClaim = true; $beforeWrites = $writes; $beforeMoney = finances();
    $result = $timed->execute($op->id); $waitingForClaim = false;
    $allowed = $claimWait + $preflightWait <= 245;
    verify($writes === $beforeWrites + ($allowed ? 1 : 0), 'Worker entry/finish margin did not bound dispatch: '.$claimWait.'/'.$preflightWait);
    verify($result->state === ($allowed ? 'uncertain' : 'held'), 'Worker boundary lost the ordinary outcome: '.$claimWait.'/'.$preflightWait.' '.$result->state.' '.$result->safe_code);
    if (!$allowed) {
        verify($result->safe_code === 'dispatch_lease_insufficient' && !isset($result->policy['dispatch_attempted_at'])
            && !array_key_exists('execution', $result->cost ?? []) && $result->provider_order_id === null,
            'Deadline refusal sealed or lost recoverable unsent evidence.');
        verify(finances() == $beforeMoney, 'Deadline refusal changed native funding or queued work.');
    } else {
        verify($privacyReads === ($claimWait + $preflightWait === 245 ? 1 : 2), 'Readbacks exceeded the worker request cap.');
        verify($workerNow <= 1270 && isset($result->policy['dispatch_attempted_at']), 'Last send consumed finish headroom.');
        verify($result->provider_order_id === '5678', 'Last response was not durably recorded.');
    }
}
// All requests, including post-send reads, share the cap; the original injected API stays reusable.
$workerNow = 1000; $remainingCalls = 0;
$base = new EnomApi(new RegistrarClient('offline', 'offline', function ($command) use (&$remainingCalls): string {
    $remainingCalls++; return enomXml($command, '<Balance>100.00</Balance>');
}));
$bounded = $base->withRequestWindow(fn (): float => 0.0);
verify($bounded->balance()->code === 'request_deadline' && $remainingCalls === 0, 'Exhausted worker window reached transport.');
$base->balance(); verify($remainingCalls === 1, 'Worker window leaked into another execution.');

// An exhausted initial-order admission is an unsent hold, never a provider rejection/refund.
foreach (['register', 'transfer'] as $initialAction) {
    foreach ([[275, 0, 0], [0, 275, 0], [244, 2, 24], [244, 2, 23.9995]] as [$claimWait, $guardWait, $exchangeWait]) {
        [$initialDomain, $initial] = fixture($initialAction);
        $initialPolicy = $initial->policy; unset($initialPolicy['dispatch_attempted_at']);
        $initial->update(['state' => 'queued', 'policy' => $initialPolicy, 'provider_order_id' => null,
            'started_at' => null, 'lease_expires_at' => null, 'cost' => []]);
        $initialPayload = $initial->payload; $initialMoney = tableRows(['invoices', 'invoice_items', 'invoice_transactions', 'credits']);
        $initialCalls = 0; $workerNow = 1000; $waitingForClaim = true;
        $initialApi = new EnomApi(new RegistrarClient('offline', 'offline', function () use (&$initialCalls, &$workerNow, $exchangeWait): string {
            $initialCalls++; $workerNow += $exchangeWait; throw new RuntimeException('Synthetic admission timeout.');
        }, function () use (&$workerNow, $guardWait): void { $workerNow += $guardWait; }));
        $initialEngine = new DomainOperations($initialApi, fn () => ['uid' => 'offline', 'token' => 'offline'],
            function () use (&$workerNow): float { return $workerNow; });
        $initialResult = $initialEngine->execute($initial->id); $waitingForClaim = false;
        verify($initialResult->state === 'held' && $initialResult->safe_code === 'dispatch_lease_insufficient'
            && $initialCalls === ($exchangeWait > 0 ? 1 : 0) && !array_key_exists('dispatch_attempted_at', $initialResult->policy ?? [])
            && !array_key_exists('execution', $initialResult->cost ?? []) && $initialResult->provider_order_id === null,
            'Exhausted initial admission rejected or sealed unsent work: '.$initialAction.'/'.$claimWait.'/'.$guardWait.'/'.$exchangeWait);
        verify($initialResult->payload === $initialPayload && $initialResult->policy['paid_at'] === $initialPolicy['paid_at']
            && tableRows(array_keys($initialMoney)) === $initialMoney && $initialDomain->service->fresh()->status === 'pending',
            'Exhausted initial admission refunded, cancelled or purged recoverable input.');
    }
}
