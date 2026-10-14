<?php
require_once dirname(__DIR__).'/public-addresses.php';
// 0.4 child nameservers (glue): input validation (host under the domain, public IPv4/IPv6 by CIDR), add/update/delete
// execution with CheckNSStatus readback, the pre-dispatch read of the old address, reconciliation of uncertain changes,
// the refresh merge and the public host kept after completion. Disposable native app; injected transport only.
// Write responses use sanitized TEST fixtures; successful CheckNSStatus responses are synthetic.
$enomTestLabel = 'glue';
require dirname(__DIR__).'/enom-native-app.php';

use App\Models\Service;
use App\Models\User;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Paymenter\Extensions\Servers\Enom\Services\StaffDomains;

Carbon\Carbon::setTestNow('2026-10-14 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
$fixtures = dirname(__DIR__).'/fixtures/enom-test-api/';
// The registry's hosts: name => address. $writeReply forces a write answer; $statusFails makes CheckNSStatus unknown for hosts.
$registry = [];
$requests = [];
$writeReply = null;
$applyWrites = true;
$statusFails = [];
$duringStatus = null;
$transport = function (string $command, array $params) use (&$registry, &$requests, &$writeReply, &$applyWrites, &$statusFails, &$duringStatus, $fixtures): string {
    $requests[] = [$command, $params];
    if ($command === 'CheckNSStatus') {
        $host = $params['CheckNSName'];
        if ($duringStatus) { ($duringStatus)($host); }
        if (in_array($host, $statusFails, true)) { return '<interface-response><broken'; }
        if (!isset($registry[$host])) { return file_get_contents($fixtures.'enom-checknsstatus-missing.xml'); }
        return enomXml($command, '<CheckNsStatus><NsName>'.$host.'</NsName><IpAddress>'.$registry[$host].'</IpAddress></CheckNsStatus><NsCheckSuccess>1</NsCheckSuccess><RRPCode>200</RRPCode>');
    }
    $file = ['RegisterNameServer' => 'enom-registernameserver.xml', 'UpdateNameServer' => 'enom-updatenameserver.xml', 'DeleteNameServer' => 'enom-deletenameserver.xml'][$command] ?? null;
    if ($file) {
        if ($writeReply !== null) { return $writeReply; }
        if ($applyWrites) {
            if ($command === 'RegisterNameServer') { $registry[$params['NSName']] = $params['IP']; }
            if ($command === 'UpdateNameServer') { $registry[$params['NS']] = $params['NewIP']; }
            if ($command === 'DeleteNameServer') { unset($registry[$params['NS']]); }
        }
        return file_get_contents($fixtures.$file);
    }
    // Refresh reads: an empty but well-formed answer for each section.
    return enomXml($command, '<RRPCode>200</RRPCode>');
};
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport)),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true]);
$app->instance(DomainOperations::class, $operations);
$manager = new DomainManager($operations);
$staffDomains = new StaffDomains($operations);
$owner = User::create(['email' => 'glue-owner@example.invalid', 'password' => 'synthetic']);
$stranger = User::create(['email' => 'glue-stranger@example.invalid', 'password' => 'synthetic']);
$staffRole = App\Models\Role::create(['name' => 'Domain staff', 'permissions' => ['admin.enom.manage']]);
$staff = User::create(['email' => 'glue-staff@example.invalid', 'password' => 'synthetic', 'role_id' => $staffRole->id]);
$server = App\Models\Server::create(['name' => 'Glue Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => '1'] as $key => $value) { $server->settings()->create(['key' => $key, 'value' => $value]); }
$category = App\Models\Category::create(['name' => 'Domains', 'slug' => 'domains']);
$product = App\Models\Product::create(['name' => 'Domain name', 'category_id' => $category->id, 'server_id' => $server->id, 'hidden' => true]);
$plan = $product->plans()->create(['name' => 'Yearly', 'type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1]);
$service = Service::create(['user_id' => $owner->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => '15.00', 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE]);
$domain = Domain::create(['service_id' => $service->id, 'user_id' => $owner->id, 'server_id' => $server->id, 'domain' => 'glue-fixture.com', 'sld' => 'glue-fixture', 'tld' => 'com',
    'status' => 'active', 'provider_order_id' => '7001', 'provider_expires_at' => '2027-10-14', 'snapshot' => [], 'capabilities' => ['glue' => true]]);
$seq = 0;
function key64(): string { global $seq; return hash('sha256', 'glue-'.(++$seq)); }
function glue(string $action, array $payload): Operation {
    global $operations, $owner, $domain;
    return $operations->enqueueManagement($owner, $domain->id, $action, $payload, key64());
}
function glueRows(): array { global $domain; return $domain->fresh()->snapshot['glue'] ?? []; }

// ---------------------------------------------------------------- public addresses by CIDR
foreach ([enomPublicIpv4(1), enomPublicIpv4(2), enomPublicIpv4(3), long2ip(ip2long('100.64.0.0') - 1), long2ip(ip2long('100.127.255.255') + 1), long2ip(ip2long('172.16.0.0') - 1), long2ip(ip2long('172.31.255.255') + 1), long2ip(ip2long('198.18.0.0') - 1), long2ip(ip2long('198.19.255.255') + 1), long2ip(ip2long('192.0.2.0') - 1),
    long2ip(ip2long('224.0.0.0') - 1), enomPublicIpv6(1), enomPublicIpv6(2), implode(':', ['2001', '200', '', '1']), enomPublicIpv6(3)] as $public) {
    verify(DomainManager::glueIpError($public) === null, 'Public address '.$public.' was refused.');
}
foreach (['0.1.2.3', '10.0.0.1', '100.64.0.1', '100.127.255.255', '127.0.0.1', '169.254.1.1', '172.16.0.1', '172.31.255.255', '192.0.0.8', '192.0.2.1', '192.168.1.1',
    '198.18.0.1', '198.19.255.255', '198.51.100.7', '203.0.113.5', '224.0.0.1', '240.0.0.1', '255.255.255.255',
    '::', '::1', '::ffff:'.enomPublicIpv4(2), '::ffff:10.0.0.1', '64:ff9b::808:808', '100::1', '2001:db8::1', '2001:0db8::1', '2001:DB8:FFFF::1', '2001::1', '2001:1ff::1',
    'fc00::1', 'fd12:3456::1', 'fe80::1', 'febf::1', 'ff02::1'] as $blocked) {
    verify(str_contains((string) DomainManager::glueIpError($blocked), 'Use a public IP address'), 'Non-public address '.$blocked.' was accepted.');
}
foreach (['', 'not-an-ip', '1.2.3', '256.1.1.1', enomPublicIpv4(1).'/24', '[::1]', ' '.enomPublicIpv4(2).' '] as $malformed) {
    verify(DomainManager::glueIpError($malformed) === 'Enter an IPv4 or IPv6 address.', 'Malformed address '.json_encode($malformed).' was not refused as malformed.');
}

// ---------------------------------------------------------------- request validation: host under the domain itself
$before = tableRows(['enom_operations']);
foreach ([['glue_add', ['host' => 'ns1.other-domain.com', 'ip' => enomPublicIpv4(1)], 'host'], ['glue_add', ['host' => 'nsglue-fixture.com', 'ip' => enomPublicIpv4(1)], 'host'],
    ['glue_add', ['host' => 'glue-fixture.com', 'ip' => enomPublicIpv4(1)], 'host'], ['glue_add', ['host' => 'ns1.glue-fixture.com', 'ip' => '10.0.0.1'], 'ip'],
    ['glue_add', ['host' => 'ns1.glue-fixture.com', 'ip' => '2001:0db8::1'], 'ip'], ['glue_add', ['host' => 'ns1.glue-fixture.com'], 'ip'],
    ['glue_update', ['host' => 'ns_1.glue-fixture.com', 'ip' => enomPublicIpv4(1)], 'host'], ['glue_delete', ['host' => ''], 'host']] as [$action, $payload, $field]) {
    try { glue($action, $payload); verify(false, $action.' accepted '.json_encode($payload)); }
    catch (InputValidationException $error) { verify(array_keys($error->errors) === [$field], $action.' refusal keyed '.json_encode(array_keys($error->errors)).' for '.json_encode($payload)); }
}
refuses(fn () => $operations->enqueueManagement($stranger, $domain->id, 'glue_add', ['host' => 'ns1.glue-fixture.com', 'ip' => enomPublicIpv4(1)], key64()), '', 'Another client added glue.', [Illuminate\Database\Eloquent\ModelNotFoundException::class]);
verify(tableRows(['enom_operations']) == $before, 'Refused glue requests wrote history.');
// The client page path needs the glue capability; staff use the same validation.
verify(($manager->snapshot($owner, $domain->id)['capabilities']['glue'] ?? false) === true, 'Active domain without unresolved requests does not offer glue.');
refuses(fn () => $staffDomains->requestChange($staff, $domain->id, 'glue_add', ['host' => 'ns1.elsewhere.net', 'ip' => enomPublicIpv4(1)]), 'must end in .glue-fixture.com', 'Staff added glue for another domain.');

// ---------------------------------------------------------------- add: RegisterNameServer, then CheckNSStatus must show the address
$add = $manager->request($owner, $domain->id, 'glue_add', ['confirmed' => '1', 'host' => 'NS1.Glue-Fixture.com.', 'ip' => enomPublicIpv6(1, true), '_request_key' => key64()]);
verify($add->payload === ['host' => 'ns1.glue-fixture.com', 'ip' => enomPublicIpv6(1, true)] && $add->state === 'queued', 'Glue add payload not normalized.');
$requests = [];
$done = $operations->execute($add->id);
verify($done->state === 'succeeded' && array_column($requests, 0) === ['RegisterNameServer', 'CheckNSStatus']
    && $requests[0][1] === ['Add' => 'true', 'NSName' => 'ns1.glue-fixture.com', 'IP' => enomPublicIpv6(1, true)], 'Glue add did not register then read back: '.json_encode(array_column($requests, 0)));
verify(glueRows() === [['host' => 'ns1.glue-fixture.com', 'ip' => enomPublicIpv6(1, true)]], 'Added glue not recorded on the snapshot.');
verify($done->payload === ['host' => 'ns1.glue-fixture.com'] && $done->completed_at !== null, 'Completed glue request did not keep only its public host.');
verify(DomainManager::safeSnapshot($domain->fresh()->snapshot)['glue'] === [['host' => 'ns1.glue-fixture.com', 'ip' => enomPublicIpv6(1, true)]], 'Page snapshot lacks the glue row.');
refuses(fn () => glue('glue_add', ['host' => 'ns1.glue-fixture.com', 'ip' => enomPublicIpv4(1)]), 'already exists', 'A known host was added twice.');
// A readback with a different address leaves the change uncertain and the snapshot untouched.
$applyWrites = false;
$requests = [];
$mismatch = $operations->execute(glue('glue_add', ['host' => 'ns2.glue-fixture.com', 'ip' => enomPublicIpv4(1)])->id);
verify($mismatch->state === 'uncertain' && $mismatch->safe_code === 'management_unconfirmed' && count(glueRows()) === 1, 'Unconfirmed glue add was recorded.');
// Reconciliation confirms it once the registry shows the address (registry spelling of the same address counts).
$registry['ns2.glue-fixture.com'] = enomPublicIpv4(1);
$requests = [];
$reconciled = $operations->reconcile($mismatch->id);
verify($reconciled->state === 'succeeded' && $reconciled->safe_code === 'readback_confirmed' && array_column($requests, 0) === ['CheckNSStatus'], 'Uncertain glue add did not reconcile by read only.');
verify(array_column(glueRows(), 'host') === ['ns1.glue-fixture.com', 'ns2.glue-fixture.com'], 'Reconciled glue not recorded in host order.');
// An unconfirmed write answer (NsSuccess missing) is uncertain; an unreadable status keeps it so.
$writeReply = str_replace('<NsSuccess>1</NsSuccess>', '', file_get_contents($fixtures.'enom-registernameserver.xml'));
$unknown = $operations->execute(glue('glue_add', ['host' => 'ns3.glue-fixture.com', 'ip' => enomPublicIpv4(2)])->id);
$writeReply = null;
verify($unknown->state === 'uncertain' && $unknown->safe_code === 'provider_unknown', 'Unconfirmed RegisterNameServer was not uncertain: '.$unknown->safe_code);
$statusFails = ['ns3.glue-fixture.com'];
verify($operations->reconcile($unknown->id)->state === 'uncertain', 'Unreadable host status resolved an uncertain change.');
$statusFails = [];
verify($operations->reconcile($unknown->id)->state === 'uncertain', 'An absent host resolved an uncertain add.');
Operation::whereKey($unknown->id)->update(['state' => 'rejected', 'completed_at' => now()]); // Staff resolution.
// The registry's refusal (captured policy error, RRPCode 200 with an error and no NsSuccess=1) created nothing:
// it is a definite rejection that keeps Enom's message and doesn't block later changes.
$writeReply = file_get_contents($fixtures.'enom-registernameserver-refused.xml');
$refused = $operations->execute(glue('glue_add', ['host' => 'ns4.glue-fixture.com', 'ip' => enomPublicIpv4(4)])->id);
$writeReply = null;
verify($refused->state === 'rejected', 'Registry-refused glue change was not a definite rejection: '.$refused->state);
verify(str_contains($refused->cost['provider_message'] ?? '', 'Parameter value policy error'), 'provider-message-prefers-rrptext: DomainOperations::execute() (Enom/Services/DomainOperations.php:399) stores RRPText before errors.Err1 as the staff-visible provider message. Enom\'s captured RegisterNameServer refusal carries RRPText "Command completed successfully" beside Err1 "Nameserver registration failed due to error: Parameter value policy error", so staff are shown "Command completed successfully" for a refused change.');
$applyWrites = true;

// ---------------------------------------------------------------- update: the old address is read before dispatch
$update = glue('glue_update', ['host' => 'ns1.glue-fixture.com', 'ip' => enomPublicIpv4(3)]);
$requests = [];
$updated = $operations->execute($update->id);
verify($updated->state === 'succeeded' && array_column($requests, 0) === ['CheckNSStatus', 'UpdateNameServer', 'CheckNSStatus']
    && $requests[1][1] === ['NS' => 'ns1.glue-fixture.com', 'OldIP' => enomPublicIpv6(1, true), 'NewIP' => enomPublicIpv4(3)], 'Glue update did not read, update with the old address, then read back.');
verify(glueRows()[0] === ['host' => 'ns1.glue-fixture.com', 'ip' => enomPublicIpv4(3)] && $updated->payload === ['host' => 'ns1.glue-fixture.com'], 'Updated glue not recorded or payload kept the address.');
// Unchanged (the same address in another spelling): no write at all.
$registry['ns2.glue-fixture.com'] = enomPublicIpv6(1, true);
$requests = [];
$same = $operations->execute(glue('glue_update', ['host' => 'ns2.glue-fixture.com', 'ip' => enomPublicIpv6(1)])->id);
verify($same->state === 'succeeded' && $same->safe_code === 'confirmed' && array_column($requests, 0) === ['CheckNSStatus'], 'Unchanged glue update sent a write.');
// A host the registry doesn't have is rejected before any write.
$requests = [];
$missing = $operations->execute(glue('glue_update', ['host' => 'ns9.glue-fixture.com', 'ip' => enomPublicIpv4(3)])->id);
verify($missing->state === 'rejected' && $missing->safe_code === 'host_not_found' && array_column($requests, 0) === ['CheckNSStatus'] && !isset($missing->cost['refund']), 'Update of a missing host reached UpdateNameServer.');
// An unknown status read before dispatch never writes.
$statusFails = ['ns1.glue-fixture.com'];
$requests = [];
$blind = $operations->execute(glue('glue_update', ['host' => 'ns1.glue-fixture.com', 'ip' => enomPublicIpv4(5)])->id);
$statusFails = [];
verify(!in_array('UpdateNameServer', array_column($requests, 0), true) && $blind->state !== 'succeeded', 'Glue update without the current address reached Enom: '.$blind->state);
Operation::whereKey($blind->id)->update(['state' => 'rejected', 'completed_at' => now()]);

// ---------------------------------------------------------------- delete: DeleteNameServer, then the host must be gone
$requests = [];
$deleted = $operations->execute(glue('glue_delete', ['host' => 'ns2.glue-fixture.com'])->id);
verify($deleted->state === 'succeeded' && array_column($requests, 0) === ['DeleteNameServer', 'CheckNSStatus'] && array_column(glueRows(), 'host') === ['ns1.glue-fixture.com'], 'Glue delete not confirmed by absence.');
$applyWrites = false;
$stuck = $operations->execute(glue('glue_delete', ['host' => 'ns1.glue-fixture.com'])->id);
verify($stuck->state === 'uncertain' && array_column(glueRows(), 'host') === ['ns1.glue-fixture.com'], 'Delete still visible in the registry was confirmed.');
unset($registry['ns1.glue-fixture.com']);
verify($operations->reconcile($stuck->id)->state === 'succeeded' && glueRows() === [], 'Uncertain delete did not reconcile on absence.');
$applyWrites = true;

// ---------------------------------------------------------------- refresh: merge, never lose concurrent or unexamined rows
$registry = ['ns1.glue-fixture.com' => enomPublicIpv4(3), 'ns2.glue-fixture.com' => enomPublicIpv4(2), 'ns4.glue-fixture.com' => enomPublicIpv4(5)];
$domain->update(['snapshot' => ['glue' => [['host' => 'ns1.glue-fixture.com', 'ip' => enomPublicIpv4(6)], ['host' => 'ns2.glue-fixture.com', 'ip' => enomPublicIpv4(2)],
    ['host' => 'ns3.glue-fixture.com', 'ip' => enomPublicIpv4(7)], ['host' => 'ns4.glue-fixture.com', 'ip' => enomPublicIpv4(5)]], 'registry' => ['us_nexus' => 'C11']]]);
$statusFails = ['ns4.glue-fixture.com'];
// While the refresh reads, a request records a new host and changes ns2.
$duringStatus = function (string $host) use ($domain): void {
    if ($host !== 'ns3.glue-fixture.com') { return; }
    $snapshot = $domain->fresh()->snapshot;
    $snapshot['glue'][] = ['host' => 'ns5.glue-fixture.com', 'ip' => enomPublicIpv4(8)];
    $snapshot['glue'][1]['ip'] = enomPublicIpv4(4);
    Domain::whereKey($domain->id)->first()->update(['snapshot' => $snapshot]);
};
$operations->refreshDomain($domain->id, $owner);
$duringStatus = null; $statusFails = [];
$merged = collect(glueRows())->pluck('ip', 'host')->all();
verify(($merged['ns1.glue-fixture.com'] ?? null) === enomPublicIpv4(3), 'Refresh did not take the registry address for an examined host.');
verify(!array_key_exists('ns3.glue-fixture.com', $merged), 'Refresh kept a host the registry no longer has.');
verify(($merged['ns4.glue-fixture.com'] ?? null) === enomPublicIpv4(5), 'Refresh dropped a host it could not examine.');
verify(($merged['ns5.glue-fixture.com'] ?? null) === enomPublicIpv4(8), 'Refresh dropped a host added meanwhile.');
verify(($merged['ns2.glue-fixture.com'] ?? null) === enomPublicIpv4(4), 'Refresh overwrote a host changed by a request meanwhile with its stale read.');
verify(($domain->fresh()->snapshot['registry'] ?? null) === ['us_nexus' => 'C11'] && ($domain->fresh()->capabilities['glue'] ?? null) === true, 'Refresh lost registry details or the glue capability.');
$readHosts = array_values(array_map(fn ($r) => $r[1]['CheckNSName'], array_filter($requests, fn ($r) => $r[0] === 'CheckNSStatus')));
verify(count($readHosts) >= 4, 'Refresh did not re-check the known hosts.');

echo 'Glue records: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; injected transport, sanitized TEST write captures; SQLite in memory.'.PHP_EOL;
$successful = true;
