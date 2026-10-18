<?php
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
require dirname(__DIR__).'/enom-reconciliation-fixture.php';

[$domain, $operation] = fixture();
[$operations, $commands] = reconciler($operation);
verify($operations->funded($operation), 'Fixture must use a genuine funded native ledger.');
$before = finances();
$creditBefore = creditNow();
$result = $operations->reconcile($operation->id);
verify($result->state === 'rejected', 'Matching rejected transfer stayed pending.');
verify($domain->fresh()->status === 'transfer_rejected', 'Rejected transfer still labelled pending.');
verify($result->payload === null && $result->completed_at !== null, 'Terminal rejection retained private payload or lacked completion timestamp.');
verify($result->safe_code === 'provider_rejected:2', 'Terminal rejection lost bounded refusal code.');
verify(finances() == $before, 'Rejection changed native invoices, payments or registrar queues.');
verify(refundedOnce($result, $creditBefore), 'Definite paid transfer rejection was not credited once with its service cancelled.');
$operations->reconcile($operation->id); $operations->execute($operation->id);
app(Paymenter\Extensions\Servers\Enom\Services\Refunds::class)->retryPending();
verify(refundedOnce($result, $creditBefore), 'Replayed reconciliation or refund retry credited the client again.');
verify($commands->getArrayCopy() === ['TP_GetOrder'], 'Terminal rejection read again or resent a mutation.');
foreach ([['id' => '999999'], ['sld' => 'foreign'], ['tld' => 'net'], ['header' => '1'], ['header' => '99'], ['detail' => '5'], ['detail' => '99'], ['detail' => ''], ['id' => ''], ['multiple' => true], ['missing_detail' => true], ['header' => '1', 'errcount' => '1'], ['errcount' => '1']] as $overrides) {
    [$candidateDomain, $candidate] = fixture();
    [$reader, $reads] = reconciler($candidate, $overrides);
    $beforeRow = DB::table('enom_operations')->where('id', $candidate->id)->first();
    $reader->reconcile($candidate->id);
    verify(DB::table('enom_operations')->where('id', $candidate->id)->first() == $beforeRow && $candidateDomain->fresh()->status === 'transfer_pending', 'Unmatched, nonterminal or contradictory transfer became terminal.');
    verify($reads->getArrayCopy() === ['TP_GetOrder'], 'Adversarial rejection invoked an unexpected command.');
}
foreach (['unpaid', 'wrong_owner', 'wrong_reference', 'wrong_service', 'wrong_currency', 'wrong_order_binding'] as $fault) {
    [$candidateDomain, $candidate] = fixture();
    if ($fault === 'unpaid') { App\Models\Invoice::withoutEvents(fn () => App\Models\Invoice::find($candidate->invoice_id)->update(['status' => 'pending'])); }
    if ($fault === 'wrong_owner') { App\Models\Invoice::withoutEvents(fn () => App\Models\Invoice::find($candidate->invoice_id)->update(['user_id' => $other->id])); }
    if ($fault === 'wrong_reference') { App\Models\InvoiceItem::withoutEvents(fn () => App\Models\InvoiceItem::find($candidate->invoice_item_id)->update(['reference_id' => 999999])); }
    if ($fault === 'wrong_service') { $candidate->update(['service_id' => 999999]); }
    if ($fault === 'wrong_currency') { $candidate->update(['currency' => 'EUR']); }
    if ($fault === 'wrong_order_binding') { $candidate->update(['provider_order_id' => null]); }
    [$reader, $reads] = reconciler($candidate);
    verify($reader->reconcile($candidate->id)->state === 'pending' && count($reads) === 0, 'Invalid ledger reached provider reconciliation.');
}
// A funded ledger can change during the provider read. Recheck under native row locks.
[$raceDomain, $race] = fixture();
[$raceReader] = reconciler($race, [], fn () => Operation::whereKey($race->id)->update(['provider_order_id' => '999998']));
verify($raceReader->reconcile($race->id)->state === 'pending', 'Changed order binding became rejected from stale provider evidence.');
[$raceDomain, $race] = fixture();
[$raceReader] = reconciler($race, [], fn () => App\Models\Invoice::withoutEvents(fn () => App\Models\Invoice::find($race->invoice_id)->update(['status' => 'pending'])));
verify($raceReader->reconcile($race->id)->state === 'pending', 'Changed funding became rejected from stale provider evidence.');
[$completedDomain, $completedTransfer] = fixture();
[$completionReader, $completionReads] = reconciler($completedTransfer, ['header' => '3', 'detail' => '5']);
verify($completionReader->reconcile($completedTransfer->id)->state === 'succeeded'
    && $completedDomain->fresh()->status === 'active'
    && $completedDomain->fresh()->service->expires_at === null, 'Transfer completion did not confirm managed registrar expiry.');
verify($completionReads->getArrayCopy() === ['TP_GetOrder', 'GetDomainExp', 'GetDomainInfo'], 'Transfer completion invoked a provider mutation.');
// Immediate registration/transfer rejection uses the same terminal presentation path.
function refusalExecutor(): DomainOperations {
    $client = new RegistrarClient('offline', 'offline', function ($command, $params): string {
        // The execution preflight refuses the name before a registrar mutation is sent.
        if ($command !== 'Check') { throw new RuntimeException('Unexpected mutation during initial refusal.'); }
        return '<interface-response><Command>Check</Command><ErrCount>0</ErrCount><Done>true</Done><Domains><Domain><Name>'.$params['SLD'].'.com</Name><RRPCode>211</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Properties><Transferable>false</Transferable></Properties></Domain></Domains></interface-response>';
    });
    return new DomainOperations(new EnomApi($client), fn () => ['uid' => 'offline', 'token' => 'offline']);
}
foreach (['register' => 'registration_rejected', 'transfer' => 'transfer_rejected'] as $action => $expected) {
    [$initial, $initialOperation] = fixture($action);
    $initialOperation->update(['state' => 'queued', 'provider_order_id' => null, 'policy' => array_diff_key($initialOperation->policy, ['dispatch_attempted_at' => true]) ]);
    $before = finances();
    $creditBefore = creditNow();
    $refused = refusalExecutor()->execute($initialOperation->id);
    verify($refused->state === 'rejected' && $initial->fresh()->status === $expected && finances() == $before, 'Initial execution rejection failed presentation or changed native accounting.');
    verify(refundedOnce($refused, $creditBefore), 'Initial paid '.$action.' rejection was not credited once.');
}
[$active, $historical] = fixture('register');
$historical->update(['state' => 'queued', 'provider_order_id' => null, 'policy' => array_diff_key($historical->policy, ['dispatch_attempted_at' => true]) ]);
$active->update(['status' => 'active', 'provider_order_id' => 'confirmed-order', 'provider_expires_at' => '2027-10-13']);
refusalExecutor()->execute($historical->id);
verify($active->fresh()->status === 'active' && $active->fresh()->provider_order_id === 'confirmed-order', 'Historical rejection overwrote an active domain.');
verify($active->fresh()->service->status === 'pending' && !isset($historical->fresh()->cost['refund']), 'A request held against an active domain was refunded or cancelled its service.');
echo 'Reconciliation rejection: '.$checks.' passed; injected provider reads only; SQLite in memory.'.PHP_EOL;
$successful = true;
