<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Stock native in-memory fixture plus injected provider reads. No installed app or real provider access.
require __DIR__.'/reconciliation-rejection.php';
$successful = false;
Carbon\Carbon::setTestNow('2026-10-13 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());

use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;

$checks = 0;
$staffRole = App\Models\Role::create(['name' => 'Offline registrar staff', 'permissions' => ['admin.enom.manage']]);
$staff = App\Models\User::create(['email' => 'recovery-staff@example.invalid', 'password' => 'synthetic', 'role_id' => $staffRole->id]);
// 0.3: a confirmed registration/transfer queues exactly one post-registration detail refresh (read only) and nothing else.
function onlyRefreshJobAdded(array $before, array $after, int $domainId): bool {
    if (count($after) !== count($before) + 1 || array_slice($after, 0, count($before)) != $before) { return false; }
    $payload = json_decode(end($after)->payload, true);
    return ($payload['displayName'] ?? null) === 'Paymenter\\Extensions\\Servers\\Enom\\Jobs\\RefreshDomain'
        && str_contains((string) ($payload['data']['command'] ?? ''), 's:8:"domainId";i:'.$domainId.';');
}
function fundedRegistration(): array {
    global $staff;
    [$domain, $operation, $intent] = fixture('register');
    Illuminate\Database\Eloquent\Model::withoutEvents(function () use ($operation) {
        App\Models\InvoiceTransaction::where('invoice_id', $operation->invoice_id)->delete();
        App\Models\Invoice::find($operation->invoice_id)->update(['status' => 'pending']);
    });
    $operation->update(['state' => 'uncertain', 'safe_code' => 'provider_unknown', 'funding_basis' => 'operator_funded', 'authorized_by' => $staff->id, 'funding_reason' => 'Offline explicit Operator funding approval.', 'expected_expires_at' => '2027-10-13']);
    return [$domain, $operation->fresh(), $intent];
}
function registrationReader($operation, array $overrides = []): array {
    $commands = new ArrayObject;
    $data = $overrides + ['id' => $operation->provider_order_id, 'description' => $operation->domain->domain, 'quantity' => '1', 'detail_status' => 'Successful', 'detail_order_status' => 'Closed', 'expiry' => '2027-10-13', 'errcount' => '0', 'result' => 'True'];
    $client = new RegistrarClient('offline', 'offline', function ($command) use ($data, $commands): string {
        $commands[] = $command;
        if ($command === 'GetDomainExp') {
            return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><ExpirationDate>'.$data['expiry'].'</ExpirationDate></interface-response>';
        }
        if ($command !== 'GetOrderDetail') { throw new RuntimeException('Unexpected registrar mutation '.$command); }
        // Sanitized provider hierarchy observed during operation24's read-only diagnostic.
        // Numeric header state coexists with the authoritative singleton Closed/Successful detail.
        $quantity = $data['quantity'] === null ? '' : '<Quantity>'.$data['quantity'].'</Quantity>';
        $detail = '<OrderDetail><ProductType>Domain</ProductType><Description>'.$data['description'].'</Description><Status>'.$data['detail_status'].'</Status>'.$quantity.'<DetailStatusID>6</DetailStatusID><AmountPaid>15.00</AmountPaid><OrderStatus>'.$data['detail_order_status'].'</OrderStatus><ExpiryDatetime>10/13/2027 11:00:00 PM</ExpiryDatetime></OrderDetail>';
        if ($data['multiple'] ?? false) { $detail .= $detail; }
        return '<interface-response><Command>'.$command.'</Command><ErrCount>'.$data['errcount'].'</ErrCount><Done>true</Done><Order><OrderID>'.$data['id'].'</OrderID><Result>'.$data['result'].'</Result><OrderStatus>6</OrderStatus><StatusDesc>Successful</StatusDesc>'.$detail.'</Order></interface-response>';
    });
    return [new DomainOperations(new EnomApi($client), fn () => []), $commands];
}
[$domain, $operation, $intent] = fundedRegistration();
[$reader, $commands] = registrationReader($operation);
verify($reader->funded($operation), 'Unpaid operator-funded registration is not a valid recovery fixture.');
$before = finances();
$result = $reader->reconcile($operation->id);
$after = finances();
verify($result->state === 'succeeded' && $result->safe_code === 'expiry_confirmed', 'Matching completed registration did not recover.');
verify($domain->fresh()->status === 'active' && $domain->fresh()->provider_expires_at->format('Y-m-d') === '2027-10-13'
    && $domain->fresh()->service->status === 'active' && $domain->fresh()->service->expires_at->format('Y-m-d') === '2027-09-13', 'Recovery did not preserve separate provider expiry and native billing lead.');
verify($result->payload === null && $intent->fresh()->payload === null, 'Recovered registration retained private initial payload.');
foreach (['invoices', 'invoice_items', 'invoice_transactions', 'failed_jobs'] as $table) {
    verify($after[$table] == $before[$table], 'Recovery changed native '.$table.'.');
}
verify(onlyRefreshJobAdded($before['jobs'], $after['jobs'], $domain->id), 'Recovery did not queue exactly one detail refresh for the recovered domain.');
verify(App\Models\Invoice::find($operation->invoice_id)->status === 'pending' && App\Models\InvoiceTransaction::where('invoice_id', $operation->invoice_id)->count() === 0, 'Staff funding fabricated customer payment.');
$reader->execute($operation->id); $reader->reconcile($operation->id);
verify($commands->getArrayCopy() === ['GetOrderDetail', 'GetDomainExp'], 'Recovery replayed a Purchase or another registrar request.');
foreach ([['id' => '999999'], ['description' => 'foreign.com'], ['quantity' => null], ['quantity' => '2'], ['detail_status' => 'Failed'], ['detail_order_status' => 'Open'], ['multiple' => true], ['result' => 'False'], ['errcount' => '1'], ['expiry' => '2027-10-14']] as $overrides) {
    [$candidateDomain, $candidate] = fundedRegistration();
    [$candidateReader, $candidateCommands] = registrationReader($candidate, $overrides);
    $beforeRows = finances();
    verify($candidateReader->reconcile($candidate->id)->state === 'uncertain' && $candidateDomain->fresh()->status === 'registration_pending', 'Unmatched order, missing term or wrong expiry confirmed a registration.');
    verify(finances() == $beforeRows && !in_array('Purchase', $candidateCommands->getArrayCopy(), true), 'Unresolved recovery changed native state or resent Purchase.');
}
[$shortDomain, $shortTerm] = fundedRegistration();
$shortTerm->update(['term' => 2, 'expected_expires_at' => null]);
[$shortReader, $shortCalls] = registrationReader($shortTerm->fresh(), ['quantity' => '2']);
$beforeShortTerm = finances();
verify($shortReader->reconcile($shortTerm->id)->state === 'uncertain' && $shortDomain->fresh()->status === 'registration_pending', 'A short registrar expiry confirmed a two-year registration.');
verify(finances() == $beforeShortTerm && $shortCalls->getArrayCopy() === ['GetOrderDetail', 'GetDomainExp'], 'Term refusal changed billing or sent a registrar write.');
Carbon\Carbon::setTestNow(now()->addDays(30)); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
[$fullReader] = registrationReader($shortTerm->fresh(), ['quantity' => '2', 'expiry' => '2028-10-13']);
verify($fullReader->reconcile($shortTerm->id)->state === 'succeeded', 'A full two-year expiry did not confirm the registration.');
Carbon\Carbon::setTestNow('2026-10-13 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
echo 'Registration reconciliation: '.$checks.' passed; staff-funded unpaid invoice preserved; injected reads only.'.PHP_EOL;

// Actual observed Purchase/getter clocks differ. A completed bound order corroborates the getter.
$checks = 0;
function executionReader($operation, array $overrides = []): array {
    $commands = new ArrayObject;
    $data = $overrides + ['purchase_expiry' => '2027-10-13 11:41:09.000', 'expiry' => '10/13/2027 5:41:00 PM', 'order_expiry' => '10/13/2027 5:41:00 PM', 'id' => '12345', 'description' => $operation->domain->domain, 'quantity' => '1', 'status' => 'Successful', 'order_status' => 'Closed', 'result' => 'True', 'errcount' => '0'];
    $client = new RegistrarClient('offline', 'offline', function ($command, $params) use ($data, $commands): string {
        $commands[] = $command;
        $body = match ($command) {
            'Check' => '<Domains><Domain><Name>'.$params['SLD'].'.com</Name><RRPCode>210</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP><Properties><Transferable>true</Transferable></Properties></Domain></Domains>',
            'Purchase' => '<RRPCode>200</RRPCode><OrderID>12345</OrderID><DomainInfo><RegistryExpDate>'.$data['purchase_expiry'].'</RegistryExpDate></DomainInfo>'.(($data['conflicting_alias'] ?? false) ? '<RegistryExpDate>2028-10-13</RegistryExpDate>' : ''),
            'GetDomainExp' => '<ExpirationDate>'.$data['expiry'].'</ExpirationDate>',
            'GetOrderDetail' => '<Order><Result>'.$data['result'].'</Result><OrderID>'.$data['id'].'</OrderID><OrderDetail><Description>'.$data['description'].'</Description><Quantity>'.$data['quantity'].'</Quantity><Status>'.$data['status'].'</Status><OrderStatus>'.$data['order_status'].'</OrderStatus><ExpiryDatetime>'.$data['order_expiry'].'</ExpiryDatetime></OrderDetail>'.(($data['multiple'] ?? false) ? '<OrderDetail><Description>foreign.com</Description><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus></OrderDetail>' : '').'</Order>',
            default => throw new RuntimeException('Unexpected provider command '.$command),
        };
        $errors = $command === 'GetOrderDetail' ? $data['errcount'] : '0';
        return '<interface-response><Command>'.$command.'</Command><ErrCount>'.$errors.'</ErrCount><Done>true</Done>'.$body.'</interface-response>';
    });
    return [new DomainOperations(new EnomApi($client), fn () => []), $commands];
}
function queuedRegistration(): array {
    [$domain, $operation, $intent] = fundedRegistration();
    $operation->update(['state' => 'queued', 'provider_order_id' => null, 'expected_expires_at' => null, 'payload' => ['contacts' => ['registrant' => ['first_name' => 'Offline', 'last_name' => 'Customer', 'address1' => '123 Example Road', 'city' => 'Example', 'postal_code' => '12345', 'country' => 'US', 'email_address' => 'offline@example.invalid', 'phone' => '+1.5551234567']]]]);
    return [$domain, $operation->fresh(), $intent];
}
[$executedDomain, $execution, $executionIntent] = queuedRegistration();
[$executor, $executionCommands] = executionReader($execution);
$financialBefore = finances();
$completed = $executor->execute($execution->id);
verify($completed->state === 'succeeded', 'Actual Purchase/getter date discrepancy left a corroborated registration uncertain.');
verify($executedDomain->fresh()->provider_expires_at->format('Y-m-d') === '2027-10-14'
    && $executedDomain->fresh()->service->expires_at->format('Y-m-d') === '2027-09-14', 'Registration fallback chose acknowledgement expiry instead of corroborated dedicated getter.');
$financialAfter = finances();
foreach (['invoices', 'invoice_items', 'invoice_transactions', 'failed_jobs'] as $table) { verify($financialAfter[$table] == $financialBefore[$table], 'Registration fallback changed native '.$table.'.'); }
verify(onlyRefreshJobAdded($financialBefore['jobs'], $financialAfter['jobs'], $executedDomain->id), 'Registration success did not queue exactly one detail refresh.');
verify($executionCommands->getArrayCopy() === ['Check', 'Purchase', 'GetDomainExp', 'GetOrderDetail'], 'Registration fallback resent Purchase or added unexpected reads.');
$executor->execute($execution->id);
verify(count($executionCommands) === 4, 'Completed registration was resent.');
foreach ([['id' => '999999'], ['description' => 'foreign.com'], ['quantity' => ''], ['quantity' => '2'], ['status' => 'Failed'], ['order_status' => 'Open'], ['multiple' => true], ['result' => 'False'], ['errcount' => '1'], ['order_expiry' => '10/14/2027 5:41:00 PM'], ['order_expiry' => 'invalid']] as $overrides) {
    [$candidateDomain, $candidate] = queuedRegistration();
    [$candidateExecutor, $candidateCommands] = executionReader($candidate, $overrides);
    $before = finances();
    $uncertain = $candidateExecutor->execute($candidate->id);
    verify($uncertain->state === 'uncertain' && $uncertain->safe_code === 'expiry_unconfirmed' && $candidateDomain->fresh()->status === 'registration_pending' && finances() == $before, 'Uncorroborated execution advanced registration or native accounting.');
    $candidateExecutor->execute($candidate->id);
    verify($candidateCommands->getArrayCopy() === ['Check', 'Purchase', 'GetDomainExp', 'GetOrderDetail'], 'Uncertain registration fallback resent Purchase.');
}
[$matchingDomain, $matching] = queuedRegistration();
[$matchingExecutor, $matchingCommands] = executionReader($matching, ['purchase_expiry' => '2027-10-13 17:41:00.000']);
verify($matchingExecutor->execute($matching->id)->state === 'succeeded' && $matchingCommands->getArrayCopy() === ['Check', 'Purchase', 'GetDomainExp', 'GetOrderDetail'], 'Matching acknowledgement path did not use one bounded acquisition-cost read.');
foreach ([['purchase_expiry' => 'invalid'], ['conflicting_alias' => true]] as $overrides) {
    [$unknownDomain, $unknown] = queuedRegistration();
    [$unknownExecutor, $unknownCommands] = executionReader($unknown, $overrides);
    verify($unknownExecutor->execute($unknown->id)->state === 'uncertain' && $unknownCommands->getArrayCopy() === ['Check', 'Purchase'], 'Unknown/conflicting acknowledgement reached the new success fallback.');
}
echo 'Registration execution expiry fallback: '.$checks.' passed; exact completed order required; one Purchase per fixture.'.PHP_EOL;
$successful = true;
