<?php
// Native registry fields, conditional dependencies, dispatch values and retained safe snapshots.
// Disposable native app; injected provider; no real requests.
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
use Paymenter\Extensions\Servers\Enom\Models\Tld;
require_once dirname(__DIR__).'/enom-cart-order.php';
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
    if ($command === 'PE_GetProductPrice') { return enomXml($command, '<productprice><productenabled>True</productenabled><price>6.0000</price></productprice>'); }
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
$us = enomTestTld(['server_id' => $server->id, 'tld' => 'us', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '9.00', 'renew_price' => '10.00', 'transfer_price' => '9.50',
    'registry_fields' => $schema]);
enomTestTld(['server_id' => $server->id, 'tld' => 'com', 'enabled' => true, 'pricing_mode' => 'fixed', 'register_price' => '12.00', 'renew_price' => '14.00', 'transfer_price' => '13.00']);
enomCartProfile($client);
$contacts = ['registrant' => ['first_name' => 'Nexus', 'last_name' => 'Owner', 'address1' => '1 Main Street', 'city' => 'Austin', 'state_province' => 'TX', 'postal_code' => '73301',
    'country' => 'US', 'email_address' => 'nexus.owner@example.com', 'phone' => '+1.5125550100']];
$history = fn () => tableRows(['invoices', 'invoice_items', 'services', 'orders', 'enom_domains', 'enom_operations']);

// Resolve a dependency chain even when children precede their parents in the schema.
$chain = [
    ['name' => 'child_c', 'label' => 'Child C', 'required' => false, 'child' => true, 'text' => true, 'options' => []],
    ['name' => 'child_b', 'label' => 'Child B', 'required' => false, 'child' => true, 'text' => false,
        'options' => [['value' => 'needs-c', 'label' => 'Needs C', 'requires' => ['child_c']]]],
    ['name' => 'parent', 'label' => 'Parent', 'required' => true, 'child' => false, 'text' => false,
        'options' => [['value' => 'needs-b', 'label' => 'Needs B', 'requires' => ['child_b']], ['value' => 'independent', 'label' => 'Independent', 'requires' => []]]],
];
try { RegistryFields::validate($chain, ['parent' => 'needs-b', 'child_b' => 'needs-c']); throw new RuntimeException('Multi-step registry dependency accepted missing C.'); }
catch (InputValidationException $error) { verify($error->errors === ['registry.child_c' => 'Fill in this field.'], 'Multi-step registry validation did not require child C.'); }
verify(RegistryFields::validate($chain, ['parent' => 'needs-b', 'child_b' => 'needs-c', 'child_c' => 'details'])
    === ['child_c' => 'details', 'child_b' => 'needs-c', 'parent' => 'needs-b'], 'Complete multi-step registry input was not retained.');
foreach ([['parent' => 'independent', 'child_b' => 'needs-c'], ['parent' => 'independent', 'child_b' => 'needs-c', 'child_c' => 'unused']] as $input) {
    verify(RegistryFields::validate($chain, $input) === ['parent' => 'independent'], 'Unused child B activated or retained its dependency C.');
}

// The stored schema reaches native checkout with its conditional child fields.
verify(app(Catalogue::class)->attributesFor($us->fresh()) === $schema && count($schema) === 3, 'Stored registry schema did not round-trip.');
$fields = app(\Paymenter\Extensions\Servers\Enom\Services\CartOrdering::class)->fields(app(Catalogue::class)->productFor($us, 'register'), ['enom_registry_us_nexus'=>'C31']);
verify(in_array('enom_registry_global_cc_us', array_column($fields,'name'), true), 'Native checkout omitted the required child field.');
foreach ([[], ['us_nexus'=>'C31','us_purpose'=>'P1'], ['us_nexus'=>'C11','us_purpose'=>'P9']] as $input) {
    $cart = enomCartStart($client);
    $product = app(Catalogue::class)->productFor($us,'register'); $plan = app(Catalogue::class)->billingPlan($product);
    $config=['domain'=>'invalid.us']; foreach($input as $key=>$value){$config['enom_registry_'.$key]=$value;}
    $before=$history();
    refuses(fn()=> $cart->items()->create(['product_id'=>$product->id,'plan_id'=>$plan->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>$config]), '', 'Invalid registry config accepted.', [InputValidationException::class]);
    verify($history()==$before,'Invalid registry config wrote billing rows.');
}
// Valid details: only known fields, the child only when its option requires it.
$invoice = enomCartOrder($client, 'nexus-one.us', 'register', 1, ['us_nexus' => 'C31', 'global_cc_us' => 'GB', 'us_purpose' => 'P1', 'injected' => 'x', 'RegistrantFirstName' => 'Injected'])['invoice'];
$operation = Operation::where('invoice_id', $invoice->id)->sole();
verify($operation->payload['registry'] === ['us_nexus' => 'C31', 'global_cc_us' => 'GB', 'us_purpose' => 'P1'], 'Stored registry details differ: '.json_encode($operation->payload['registry'] ?? null));
$citizen = enomCartOrder($client, 'nexus-two.us', 'register', 1, ['us_nexus' => 'C11', 'global_cc_us' => 'GB', 'us_purpose' => 'P3'])['invoice'];
verify(Operation::where('invoice_id', $citizen->id)->sole()->payload['registry'] === ['us_nexus' => 'C11', 'us_purpose' => 'P3'], 'An unrequested child value was kept.');
// Endings without registry fields ignore submitted details.
$plain = enomCartOrder($client, 'plain-name.com', 'register', 1, ['us_nexus' => 'C11'])['invoice'];
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

echo 'Registry details: '.$checks.' passed; injected provider only; SQLite in memory.'.PHP_EOL;
$successful = true;
