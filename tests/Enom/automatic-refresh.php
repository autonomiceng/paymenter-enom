<?php
$enomTestLabel = 'automatic-refresh';
require dirname(__DIR__).'/enom-reconciliation-fixture.php';
require dirname(__DIR__).'/enom-domain-page.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Enom\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Jobs\RefreshDomain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\RuntimeGuard;

Carbon\Carbon::setTestNow('2026-10-13 12:00:00');
Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());

function automaticProvider(Operation $operation): array {
    $calls = new ArrayObject;
    $state = new ArrayObject(['refresh' => false, 'fail' => false]);
    $name = $operation->domain->domain;
    $orderId = $operation->provider_order_id ?? '12345';
    $api = new EnomApi(new RegistrarClient('offline', 'offline', function ($command, $params) use ($name, $orderId, $calls, $state) {
        $calls[] = $command;
        if ($state['refresh']) {
            verify(DB::transactionLevel() === 0, 'Automatic registrar read ran inside a transaction.');
            if ($state['fail']) { throw new RuntimeException('Synthetic private transport failure'); }
        }
        $body = match ($command) {
            'Check' => '<Domains><Domain><Name>'.$name.'</Name><RRPCode>210</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP></Domain></Domains>',
            'PE_GetProductPrice' => '<productprice><productenabled>True</productenabled><price>10.00</price></productprice>',
            'Purchase' => '<RRPCode>200</RRPCode><OrderID>'.$orderId.'</OrderID><DomainInfo><RegistryExpDate>2027-10-13</RegistryExpDate></DomainInfo>',
            'GetOrderDetail' => '<Order><Result>True</Result><OrderID>'.$orderId.'</OrderID><OrderBillAmount>$10.00</OrderBillAmount><OrderDetail><ProductType>Register</ProductType><AmountPaid>10.00</AmountPaid><Description>'.$name.'</Description><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><ExpiryDatetime>2027-10-13</ExpiryDatetime></OrderDetail></Order>',
            'TP_GetOrder' => '<transferorder><transferorderid>'.$orderId.'</transferorderid><statusid>3</statusid><transferorderdetail><sld>'.explode('.', $name, 2)[0].'</sld><tld>com</tld><statusid>5</statusid></transferorderdetail></transferorder>',
            'GetDomainExp' => '<ExpirationDate>2027-10-13</ExpirationDate>',
            'GetDomainInfo' => '<GetDomainInfo><domainname domainnameid="456">'.$name.'</domainname><status><expiration>2027-10-13</expiration><belongs-to>offline</belongs-to></status></GetDomainInfo>',
            'GetDNS' => '<dns>dns1.name-services.com</dns><dns>dns2.name-services.com</dns><UseDNS>default</UseDNS>',
            'GetRegLock' => '<reg-lock>1</reg-lock>',
            'GetContacts' => '<RegistrantEmailAddress>owner@example.invalid</RegistrantEmailAddress>',
            'GetWPPSInfo' => '<GetWPPSInfo><WPPSAllowed>True</WPPSAllowed><WPPSExists>False</WPPSExists><WPPSEnabled>False</WPPSEnabled></GetWPPSInfo>',
            'GetHosts' => '<DomainServices><HostRecords>True</HostRecords></DomainServices>',
            'GetDomainSRVHosts' => '<srvhosts/>',
            'GetDnsSec' => '<Success>True</Success><DnsSecDataCount>0</DnsSecDataCount><DnsSecData><Result><ResponseCode>200</ResponseCode></Result></DnsSecData>',
            'GetRenew' => '<auto-renew>0</auto-renew>',
            default => throw new RuntimeException('Unexpected synthetic command '.$command),
        };
        return enomXml($command, $body);
    }));
    return [new DomainOperations($api, fn ($serverId, $readOnly) => RuntimeGuard::assertProvider($serverId, $readOnly)), $calls, $state];
}

function refreshJob(Operation $operation): RefreshDomain {
    $jobs = array_filter(queuedJobs(RefreshDomain::class), fn ($row) =>
        unserialize(json_decode($row->payload, true)['data']['command'])->operationId === (int) $operation->id);
    verify(count($jobs) === 1, 'Confirmation did not queue exactly one operation-bound refresh.');
    $row = array_values($jobs)[0];
    verify($row->available_at <= now()->timestamp, 'Automatic refresh retained the old delay.');
    return unserialize(json_decode($row->payload, true)['data']['command']);
}

$refreshCommands = ['GetDomainInfo', 'GetContacts', 'GetDNS', 'GetRegLock', 'GetWPPSInfo', 'GetHosts', 'GetDomainSRVHosts', 'GetDnsSec', 'GetRenew'];
foreach (['register', 'transfer'] as $action) {
    [$domain, $operation] = fixture($action);
    if ($action === 'register') {
        $operation->update(['state' => 'queued', 'provider_order_id' => null,
            'policy' => array_diff_key($operation->policy, ['dispatch_attempted_at' => true])]);
    }
    [$reader, $calls, $state] = automaticProvider($operation->fresh());
    $beforeMoney = tableRows(['invoices', 'invoice_items', 'invoice_transactions', 'credits']);
    $result = $action === 'register' ? $reader->execute($operation->id) : $reader->reconcile($operation->id);
    verify($result->state === 'succeeded' && $domain->fresh()->status === 'active', 'Synthetic '.$action.' did not confirm.');
    verify(($domain->fresh()->snapshot['locked'] ?? null) === null, 'Details were fetched before queued fulfillment.');
    $job = refreshJob($result);
    $audit = $result->getAttributes();
    $calls->exchangeArray([]); $state['refresh'] = true;
    // The read-only guard permits reads even after staff turn order processing off.
    $server->settings()->where('key', 'operations_enabled')->update(['value' => false]);
    $job->handle($reader);
    verify($calls->getArrayCopy() === $refreshCommands, 'Automatic refresh differs from one manual Refresh.');
    $cached = (new DomainManager($reader))->snapshot($user, $domain->id)['cached'];
    verify($cached['locked'] === true && $cached['registrar_dns'] === true
        && $cached['nameservers'] === ['dns1.name-services.com', 'dns2.name-services.com'], 'Client facts stayed unknown.');
    $staffFacts = DomainFacts::for($domain->fresh())['snapshot'];
    verify($staffFacts['locked'] === true && $staffFacts['nameservers'] === $cached['nameservers'] && $staffFacts['registrar_dns'] === true, 'Staff facts stayed unknown.');
    $page = enomDomainPage($user, $domain->id);
    verify(str_contains($page, 'Registrar default') && str_contains($page, 'dns1.name-services.com'), 'Client page did not render stored registrar facts.');
    verify($result->fresh()->getAttributes() === $audit && tableRows(array_keys($beforeMoney)) === $beforeMoney, 'Refresh changed success, audit or billing.');
    // Duplicate delivery may repeat the read; terminal success handling does not enqueue again.
    (new RefreshDomain($domain->id, $operation->id))->handle($reader);
    $reader->execute($operation->id); $reader->reconcile($operation->id);
    refreshJob($result);
    verify($calls->getArrayCopy() === array_merge($refreshCommands, $refreshCommands)
        && $result->fresh()->getAttributes() === $audit, 'Duplicate delivery changed the operation or failed to repeat the read.');
    $calls->exchangeArray([]);
    $reader->refreshSnapshot($user, $domain->id);
    verify($calls->getArrayCopy() === $refreshCommands, 'Automatic refresh differs from manual Refresh.');
    $server->settings()->where('key', 'operations_enabled')->update(['value' => true]);
}

// Use the real database worker for provider non-success, recovered throws and exhausted throws.
$queue = $app['queue']->connection('database');
$worker = $app['queue.worker'];
foreach ([0, 1, 3] as $throws) {
    [$domain, $operation] = fixture('transfer');
    [$reader, $calls, $state] = automaticProvider($operation);
    $app->instance(DomainOperations::class, $reader);
    $reader->reconcile($operation->id);
    $audit = $operation->fresh()->getAttributes();
    $beforeMoney = tableRows(['invoices', 'invoice_items', 'invoice_transactions', 'credits']);
    $calls->exchangeArray([]); $state['refresh'] = true; $state['fail'] = $throws === 0;
    $token = $server->settings()->where('key', 'token')->value('value');
    $queue->push(refreshJob($operation), '', 'automatic-refresh-test');
    for ($attempt = 1; $attempt <= ($throws === 3 ? 3 : $throws + 1); $attempt++) {
        $server->settings()->where('key', 'token')->update(['value' => $attempt <= $throws ? '' : $token]);
        $queued = $queue->pop('automatic-refresh-test');
        verify($queued !== null && $queued->attempts() === $attempt, 'Queue did not retry the refresh.');
        try { $worker->process('database', $queued, new Illuminate\Queue\WorkerOptions); $error = null; }
        catch (Throwable $error) {}
        verify(($error !== null) === ($attempt <= $throws), 'Thrown refresh failure did not reach the worker.');
        if ($attempt <= $throws && $attempt < 3) {
            $row = DB::table('jobs')->where('queue', 'automatic-refresh-test')->first();
            $delay = [60, 300][$attempt - 1];
            verify($row && $row->available_at === now()->timestamp + $delay
                && !isset($domain->fresh()->observation['error']), 'Retry backoff or final-only failure recording changed.');
            Carbon\Carbon::setTestNow(now()->addSeconds($delay));
            Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
        }
    }
    $snapshot = $domain->fresh()->snapshot ?? [];
    verify(DB::table('jobs')->where('queue', 'automatic-refresh-test')->count() === 0, 'Completed refresh stayed queued.');
    verify($operation->fresh()->getAttributes() === $audit && tableRows(array_keys($beforeMoney)) === $beforeMoney, 'Refresh changed operation or billing.');
    if ($throws === 1) {
        verify($calls->getArrayCopy() === $refreshCommands && $snapshot['locked'] === true
            && $snapshot['registrar_dns'] === true && count($snapshot['nameservers']) === 2, 'Recovered retry did not store registrar facts.');
    } else {
        verify(($snapshot['locked'] ?? null) === null && empty($snapshot['nameservers'])
            && ($snapshot['registrar_dns'] ?? null) === null, 'Failed read invented known facts.');
        verify($throws === 0 ? $calls->getArrayCopy() === $refreshCommands && $snapshot['read_status']['lock'] === 'unknown'
            : count($calls) === 0 && $domain->fresh()->observation['error'] === 'refresh_failed'
                && $domain->fresh()->observation['status'] === 'stale', 'Provider result retried or three throws lost final observation failure.');
    }
    $server->settings()->where('key', 'token')->update(['value' => $token]);
}

// The job is published only by the outermost successful commit; a rollback discards it.
[$domain, $operation] = fixture('transfer');
[$reader, $calls, $state] = automaticProvider($operation);
$beforeJobs = count(queuedJobs(RefreshDomain::class));
DB::beginTransaction();
verify($reader->reconcile($operation->id)->state === 'succeeded', 'Nested confirmation did not succeed.');
verify(count(queuedJobs(RefreshDomain::class)) === $beforeJobs, 'Refresh was published before the outer success commit.');
DB::rollBack();
verify(count(queuedJobs(RefreshDomain::class)) === $beforeJobs && $operation->fresh()->state === 'pending', 'Rollback retained refresh work.');
DB::beginTransaction();
$reader->reconcile($operation->id);
DB::commit();
$job = refreshJob($operation);
$calls->exchangeArray([]);
$domain->update(['status' => 'expired']); $job->handle($reader);
$domain->update(['status' => 'active']); $operation->update(['state' => 'held']); $job->handle($reader);
verify(count($calls) === 0, 'Repeated job did not recheck domain and operation eligibility.');

// No refresh attempt for renewal, other actions, unconfirmed operations or a mismatched domain.
foreach (['renew', 'lock', 'register'] as $action) {
    [$domain, $operation] = fixture('register');
    $operation->update(['action' => $action, 'state' => $action === 'register' ? 'pending' : 'succeeded',
        'safe_code' => 'expiry_confirmed', 'confirmed_expires_at' => '2027-10-13']);
    $domain->update(['status' => 'active']);
    [$reader, $calls] = automaticProvider($operation->fresh());
    (new RefreshDomain($domain->id, $operation->id))->handle($reader);
    verify(count($calls) === 0, 'Ineligible operation reached automatic read.');
}
[$foreignDomain] = fixture('transfer');
(new RefreshDomain($foreignDomain->id, $operation->id))->handle($reader);
verify(count($calls) === 0, 'Mismatched operation/domain reached automatic read.');

// A domain-only job serialized before this release has no operationId property at all.
$legacy = new RefreshDomain($domain->id);
unset($legacy->operationId);
unserialize(serialize($legacy))->handle($reader);
verify($calls->getArrayCopy() === $refreshCommands, 'Previously queued domain-only refresh is incompatible.');

// A synchronous worker also runs after commit, and its failed read cannot escape into fulfillment.
[$domain, $operation] = fixture('transfer');
[$reader, $calls, $state] = automaticProvider($operation);
$app->instance(DomainOperations::class, $reader);
config(['queue.default' => 'sync']);
$app['events']->listen(Illuminate\Queue\Events\JobProcessing::class, function ($event) use ($server) {
    if ($event->job->resolveName() !== RefreshDomain::class) { return; }
    verify(DB::transactionLevel() === 0, 'Sync worker ran before commit.');
    $server->settings()->where('key', 'token')->update(['value' => '']);
});
$beforeMoney = tableRows(['invoices', 'invoice_items', 'invoice_transactions', 'credits']);
verify($reader->reconcile($operation->id)->state === 'succeeded' && $domain->fresh()->status === 'active', 'Sync refresh failure escaped into fulfillment.');
verify(($domain->fresh()->snapshot['locked'] ?? null) === null && $domain->fresh()->observation['error'] === 'refresh_failed'
    && tableRows(array_keys($beforeMoney)) === $beforeMoney, 'Sync refresh failure changed facts or billing.');

echo 'Automatic initial refresh: '.$checks.' passed; immediate retryable reads after registration/transfer; synthetic provider only.'.PHP_EOL;
$successful = true;
