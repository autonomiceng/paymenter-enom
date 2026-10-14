<?php
// 0.4 registry details (.us nexus, purpose and a country child) through the native order path: the quote carries the
// stored schema, place() validates against it before writing anything, Purchase/TP_CreateOrder send the values, and the
// chosen values stay on the domain snapshot after the private order payload is purged. Injected provider only.
$enomTestLabel = 'registry';
require dirname(__DIR__).'/enom-native-app.php';

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\User;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\OrderIntent;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;
use Paymenter\Extensions\Servers\Enom\Services\DomainOrders;
use Paymenter\Extensions\Servers\Enom\Services\InputValidationException;
use Paymenter\Extensions\Servers\Enom\Services\RegistryFields;

Carbon\Carbon::setTestNow('2026-10-14 12:00:00'); Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::now());
$requests = [];
$available = true;
$transport = function (string $command, array $params) use (&$requests, &$available): string {
    $requests[] = [$command, $params];
    $name = strtolower(($params['SLD'] ?? '').'.'.($params['TLD'] ?? ''));
    if ($command === 'Check') {
        return enomXml($command, '<Domains><Domain><Name>'.$name.'</Name><RRPCode>'.($available ? '210' : '211').'</RRPCode><IsPremium>false</IsPremium><IsPlatinum>false</IsPlatinum><IsEAP>false</IsEAP>'
            .'<Properties><Transferable>'.($available ? 'false' : 'true').'</Transferable></Properties><Prices><Currency>USD</Currency><Registration>6.00</Registration><Renewal>6.00</Renewal><Transfer>6.00</Transfer></Prices></Domain></Domains>');
    }
    if ($command === 'Purchase') { return enomXml($command, '<RRPCode>200</RRPCode><OrderID>91001</OrderID><DomainInfo><RegistryExpDate>2027-10-14 10:00:00.000</RegistryExpDate></DomainInfo>'); }
    if ($command === 'GetDomainExp') { return enomXml($command, '<ExpirationDate>2027-10-14</ExpirationDate>'); }
    if ($command === 'GetDomainInfo') { return enomXml($command, '<GetDomainInfo><domainname domainnameid="5150">'.$name.'</domainname><status><expiration>2027-10-14</expiration><belongs-to>offline-account</belongs-to></status></GetDomainInfo>'); }
    if ($command === 'TP_CreateOrder') { return enomXml($command, '<TransferOrderID>9200</TransferOrderID>'); }
    if (in_array($command, ['GetOrderDetail', 'ModifyNS'], true)) { return enomXml($command, '<RRPCode>200</RRPCode>'); }
    throw new RuntimeException('Unexpected provider command '.$command);
};
$operations = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', $transport)),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true]);
$app->instance(DomainOperations::class, $operations);
$orders = new DomainOrders($operations);
$client = User::create(['email' => 'nexus@example.invalid', 'password' => 'synthetic']);
$server = App\Models\Server::create(['name' => 'Registry Enom', 'type' => 'server', 'extension' => 'Enom', 'enabled' => true]);
foreach (['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => '1', 'billing_enabled' => '1'] as $key => $value) { $server->settings()->create(['key' => $key, 'value' => $value]); }
$fixture = file_get_contents(dirname(__DIR__).'/fixtures/enom-test-api/enom-getextattributes-us.xml');
$schema = RegistryFields::normalize((new RegistrarClient('uid', 'token', fn () => $fixture, null, true))->execute('GetExtAttributes', ['TLD' => 'us']));
$us = Tld::create(['server_id' => $server->id, 'tld' => 'us', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '9.00', 'renew_price' => '10.00', 'transfer_price' => '9.50',
    'registry_fields' => $schema]);
Tld::create(['server_id' => $server->id, 'tld' => 'com', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '12.00', 'renew_price' => '14.00', 'transfer_price' => '13.00']);
app(Catalogue::class)->ensureProduct($server->id);
$contacts = ['registrant' => ['first_name' => 'Nexus', 'last_name' => 'Owner', 'address1' => '1 Main Street', 'city' => 'Austin', 'state_province' => 'TX', 'postal_code' => '73301',
    'country' => 'US', 'email_address' => 'nexus.owner@example.com', 'phone' => '+1.5125550100']];
$history = fn () => tableRows(['invoices', 'invoice_items', 'services', 'orders', 'enom_domains', 'enom_operations']);

// The stored schema round-trips through the model cast and reaches the quote for the order form.
verify(app(Catalogue::class)->attributesFor($us->fresh()) === $schema && count($schema) === 3, 'Stored registry schema did not round-trip.');
$quote = $orders->quote($client, 'nexus-one.us');
verify($quote['registry_fields'] === $schema && $orders->quote($client, 'plain-name.com')['registry_fields'] === [], 'Quote does not carry exactly the ending\'s registry fields.');

// place() validates before writing: missing, unlisted and missing-child details refuse with registry.<name> keys.
foreach ([[[], ['registry.us_nexus', 'registry.us_purpose']], [['us_nexus' => 'C31', 'us_purpose' => 'P1'], ['registry.global_cc_us']],
    [['us_nexus' => 'C11', 'us_purpose' => 'P9'], ['registry.us_purpose']], [['us_nexus' => 'C32', 'us_purpose' => 'P1', 'global_cc_us' => 'XX'], ['registry.global_cc_us']]] as [$input, $keys]) {
    $before = $history();
    try { $orders->place($client, $quote['intent_id'], $contacts, null, [], $input); verify(false, 'Invalid registry details placed: '.json_encode($input)); }
    catch (InputValidationException $error) { verify(array_keys($error->errors) === $keys, 'Registry errors differ for '.json_encode($input).': '.json_encode($error->errors)); }
    verify($history() == $before && OrderIntent::findOrFail($quote['intent_id'])->claimed_at === null, 'Refused registry details wrote records or claimed the quote.');
}
// Valid details: only known fields, the child only when its option requires it.
$invoice = $orders->place($client, $quote['intent_id'], $contacts, null, [], ['us_nexus' => 'C31', 'global_cc_us' => 'GB', 'us_purpose' => 'P1', 'injected' => 'x', 'RegistrantFirstName' => 'Injected']);
$operation = Operation::where('invoice_id', $invoice->id)->sole();
verify($operation->payload['registry'] === ['us_nexus' => 'C31', 'global_cc_us' => 'GB', 'us_purpose' => 'P1'], 'Stored registry details differ: '.json_encode($operation->payload['registry'] ?? null));
$citizen = $orders->place($client, $orders->quote($client, 'nexus-two.us')['intent_id'], $contacts, null, [], ['us_nexus' => 'C11', 'global_cc_us' => 'GB', 'us_purpose' => 'P3']);
verify(Operation::where('invoice_id', $citizen->id)->sole()->payload['registry'] === ['us_nexus' => 'C11', 'us_purpose' => 'P3'], 'An unrequested child value was kept.');
// Endings without registry fields ignore submitted details.
$plain = $orders->place($client, $orders->quote($client, 'plain-name.com')['intent_id'], $contacts, null, [], ['us_nexus' => 'C11']);
verify(Operation::where('invoice_id', $plain->id)->sole()->payload['registry'] === [], 'Registry details stored for an ending without fields.');

// Execution: Purchase carries the values as name=value; once confirmed, the values stay visible on the snapshot.
ExtensionHelper::addPayment($invoice->id, null, amount: 9, isCreditTransaction: true);
$requests = [];
$done = $operations->execute($operation->id);
$purchase = array_values(array_filter($requests, fn ($request) => $request[0] === 'Purchase'));
verify(count($purchase) === 1 && $purchase[0][1]['us_nexus'] === 'C31' && $purchase[0][1]['global_cc_us'] === 'GB' && $purchase[0][1]['us_purpose'] === 'P1'
    && !isset($purchase[0][1]['injected']), 'Purchase did not carry exactly the registry details.');
verify($done->state === 'succeeded' && $done->payload === null, 'Registration did not confirm or kept its private payload.');
$domain = Domain::findOrFail($done->domain_id);
verify(($domain->snapshot['registry'] ?? null) === ['us_nexus' => 'C31', 'global_cc_us' => 'GB', 'us_purpose' => 'P1'], 'Registry details were lost when the payload was purged.');
verify(DomainManager::safeSnapshot($domain->snapshot)['registry'] === ['us_nexus' => 'C31', 'global_cc_us' => 'GB', 'us_purpose' => 'P1'], 'Page snapshot lost the registry details.');
// The page model filters malformed stored values.
verify(DomainManager::safeSnapshot(['registry' => ['ok_field' => 'yes', 'Bad Key' => 'x', 'too_long' => str_repeat('a', 256), 'array_value' => ['x'], 0 => 'y']])['registry'] === ['ok_field' => 'yes'], 'Malformed snapshot registry values reached the page.');
// A refresh keeps the registry details (Enom does not return them).
$refreshTransport = new DomainOperations(new EnomApi(new RegistrarClient('offline-account', 'offline-token', fn (string $command) => enomXml($command, '<RRPCode>200</RRPCode>'))),
    fn () => ['uid' => 'offline-account', 'token' => 'offline-token', 'operations_enabled' => true]);
$refreshTransport->refreshDomain($domain->id, $client);
verify(($domain->fresh()->snapshot['registry'] ?? null) === ['us_nexus' => 'C31', 'global_cc_us' => 'GB', 'us_purpose' => 'P1'], 'Refresh dropped the registry details.');

// Transfers send the same values on TP_CreateOrder.
$available = false;
$transferQuote = $orders->quote($client, 'nexus-move.us', 'transfer');
$transfer = $orders->place($client, $transferQuote['intent_id'], $contacts, 'EPP-NEXUS-1', [], ['us_nexus' => 'C21', 'us_purpose' => 'P2']);
ExtensionHelper::addPayment($transfer->id, null, amount: 9.5, isCreditTransaction: true);
$requests = [];
$operations->execute(Operation::where('invoice_id', $transfer->id)->sole()->id);
$create = array_values(array_filter($requests, fn ($request) => $request[0] === 'TP_CreateOrder'));
verify(count($create) === 1 && $create[0][1]['us_nexus'] === 'C21' && $create[0][1]['us_purpose'] === 'P2' && $create[0][1]['AuthInfo1'] === 'EPP-NEXUS-1', 'TP_CreateOrder did not carry the registry details.');

echo 'Registry details: '.$checks.' passed; known runtime bugs: '.($knownBugs ? implode(', ', $knownBugs) : 'none').'; injected provider only; SQLite in memory.'.PHP_EOL;
$successful = true;
