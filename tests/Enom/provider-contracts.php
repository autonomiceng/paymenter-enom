<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Offline only. No framework, credentials, provider requests or database required.
foreach (['DomainName', 'RegistrarResult', 'RegistrarClient', 'EnomApi'] as $class) {
    require_once __DIR__.'/../../Enom/Api/'.$class.'.php';
}

use Paymenter\Extensions\Servers\Enom\Api\DomainName;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException('Failed: '.$message); }
}
function rejects(callable $call, string $message): void {
    try { $call(); } catch (InvalidArgumentException) { check(true, $message); return; }
    check(false, $message);
}
function xml(string $command, string $body = '', string $errors = '0', string $done = 'true'): string {
    return '<interface-response>'.$body.'<Command>'.$command.'</Command><ErrCount>'.$errors.'</ErrCount><Done>'.$done.'</Done></interface-response>';
}
function response(string $command, string $body): RegistrarResult {
    return (new RegistrarClient('offline_uid', 'offline_token', fn () => $body))->execute($command);
}

check(DomainName::parse(' DEMO.COM ') === ['domain' => 'demo.com', 'sld' => 'demo', 'tld' => 'com'], 'canonical name');
check(DomainName::parse('a.com')['sld'] === 'a', 'valid single-character domain');
check(DomainName::parse('demo.co.uk', ['uk', 'co.uk'])['tld'] === 'co.uk', 'longest suffix');
check(DomainName::parse('xn--bcher-kva.com')['sld'] === 'xn--bcher-kva', 'ASCII punycode');
foreach (['', '-demo.com', 'demo-.com', 'ab--demo.com', 'sub.demo.com', 'demo.net', 'demo.com.', 'https://demo.com', 'demo.com:443', 'de mo.com', 'demo.com/path', "demo\0.com", str_repeat('a', 64).'.com'] as $name) {
    rejects(fn () => DomainName::parse($name), 'invalid name rejected');
}

foreach(['49,978.69'=>'49978.69','1,234,567.8'=>'1234567.8','100.00'=>'100.00'] as $provider=>$expected){
    check(EnomApi::normalize('balance',new RegistrarResult('success',null,['availablebalance'=>$provider]))['available_balance']===$expected,'Documented grouped USD balance was not normalized.');
}
foreach([null,1234,'9,99.00','49.978,69','$1,000.00','-1,000.00','01,000.00','1,000.123'] as $invalid){
    check(EnomApi::normalize('balance',new RegistrarResult('success',null,['availablebalance'=>$invalid]))['available_balance']===null,'Malformed balance became spend authority.');
}
check(response('GetBalance', xml('GETBALANCE', '<Balance>5000</Balance>'))->status === 'success', 'safe envelope');
foreach (['<interface-response>', '<html>error</html>', xml('Purchase'), xml('GetBalance', '', '0', 'false'), xml('GetBalance', '', '-1'), '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'.xml('GetBalance', '<Balance>&secret;</Balance>'), str_repeat('x', 1048577), xml('GetBalance', '<ErrCount>0</ErrCount>'), xml('GetBalance', '<Err1>problem</Err1>')] as $bad) {
    check(response('GetBalance', $bad)->status === 'unknown', 'ambiguous envelope never success');
}
check(response('GetBalance', xml('GetBalance', '<errors><Err1>Private provider message</Err1></errors>', '1'))->status === 'rejected', 'documented provider error');
check(response('GetBalance', xml('GetBalance', '<errors><Err1>contradictory</Err1></errors>'))->status === 'unknown', 'zero errors contradicts errors node');
$calls = 0;
$client = new RegistrarClient('not-printed', 'not-printed', function () use (&$calls) { ++$calls; throw new RuntimeException('token=SECRET URL and private body'); });
check($client->execute('Purchase')->summary() === ['status' => 'unknown', 'code' => 'transport'], 'secret-bearing exceptions sanitized');
check($calls === 1, 'no mutation retry');
$blocked = new RegistrarClient('fake', 'fake', fn () => throw new RuntimeException('must not run'));
foreach ([['UID' => 'override'], ['pW' => 'override'], ['ResponseType' => 'text'], ['OverrideOrder' => 1], ['UseCreditCard' => 'yes'], ['UseWireTransfer' => 1], ['RenewFlag' => 1], ['EmailEPP' => 'True'], ['EmailNotify' => 1], ['CreditCardNumber' => '123'], ['NS1' => 'a', 'ns1' => 'b']] as $parameters) {
    rejects(fn () => $blocked->execute('SetRenew', $parameters), 'unsafe parameters refused before transport');
}
rejects(fn () => $blocked->execute('DeleteRegistration'), 'deletion unavailable');
check((new RegistrarClient('fake', 'fake'))->execute('GetBalance')->status === 'unknown', 'transport guard refuses unconfigured requests before network access');
$gate = true; $guardedCalls = 0;
$guarded = new RegistrarClient('uid', 'token', function ($command) use (&$guardedCalls) { ++$guardedCalls; return xml($command); }, function () use (&$gate) { if (!$gate) { throw new RuntimeException('private configuration SECRET'); } });
$guarded->execute('GetBalance'); $gate = false;
$guardedResult = $guarded->execute('Purchase');
check($guardedCalls === 1 && $guardedResult->summary() === ['status' => 'rejected', 'code' => 'guard_refused'], 'fresh guard blocks mutation after preflight gate flips without a transport call');

$complete = '<RRPCode>200</RRPCode><OrderID>12345</OrderID><RegistryExpDate>2027-10-12T21:00:00Z</RegistryExpDate>';
check(response('Purchase', xml('Purchase', $complete))->status === 'success', 'completed purchase');
$observedPurchase = file_get_contents(__DIR__.'/provider-purchase-domaininfo.xml');
$nestedPurchase = response('Purchase', $observedPurchase);
check($nestedPurchase->status === 'success', 'observed nested DomainInfo Purchase expiry confirms clean registration acknowledgement');
check($nestedPurchase->confirmedExpiry()?->format(DATE_ATOM) === '2027-10-12T18:41:09+00:00', 'nested fractional plain date follows documented Pacific fallback');
$utcPurchase = str_replace('<RegistryExpDate>2027-10-12 11:41:09.000', '<RegistryExpDate UTC="2027-10-12T18:41:09Z">2027-10-12 11:41:09.000', $observedPurchase);
check(response('Purchase', $utcPurchase)->confirmedExpiry()?->format(DATE_ATOM) === '2027-10-12T18:41:09+00:00', 'nested UTC attribute parsed as UTC');
foreach (['2027-02-30 11:41:09.000', '2027-10-12 +1 year', 'not a date', ''] as $malformedDate) {
    check(response('Purchase', str_replace('2027-10-12 11:41:09.000', $malformedDate, $observedPurchase))->status === 'unknown', 'malformed nested expiry cannot confirm purchase');
}
check(response('Purchase', str_replace('<OrderID>12345</OrderID>', '<OrderID>0</OrderID>', $observedPurchase))->status === 'unknown', 'nested expiry never bypasses valid purchase order ID requirement');
check(response('Purchase', str_replace('<Done>true</Done>', '<Done>false</Done>', $observedPurchase))->status === 'unknown', 'nested expiry never bypasses clean completed envelope requirement');
check(response('Purchase', str_replace('</interface-response>', '<OrderStatus>Pending</OrderStatus></interface-response>', $observedPurchase))->status === 'pending', 'nested expiry does not override explicit pending registrar status');
check(response('Purchase', str_replace('</interface-response>', '<RegistryExpDate>2028-10-12T18:41:09Z</RegistryExpDate></interface-response>', $observedPurchase))->status === 'unknown', 'conflicting known nested and flat expiry aliases fail closed');
check(response('Purchase', str_replace('</interface-response>', '<RegistryExpDate>2027-10-12T18:41:09Z</RegistryExpDate></interface-response>', $observedPurchase))->status === 'success', 'matching known nested and flat expiry aliases agree across timezone representations');
check(response('Purchase', str_replace('</interface-response>', '<RegistryExpDate>2027-10-12</RegistryExpDate></interface-response>', $observedPurchase))->status === 'success', 'matching date-only and precise timestamp aliases agree at UTC calendar-day precision');
check(response('Purchase', str_replace('</interface-response>', '<RegistryExpDate>malformed</RegistryExpDate></interface-response>', $observedPurchase))->status === 'unknown', 'malformed known alias cannot hide behind another valid alias');
check(response('Purchase', str_replace('UTC="2027-10-12T18:41:09Z"', 'UTC="not a date"', $utcPurchase))->status === 'unknown', 'malformed supplied UTC attribute cannot silently fall back to display text');
check(response('Purchase', xml('Purchase', '<RRPCode>1300</RRPCode><OrderID>12345</OrderID>'))->status === 'pending', 'queued purchase pending');
check(response('Purchase', xml('Purchase', '<RRPCode>200</RRPCode><OrderID>12345</OrderID>'))->status === 'unknown', 'missing purchase expiry');
check(response('Purchase', xml('Purchase', '<RRPCode>200</RRPCode><OrderID>0</OrderID><RegistryExpDate>2027-10-12</RegistryExpDate>'))->status === 'unknown', 'invalid purchase ID');
check(response('Purchase', xml('Purchase', $complete.'<OrderStatus>Pending</OrderStatus>'))->status === 'pending', 'consent pending despite realtime code');
foreach (['420', '421', '520', '521', '700', '701', '799', '880', '554', '555', '999', '607166', '621242', '202153'] as $code) {
    $ambiguous = response('Purchase', xml('Purchase', '<RRPCode>'.$code.'</RRPCode><Err1>Provider failure</Err1>', '1'));
    check($ambiguous->status === 'unknown' && $ambiguous->code === $code, 'write server/timeout/duplicate/unknown error is uncertain');
}
foreach (['504', '530', '531', '546', '548', '556', '304151', '413155', '715152', '524181'] as $code) {
    check(response('Extend', xml('Extend', '<RRPCode>'.$code.'</RRPCode><Err1>Documented refusal</Err1>', '1'))->status === 'rejected', 'documented definitive validation/permission/unavailable refusal');
}
check(response('Extend', xml('Extend', '<Err1>Uncoded error</Err1>', '1'))->status === 'unknown', 'uncoded write failure stays uncertain');
check(response('Purchase', xml('Purchase', '<RRPCode>504</RRPCode><OrderID>123</OrderID>', '1'))->status === 'unknown', 'order identity with refusal may indicate partial execution');
foreach (['Purchase', 'Extend'] as $command) {
    foreach (['<RegistryExpDate>2027-10-12 11:41:09.000</RegistryExpDate>',
        '<RegistryExpDate UTC="2027-10-12T18:41:09Z"/>', '<RegistryExpDate>not a date</RegistryExpDate>',
        '<RegistryExpDate>2027-10-12</RegistryExpDate><RegistryExpDate>2028-10-12</RegistryExpDate>'] as $partial) {
        $result = response($command, xml($command, '<RRPCode>504</RRPCode><DomainInfo>'.$partial.'</DomainInfo>', '1'));
        check($result->summary() === ['status' => 'unknown', 'code' => '504'], 'nested expiry alongside definitive code preserves partial-execution uncertainty');
    }
}
check(response('Purchase', xml('Purchase', '<RRPCode>504</RRPCode><DomainInfo><RegistryExpDate>2027-10-12</RegistryExpDate></DomainInfo><DomainInfo><RegistryExpDate>2028-10-12</RegistryExpDate></DomainInfo>', '1'))->status === 'unknown', 'repeated DomainInfo cannot hide nested partial-execution evidence');
check(response('Purchase', xml('Purchase', '<RRPCode>504</RRPCode><DomainInfo><RegistryExpDate/></DomainInfo>', '1'))->status === 'rejected', 'empty nested expiry does not override definitive refusal without execution evidence');
check(response('Extend', xml('Extend', '<RRPCode>504</RRPCode><ResponseCode>607166</ResponseCode>', '1'))->status === 'unknown', 'ambiguous secondary code defeats definitive rejection');
check(response('Extend', xml('Extend', $complete.'<Extension>Successful</Extension>'))->status === 'success', 'renewal complete');
check(response('Extend', xml('Extend', $complete))->status === 'unknown', 'renewal needs completion field');
check(response('GetDomainExp', xml('GetDomainExp', '<ExpirationDate>2027-02-30</ExpirationDate>'))->status === 'unknown', 'invalid expiry is not normalized');
$expiry = response('GetDomainExp', xml('GetDomainExp', '<ExpirationDate UTC="2027-10-13T04:00:00Z">10/12/2027 9:00:00 PM</ExpirationDate>'));
check($expiry->confirmedExpiry()?->format(DATE_ATOM) === '2027-10-13T04:00:00+00:00', 'UTC attribute preferred');
check(response('GetDomainExp', xml('GetDomainExp', '<ExpirationDate>10/12/2027 9:00:00 PM</ExpirationDate>'))->confirmedExpiry()?->format('H:i') === '04:00', 'Pacific daylight expiry normalized');
check(response('GetDomainExp', xml('GetDomainExp', '<ExpirationDate>1/12/2027 9:00:00 PM</ExpirationDate>'))->confirmedExpiry()?->format('H:i') === '05:00', 'Pacific winter expiry normalized');
foreach (['rejected', 'pending', 'unknown'] as $state) {
    $failedResult = new RegistrarResult($state, null, ['registryexpdate' => '2027-10-12', 'getwppsinfo' => ['wppsexpdate' => 'Nov 26, 2027']]);
    check($failedResult->confirmedExpiry() === null && $failedResult->confirmedPrivacyExpiry() === null, 'non-success result cannot confirm domain or privacy expiry');
}
$privacyXml = '<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>1</WPPSExists><WPPSEnabled>1</WPPSEnabled><WPPSExpDate>Nov 26, 2027</WPPSExpDate></GetWPPSInfo>';
$privacyResult = response('GetWPPSInfo', xml('GetWPPSInfo', $privacyXml));
check($privacyResult->confirmedPrivacyExpiry()?->format(DATE_ATOM) === '2027-11-26T08:00:00+00:00', 'documented privacy expiry month format with Pacific fallback');
check(EnomApi::normalize('privacy', $privacyResult)['confirmed_expires_at'] === '2027-11-26T08:00:00+00:00', 'confirmed privacy expiry normalized separately from raw date');
$privacyUtc = response('GetWPPSInfo', xml('GetWPPSInfo', str_replace('<WPPSExpDate>', '<WPPSExpDate UTC="2027-11-26T12:00:00Z">', $privacyXml)));
check($privacyUtc->confirmedPrivacyExpiry()?->format(DATE_ATOM) === '2027-11-26T12:00:00+00:00', 'privacy UTC attribute overrides Pacific date');
check(response('GetWPPSInfo', xml('GetWPPSInfo', str_replace('Nov 26, 2027', 'Nov 31, 2027', $privacyXml)))->confirmedPrivacyExpiry() === null, 'invalid privacy calendar day rejected');

$search = response('Check', xml('Check', '<Domains><Domain><Name>demo.com</Name><RRPCode>210</RRPCode><IsPremium>False</IsPremium><IsPlatinum>False</IsPlatinum><IsEAP>False</IsEAP><Prices><Currency/><Registration>8.95</Registration><Renewal>8.95</Renewal><Transfer>9.50</Transfer></Prices></Domain></Domains>'));
$quote = EnomApi::normalize('search', $search);
check($quote['available'] && $quote['is_premium'] === false, 'single domain search flags');
check($quote['currency'] === '' && $quote['prices']['registration'] === '8.95', 'blank currency and provider cost preserved without tariff inference');
check(response('Check', xml('Check', '<RRPCode>211</RRPCode>'))->status === 'success', 'unavailable domain is successful read');
check(response('Check', xml('Check', '<RRPCode>200</RRPCode>'))->status === 'unknown', 'unknown check availability');
check(response('TP_CreateOrder', xml('TP_CreateOrder', '<TransferOrderID>999</TransferOrderID>'))->status === 'pending', 'transfer accepted pending');
check(response('TP_CreateOrder', xml('TP_CreateOrder'))->status === 'unknown', 'transfer missing order');
check(response('TP_GetOrderDetail', xml('TP_GetOrderDetail', '<transferorderdetail><statusid>100</statusid></transferorderdetail>'))->status === 'pending', 'consent waiting pending');
check(response('TP_GetOrderDetail', xml('TP_GetOrderDetail', '<transferorderdetail><statusid>32</statusid></transferorderdetail>'))->status === 'rejected', 'invalid EPP transfer rejected');
check(response('TP_GetOrderDetail', xml('TP_GetOrderDetail', '<transferorderdetail><statusid>5</statusid></transferorderdetail>'))->status === 'success', 'detail5 complete');
check(response('TP_GetOrder', xml('TP_GetOrder', '<transferorder><statusid>5</statusid></transferorder>'))->status === 'pending', 'header5 draft differs from detail5');
$orderXml = '<Order><OrderID>12345</OrderID><Result>true</Result><OrderDetail><Description>demo.com</Description><Status>Successful</Status><OrderStatus>Closed</OrderStatus><Quantity>1</Quantity><ExpiryDatetime>10/12/2027 9:00:00 PM</ExpiryDatetime></OrderDetail></Order>';
$order = response('GetOrderDetail', xml('GetOrderDetail', $orderXml));
check($order->status === 'success', 'provider order item completion');
check(EnomApi::normalize('order', $order)['domain'] === 'demo.com' && EnomApi::normalize('order', $order)['term'] === 1, 'order identity and term normalizer');
check(response('GetOrderDetail', xml('GetOrderDetail', str_replace('<OrderStatus>Closed</OrderStatus>', '<OrderStatus>Open</OrderStatus>', $orderXml)))->status === 'pending', 'open order pending');
check(response('GetOrderDetail', xml('GetOrderDetail', '<Order><Result>false</Result></Order>'))->status === 'unknown', 'unknown ownership never confirms order');

$requests = [];
$api = new EnomApi(new RegistrarClient('uid', 'token', function ($command, $parameters) use (&$requests) {
    $requests[] = [$command, $parameters];
    return xml($command);
}));
$contact = ['first_name' => 'Demo', 'last_name' => 'Owner', 'organization_name' => 'Example', 'address1' => '1 Example Street', 'city' => 'Test', 'state_province' => 'CA', 'postal_code' => '90000', 'country' => 'US', 'email_address' => 'demo@example.invalid', 'phone' => '+1.5551234567'];
$api->register('demo.com', ['registrant' => $contact, 'admin' => $contact], [], 1);
$register = end($requests)[1];
check($register['RenewName'] === 0 && $register['UseDNS'] === 'default' && $register['UseCreditCard'] === 'no', 'registration funded externally and default DNS');
check(isset($register['RegistrantFirstName'], $register['AdminFirstName']), 'complete contact role sets preserved');
$api->renew('demo.com'); check(!isset(end($requests)[1]['OverrideOrder']), 'renew does not bypass duplicate guard');
$api->transfer('demo.com', $contact, 'PRIVATE-EPP');
check(end($requests)[1]['DomainCount'] === 1 && end($requests)[1]['AuthInfo1'] === 'PRIVATE-EPP' && end($requests)[1]['Renew'] === 0, 'one transfer private EPP and no auto renew');
$api->updateContact('demo.com', 'tech', $contact);
check(end($requests)[1]['ContactType'] === 'TECH' && isset(end($requests)[1]['TechFirstName']) && !isset(end($requests)[1]['RegistrantFirstName']), 'contact update cannot clear other roles');
$api->setLock('demo.com', true); check(end($requests)[1]['UnlockRegistrar'] === 0, 'lock flag inverse');
$api->setLock('demo.com', false); check(end($requests)[1]['UnlockRegistrar'] === 1, 'unlock flag inverse');
$api->synchronizeEpp('demo.com'); check(end($requests)[1]['EmailEPP'] === 'False', 'EPP synchronization never sends email');
$api->disableProviderRenewal('demo.com'); check(end($requests)[1]['RenewFlag'] === 0 && end($requests)[1]['WPPSRenew'] === 0, 'domain and privacy provider auto renew off');
$api->purchasePrivacy('demo.com'); check(end($requests)[1]['Service'] === 'WPPS' && end($requests)[1]['RenewName'] === 0, 'privacy purchase does not silently auto-renew');
$api->enablePrivacy('demo.com', false); check(end($requests)[0] === 'DisableServices', 'privacy toggle separate from purchase');
$api->setNameservers('demo.com', ['NS1.EXAMPLE.COM', 'ns2.example.com']);
check(end($requests)[1]['NS1'] === 'ns1.example.com' && !isset(end($requests)[1]['UseDNS']), 'custom DNS exclusive default');
$api->setHosts('demo.com', [['name' => '@', 'type' => 'A', 'address' => '192.0.2.1'], ['name' => '@', 'type' => 'TXT', 'address' => 'v=spf1 -all'], ['name' => '@', 'type' => 'MX', 'address' => 'mail.example.com.', 'mx_pref' => 20]]);
check(end($requests)[1]['HostName3'] === '@' && end($requests)[1]['RecordType2'] === 'TXT' && end($requests)[1]['MXPref3'] === 20, 'whole ordinary DNS set preserved');
$api->setSrv('demo.com', [['service' => 'sip', 'protocol' => 'tcp', 'priority' => 1, 'weight' => 10, 'port' => 5060, 'target' => 'sip.example.com.']]);
check(end($requests)[1]['Target1'] === 'sip.example.com.' && end($requests)[1]['Protocol1'] === 'TCP', 'SRV documented inputs');
$ds = ['algorithm' => 13, 'digest_type' => 2, 'key_tag' => 1234, 'digest' => str_repeat('AB', 32)];
$api->addDnssec('demo.com', $ds); $added = end($requests)[1];
$api->deleteDnssec('demo.com', $ds); check(end($requests)[1] === $added, 'delete DS exact matching tuple');
foreach ([fn () => $api->register('demo.com', $contact, [], 2), fn () => $api->register('xn--bcher-kva.com', $contact), fn () => $api->updateContact('demo.com', 'billing', $contact), fn () => $api->updateContact('demo.com', 'registrant', ['first_name' => 'Only']), fn () => $api->setNameservers('demo.com', ['ns1.example.com']), fn () => $api->setHosts('demo.com', []), fn () => $api->setHosts('demo.com', [['name' => '@', 'type' => 'A', 'address' => 'bad']]), fn () => $api->setHosts('demo.com', [['name' => '@', 'type' => 'MX', 'address' => 'mail.example.com']]), fn () => $api->addDnssec('demo.com', ['algorithm' => 13, 'digest_type' => 2, 'key_tag' => 1234, 'digest' => 'BAD']), fn () => $api->orders('10/12/2026', '10/12/2027')] as $invalid) {
    $before = count($requests); rejects($invalid, 'invalid facade request'); check(count($requests) === $before, 'invalid request makes no transport call');
}

$dns = response('GetDNS', xml('GetDNS', '<dns>ns1.example.com</dns><dns>ns2.example.com</dns><UseDNS>default</UseDNS>'));
check(EnomApi::normalize('nameservers', $dns)['nameservers'] === ['ns1.example.com', 'ns2.example.com'], 'repeated DNS tags retained');
$contacts = response('GetContacts', xml('GetContacts', '<GetContacts><Registrant><FirstName>Demo</FirstName><LastName>Owner</LastName></Registrant><Tech><FirstName>Other</FirstName></Tech></GetContacts>'));
check(EnomApi::normalize('contacts', $contacts)['contacts']['tech']['first_name'] === 'Other', 'untouched contacts roundtrip');
$contactStatusApi = new EnomApi(new RegistrarClient('uid', 'token', fn ($command) => xml($command, '<GetContacts><Registrant><FirstName>Demo</FirstName></Registrant><PendingVerification>True</PendingVerification><TransferLock>True</TransferLock></GetContacts>')));
$contactStatusSnapshot = $contactStatusApi->snapshot('demo.com', ['contacts']);
check($contactStatusSnapshot['contact_status'] === ['pending_verification' => true, 'transfer_locked' => true], 'pending verification and transfer lock retained in flat snapshot');
check($contactStatusSnapshot['contacts']['registrant']['first_name'] === 'Demo', 'contact values and pending status retained independently');
$missingStatusApi = new EnomApi(new RegistrarClient('uid', 'token', fn ($command) => xml($command, '<GetContacts><Registrant><FirstName>Demo</FirstName></Registrant></GetContacts>')));
check($missingStatusApi->snapshot('demo.com', ['contacts'])['contact_status'] === ['pending_verification' => null, 'transfer_locked' => null], 'absent provider status does not imply verified or unlocked');
// Contact containers accept role-prefixed fields.
$prefixedContactsBody = '<GetContacts>';
$expectedContacts = [];
foreach (['Registrant', 'Admin', 'Tech', 'AuxBilling'] as $role) {
    $type = strtolower($role);
    $prefixedContactsBody .= '<'.$role.'>';
    foreach (['first_name' => 'FirstName', 'last_name' => 'LastName', 'organization_name' => 'OrganizationName',
        'address1' => 'Address1', 'address2' => 'Address2', 'city' => 'City', 'state_province' => 'StateProvince',
        'state_province_choice' => 'StateProvinceChoice', 'postal_code' => 'PostalCode', 'country' => 'Country',
        'email_address' => 'EmailAddress', 'phone' => 'Phone', 'phone_ext' => 'PhoneExt', 'fax' => 'Fax', 'job_title' => 'JobTitle'] as $key => $suffix) {
        $value = 'synthetic-'.$type.'-'.$key;
        $expectedContacts[$type][$key] = $value;
        $prefixedContactsBody .= '<'.$role.$suffix.'>'.$value.'</'.$role.$suffix.'>';
    }
    $prefixedContactsBody .= '<'.$role.'PartyID>synthetic-private-id</'.$role.'PartyID></'.$role.'>';
}
$prefixedContactsBody .= '<PendingVerification>True</PendingVerification><TransferLock>False</TransferLock></GetContacts>';
$prefixedContacts = EnomApi::normalize('contacts', response('GetContacts', xml('GetContacts', $prefixedContactsBody)));
foreach ($expectedContacts as $role => $expected) {
    foreach ($expected as $key => $value) { check(($prefixedContacts['contacts'][$role][$key] ?? null) === $value, 'prefixed '.$role.' '.$key.' preserved'); }
    check(!isset($prefixedContacts['contacts'][$role]['party_id']), 'provider contact identity remains outside customer form fields');
}
check($prefixedContacts['pending_verification'] === true && $prefixedContacts['transfer_locked'] === false, 'prefixed contact values preserve independent verification/transfer-lock flags');
$identicalAliases = response('GetContacts', xml('GetContacts', '<GetContacts><Registrant><FirstName>Same</FirstName><RegistrantFirstName>Same</RegistrantFirstName></Registrant></GetContacts>'));
check((EnomApi::normalize('contacts', $identicalAliases)['contacts']['registrant']['first_name'] ?? null) === 'Same', 'matching legacy and prefixed aliases are unambiguous');
foreach (['Registrant', 'Admin', 'Tech', 'AuxBilling'] as $role) {
    $other = $role === 'Registrant' ? '' : '<Registrant><RegistrantFirstName>Synthetic</RegistrantFirstName></Registrant>';
    $conflict = response('GetContacts', xml('GetContacts', '<GetContacts>'.$other.'<'.$role.'><FirstName>Different</FirstName><'.$role.'FirstName>Synthetic</'.$role.'FirstName></'.$role.'></GetContacts>'));
    check(EnomApi::normalize('contacts', $conflict) === ['status' => 'unknown'], 'conflicting '.$role.' aliases fail closed without partial contact forms');
}
$repeatedContactField = response('GetContacts', xml('GetContacts', '<GetContacts><Registrant><RegistrantFirstName>One</RegistrantFirstName><RegistrantFirstName>Two</RegistrantFirstName></Registrant></GetContacts>'));
check(EnomApi::normalize('contacts', $repeatedContactField) === ['status' => 'unknown'], 'repeated contact values cannot silently normalize to empty');
$nestedContactField = response('GetContacts', xml('GetContacts', '<GetContacts><Registrant><RegistrantFirstName><Nested>Hidden</Nested>Visible</RegistrantFirstName></Registrant></GetContacts>'));
check(EnomApi::normalize('contacts', $nestedContactField) === ['status' => 'unknown'], 'nested contact content cannot be silently dropped while extracting text');
$attributedContactField = response('GetContacts', xml('GetContacts', '<GetContacts><Registrant><RegistrantFirstName language="en">Synthetic</RegistrantFirstName></Registrant></GetContacts>'));
check((EnomApi::normalize('contacts', $attributedContactField)['contacts']['registrant']['first_name'] ?? null) === 'Synthetic', 'plain contact text with scalar metadata attributes remains valid');
$repeatedContactRole = response('GetContacts', xml('GetContacts', '<GetContacts><Registrant><RegistrantFirstName>One</RegistrantFirstName></Registrant><Registrant><RegistrantFirstName>Two</RegistrantFirstName></Registrant></GetContacts>'));
check(EnomApi::normalize('contacts', $repeatedContactRole) === ['status' => 'unknown'], 'repeated contact role cannot turn into an empty partial form');
$prefixedSnapshotApi = new EnomApi(new RegistrarClient('uid', 'token', fn ($command) => xml($command, $prefixedContactsBody)));
check($prefixedSnapshotApi->snapshot('demo.com', ['contacts'])['contacts']['admin']['address1'] === 'synthetic-admin-address1', 'prefixed contacts reach actual flat snapshot consumed by management forms');
$srv = response('GetDomainSRVHosts', xml('GetDomainSRVHosts', '<srv-records><srv><HostID>1</HostID><HostName>_sip</HostName><Protocol>_TCP</Protocol><Address>sip.example.com.</Address><Priority>1</Priority><Weight>10</Weight><Port>5060</Port></srv></srv-records>'));
check(EnomApi::normalize('srv', $srv)['srv'][0]['service'] === 'sip', 'documented SRV container and service prefix');
$info = response('GetDomainInfo', xml('GetDomainInfo', '<GetDomainInfo><domainname domainnameid="123">demo.com</domainname><status><belongs-to party-id="private">reseller</belongs-to><expiration>2027-10-12</expiration></status></GetDomainInfo>'));
check(EnomApi::normalize('info', $info)['belongs_to'] === 'reseller', 'attributes retain identity text without losing it');
check($info->summary() === ['status' => 'success', 'code' => null], 'summary excludes private payload');
check(json_encode($info) === '{"status":"success","code":null}', 'JSON serialization excludes provider identity payload');
ob_start(); var_dump($client, $info); $debug = ob_get_clean();
check(!str_contains($debug, 'not-printed') && !str_contains($debug, 'party-id') && !str_contains($debug, 'reseller'), 'debug dumps exclude credentials and private response');
foreach (['GetDNS', 'GetRegLock', 'GetRenew', 'GetWPPSInfo', 'GetContacts', 'GetHosts', 'GetDomainSRVHosts', 'PurchaseServices', 'RenewServices', 'SynchAuthInfo'] as $command) {
    check(response($command, xml($command))->status === 'unknown', 'missing command-specific payload remains unknown');
}
check(response('GetDomainExp', xml('GetDomainExp', '<ExpirationDate>2027-10-12 +1 year</ExpirationDate>'))->status === 'unknown', 'relative expiry syntax rejected');
check(response('GetHosts', xml('GetHosts', '<host><name>@</name></host><DomainServices><HostRecords>True</HostRecords></DomainServices>'))->status === 'unknown', 'malformed host is not hidden by domain services');
check(response('AddDnsSec', xml('AddDnsSec', '<ResponseCode>555</ResponseCode>'))->status === 'unknown', 'unestablished response code is not success');
check(EnomApi::normalize('dnssec', response('GetDnsSec', xml('GetDnsSec')))['status'] === 'unknown', 'unknown DNSSEC shape cannot prove absence');
$subsetCalls = [];
$subsetApi = new EnomApi(new RegistrarClient('uid', 'token', function ($command) use (&$subsetCalls) { $subsetCalls[] = $command; return xml($command, '<reg-lock>1</reg-lock>'); }));
$snapshot = $subsetApi->snapshot('demo.com', ['lock']);
check($subsetCalls === ['GetRegLock'] && $snapshot['locked'] === true && $snapshot['read_status'] === ['lock' => 'success'], 'partial snapshot bounds command count and keeps flat fields');
$transfer = response('TP_GetOrder', xml('TP_GetOrder', '<transferorder><transferorderid>123</transferorderid><statusid>3</statusid><transferorderdetail><sld>demo</sld><tld>com</tld><statusid>5</statusid></transferorderdetail></transferorder>'));
check(EnomApi::normalize('transfer', $transfer)['completed'] && EnomApi::normalize('transfer', $transfer)['provider_order_id'] === '123', 'single transfer header and detail prove same domain operation');
// Getter response contracts use synthetic domain and order identifiers.
// The actual order has a numeric header state and a Closed detail, so the existing contract applies.
$observedOrder = response('GetOrderDetail', xml('GETORDERDETAIL', '<Order><OrderID>12345</OrderID><Result>True</Result><OrderStatus>6</OrderStatus><StatusDesc>Successful</StatusDesc><OrderDetail><Description>demo.com</Description><Status>Successful</Status><Quantity>1</Quantity><OrderStatus>Closed</OrderStatus><ExpiryDatetime>10/12/2027 11:00:00 PM</ExpiryDatetime></OrderDetail></Order>'));
$observedOrderNormalized = EnomApi::normalize('order', $observedOrder);
check($observedOrder->status === 'success' && $observedOrderNormalized['completed'] === true
    && $observedOrderNormalized['provider_order_id'] === '12345' && $observedOrderNormalized['domain'] === 'demo.com'
    && $observedOrderNormalized['term'] === 1, 'actual numeric order header preserves Closed successful detail identity');
$registered = response('GetRegistrationStatus', xml('GETREGISTRATIONSTATUS', '<RegistrationStatus>Registered</RegistrationStatus>'));
check($registered->status === 'success', 'Registered getter works when sandbox omits PurchaseStatus');
check(response('GetRegistrationStatus', xml('GetRegistrationStatus', '<RegistrationStatus>Registered</RegistrationStatus><PurchaseStatus>Unpaid</PurchaseStatus>'))->status === 'unknown', 'explicit unpaid purchase status is not silently ignored');
check(response('GetRegistrationStatus', xml('GetRegistrationStatus', '<RegistrationStatus>Hosted</RegistrationStatus>'))->status === 'unknown', 'Hosted without purchase status cannot prove registration');
check(response('GetRegistrationStatus', xml('GetRegistrationStatus', '<RegistrationStatus>Processing</RegistrationStatus>'))->status === 'pending', 'processing getter remains pending');
check(response('GetRegistrationStatus', xml('GetRegistrationStatus', '<RegistrationStatus>Registered</RegistrationStatus><PurchaseStatus/>'))->status === 'unknown', 'explicit empty purchase status remains unknown');
$observedRenewal = response('GetRenew', xml('GETRENEW', '<auto-renew>0</auto-renew><IDProtectRenew>0</IDProtectRenew>'));
check($observedRenewal->status === 'success' && EnomApi::normalize('renewal', $observedRenewal)['auto_renew'] === false, 'actual auto-renew zero confirms provider renewal disabled');
check(response('GetRenew', xml('GetRenew', '<auto-renew>1</auto-renew>'))->status === 'success'
    && EnomApi::normalize('renewal', response('GetRenew', xml('GetRenew', '<auto-renew>1</auto-renew>')))['auto_renew'] === true, 'auto-renew one remains enabled');
check(response('GetRenew', xml('GetRenew', '<auto-renew>unexpected</auto-renew>'))->status === 'unknown', 'malformed auto-renew flag remains unknown');
check(response('GetRenew', xml('GetRenew', '<auto-renew>0</auto-renew><RenewName>1</RenewName>'))->status === 'unknown', 'conflicting renewal aliases cannot prove disabled');
check(response('GetRenew', xml('GetRenew', '<RenewName>0</RenewName>'))->status === 'success', 'existing documented renewal flag still works');
$emptyDnssecBody = '<DnsSecData><Result><ResponseCode>200</ResponseCode><ResponseMessage>Command completed successfully</ResponseMessage></Result></DnsSecData><DnsSecDataCount>0</DnsSecDataCount><MaxSigLife/><Success>True</Success>';
$emptyDnssec = EnomApi::normalize('dnssec', response('GetDnsSec', xml('GETDNSSEC', $emptyDnssecBody)));
check($emptyDnssec['status'] === 'success' && $emptyDnssec['shape_verified'] === true && $emptyDnssec['dnssec'] === [], 'observed confirmed zero-count DNSSEC contract proves empty records');
$observedDs = '<KeyData><Algorithm>13</Algorithm><Digest>'.str_repeat('AB', 32).'</Digest><DigestType>2</DigestType><KeyTag>27578</KeyTag></KeyData>';
$observedDsBody = str_replace('<DnsSecData>', '<DnsSecData>'.$observedDs, str_replace('<DnsSecDataCount>0</DnsSecDataCount>', '<DnsSecDataCount>1</DnsSecDataCount>', $emptyDnssecBody));
$oneDs = EnomApi::normalize('dnssec', response('GetDnsSec', xml('GetDnsSec', $observedDsBody)));
check($oneDs['status'] === 'success' && $oneDs['shape_verified'] === true && $oneDs['dnssec'] === [
    ['algorithm' => 13, 'digest' => str_repeat('AB', 32), 'digest_type' => 2, 'key_tag' => 27578]], 'observed KeyData shape yields strict typed single DS tuple');
$legacyDsBody = '<Algorithm>13</Algorithm><Digest>'.str_repeat('AB', 32).'</Digest><DigestType>2</DigestType><KeyTag>27578</KeyTag>';
foreach (['False', 'unexpected'] as $failureFlag) {
    $failedLegacyDs = EnomApi::normalize('dnssec', response('GetDnsSec', xml('GetDnsSec', $legacyDsBody.'<Success>'.$failureFlag.'</Success>')));
    check($failedLegacyDs['status'] === 'unknown' && $failedLegacyDs['shape_verified'] === false, 'explicit failed/malformed legacy DS flag cannot confirm a tuple');
}
check(EnomApi::normalize('dnssec', response('GetDnsSec', xml('GetDnsSec', $legacyDsBody.'<Success>True</Success>')))['shape_verified'] === true, 'explicit successful legacy DS flag preserves compatible tuple');
// Repeated KeyData follows the existing DOM parser's list representation. Multi-record provider support is not claimed.
$twoDsBody = str_replace($observedDs, $observedDs.str_replace('27578', '27579', $observedDs), str_replace('<DnsSecDataCount>1</DnsSecDataCount>', '<DnsSecDataCount>2</DnsSecDataCount>', $observedDsBody));
$twoDs = EnomApi::normalize('dnssec', response('GetDnsSec', xml('GetDnsSec', $twoDsBody)));
check($twoDs['status'] === 'success' && count($twoDs['dnssec']) === 2 && $twoDs['dnssec'][1]['key_tag'] === 27579, 'synthetic repeated KeyData is bounded and matches declared count');
$fiftyDs = '';
for ($tag = 1; $tag <= 50; ++$tag) { $fiftyDs .= str_replace('27578', (string) $tag, $observedDs); }
$fiftyDsBody = str_replace($observedDs, $fiftyDs, str_replace('<DnsSecDataCount>1</DnsSecDataCount>', '<DnsSecDataCount>50</DnsSecDataCount>', $observedDsBody));
check(count(EnomApi::normalize('dnssec', response('GetDnsSec', xml('GetDnsSec', $fiftyDsBody)))['dnssec']) === 50, 'synthetic complete list retains all 50 bounded DS tuples');
foreach ([str_replace('<Success>True</Success>', '<Success>False</Success>', $observedDsBody),
    str_replace('<ResponseCode>200</ResponseCode>', '<ResponseCode>555</ResponseCode>', $observedDsBody),
    str_replace('<DnsSecDataCount>1</DnsSecDataCount>', '<DnsSecDataCount>0</DnsSecDataCount>', $observedDsBody),
    str_replace('<DnsSecDataCount>1</DnsSecDataCount>', '<DnsSecDataCount>2</DnsSecDataCount>', $observedDsBody),
    str_replace('<DnsSecDataCount>50</DnsSecDataCount>', '<DnsSecDataCount>51</DnsSecDataCount>', $fiftyDsBody),
    str_replace('<Algorithm>13</Algorithm>', '<Algorithm>99</Algorithm>', $observedDsBody),
    str_replace('<KeyTag>27578</KeyTag>', '<KeyTag>65536</KeyTag>', $observedDsBody),
    str_replace('<DigestType>2</DigestType>', '<DigestType>3</DigestType>', $observedDsBody),
    str_replace(str_repeat('AB', 32), 'not-hex', $observedDsBody),
    str_replace(str_repeat('AB', 32), str_repeat('AB', 20), $observedDsBody),
    str_replace('</KeyData>', '<Unexpected>ignored</Unexpected></KeyData>', $observedDsBody),
    str_replace('<KeyTag>27578</KeyTag>', '<KeyTag>27578</KeyTag><KeyTag>27579</KeyTag>', $observedDsBody)] as $invalidDsBody) {
    $invalidDs = EnomApi::normalize('dnssec', response('GetDnsSec', xml('GetDnsSec', $invalidDsBody)));
    check($invalidDs['status'] === 'unknown' && empty($invalidDs['shape_verified']) && empty($invalidDs['dnssec']), 'malformed or contradictory DS result cannot verify records/absence');
}
foreach ([str_replace('<Success>True</Success>', '<Success>False</Success>', $emptyDnssecBody),
    str_replace('<ResponseCode>200</ResponseCode>', '<ResponseCode>555</ResponseCode>', $emptyDnssecBody),
    str_replace('<DnsSecDataCount>0</DnsSecDataCount>', '<DnsSecDataCount>1</DnsSecDataCount>', $emptyDnssecBody),
    str_replace('</DnsSecData>', '<Record><Digest>synthetic</Digest></Record></DnsSecData>', $emptyDnssecBody),
    $emptyDnssecBody.'<Digest>'.str_repeat('AB', 32).'</Digest>',
    str_replace('<DnsSecDataCount>0</DnsSecDataCount>', '', $emptyDnssecBody)] as $unverifiedDnssec) {
    check(EnomApi::normalize('dnssec', response('GetDnsSec', xml('GetDnsSec', $unverifiedDnssec)))['status'] === 'unknown', 'unconfirmed or contradictory DNSSEC shape cannot prove empty records');
}
echo "Provider contracts: {$checks} checks passed (offline).\n";
