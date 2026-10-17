<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Offline only: retained public contract, injected XML, no framework or network.
foreach (['DomainName', 'RegistrarResult', 'RegistrarClient'] as $class) {
    require_once __DIR__.'/../../Enom/Api/'.$class.'.php';
}
require_once __DIR__.'/../../Enom/Services/RegistrarMoney.php';

use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;
use Paymenter\Extensions\Servers\Enom\Services\RegistrarMoney;

$checks = 0;
function verifyMoney(bool $passed, string $message): void {
    global $checks;
    ++$checks;
    if (!$passed) { throw new RuntimeException($message); }
}
// Escalate warnings so malformed typed evidence cannot pass silently.
set_error_handler(static function ($severity, $message, $file, $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// GetOrderDetail official XML example, public getorderdetail documentation.
$xml = <<<'XML'
<interface-response><Order><OrderID>900000001</OrderID><Result>true</Result>
<BillDate>11/7/2011 5:40:05 AM</BillDate><OrderBillAmount>$8.95</OrderBillAmount>
<Certs>false</Certs><OrderStatus>6</OrderStatus><StatusDesc>Successful</StatusDesc>
<OrderDetail><ProductType>Register</ProductType><Description>testnum10.com</Description>
<Status>Successful</Status><Quantity>1</Quantity><AmountPaid>8.95</AmountPaid>
<OrderStatus>Closed</OrderStatus><ExpiryDatetime>11/7/2012 5:37:00 AM</ExpiryDatetime>
</OrderDetail></Order><Command>GETORDERDETAIL</Command><ErrCount>0</ErrCount><Done>true</Done></interface-response>
XML;
$client = new RegistrarClient('offline', 'offline', static fn () => $xml, null, true);
$order = $client->execute('GetOrderDetail', ['OrderID' => '900000001']);
$binding = ['provider_order_id' => '900000001', 'domain' => 'testnum10.com', 'action' => 'register', 'term' => 1];
$execution = new RegistrarResult('success', null, ['command' => 'Purchase', 'orderid' => '900000001']);
$confirmed = ['state' => 'confirmed', 'source' => 'GetOrderDetail', 'provider_order_id' => '900000001',
    'domain' => 'testnum10.com', 'action' => 'register', 'term' => 1, 'amount' => '8.95',
    'currency' => 'USD', 'currency_source' => 'documented_orderbillamount_usd'];
$unknown = ['state' => 'unconfirmed', 'source' => 'GetOrderDetail', 'amount' => null, 'currency' => null];
verifyMoney($order->status === 'success', 'Published XML did not parse as a successful order.');
verifyMoney(RegistrarMoney::actualCost($order, $binding, $execution) === $confirmed, 'Published Register/$8.95 order lost exact confirmed cost.');

// Header and per-item fields must agree independently; no allocation or rounding.
foreach (['foreign_order', 'false_membership', 'missing_item', 'second_item', 'wrong_product', 'wrong_domain',
    'wrong_term', 'item_pending', 'item_failed', 'unbilled', 'header_currency', 'item_currency', 'null_currency',
    'blank_currency', 'different_total', 'decimal_overprecision', 'exponent', 'negative', 'comma', 'double_dollar',
    'item_dollar', 'array_total', 'array_description', 'array_product', 'array_status', 'array_membership'] as $fault) {
    $data = $order->data;
    $item = &$data['order']['orderdetail'];
    switch ($fault) {
        case 'foreign_order': $data['order']['orderid'] = '157770752'; break;
        case 'false_membership': $data['order']['result'] = 'false'; break;
        case 'missing_item': unset($data['order']['orderdetail']); break;
        case 'second_item': $data['order']['orderdetail'] = [$item, $item]; break;
        case 'wrong_product': $item['producttype'] = 'Domain'; break;
        case 'wrong_domain': $item['description'] = 'foreign.com'; break;
        case 'wrong_term': $item['quantity'] = '2'; break;
        case 'item_pending': $item['orderstatus'] = 'Open'; break;
        case 'item_failed': $item['status'] = 'Failed'; break;
        case 'unbilled': $item['amountpaid'] = 'Not billed'; break;
        case 'header_currency': $data['order']['currency'] = 'CAD'; break;
        case 'item_currency': $item['currency'] = 'EUR'; break;
        case 'null_currency': $item['currency'] = null; break;
        case 'blank_currency': $data['order']['currency'] = ''; break;
        case 'different_total': $data['order']['orderbillamount'] = '$9.95'; break;
        case 'decimal_overprecision': $data['order']['orderbillamount'] = '$8.950'; break;
        case 'exponent': $data['order']['orderbillamount'] = '$8.95e0'; break;
        case 'negative': $data['order']['orderbillamount'] = '-$8.95'; break;
        case 'comma': $data['order']['orderbillamount'] = '$1,008.95'; break;
        case 'double_dollar': $data['order']['orderbillamount'] = '$$8.95'; break;
        case 'item_dollar': $item['amountpaid'] = '$8.95'; break;
        case 'array_total': $data['order']['orderbillamount'] = ['$8.95', '$8.95']; break;
        case 'array_description': $item['description'] = ['testnum10.com', 'testnum10.com']; break;
        case 'array_product': $item['producttype'] = ['Register', 'Register']; break;
        case 'array_status': $item['status'] = ['Successful', 'Successful']; break;
        case 'array_membership': $data['order']['result'] = ['true', 'true']; break;
    }
    unset($item);
    verifyMoney(RegistrarMoney::actualCost(new RegistrarResult('success', null, $data), $binding, $execution) === $unknown, 'Ambiguous order cost confirmed: '.$fault);
}
foreach ([null, new RegistrarResult('pending', null, $execution->data),
    new RegistrarResult('unknown', null, $execution->data),
    new RegistrarResult('success', null, ['command' => 'Extend', 'orderid' => '900000001']),
    new RegistrarResult('success', null, ['command' => ['Purchase'], 'orderid' => '900000001']),
    new RegistrarResult('success', null, ['command' => 'Purchase', 'orderid' => '157770752'])] as $candidate) {
    verifyMoney(RegistrarMoney::actualCost($order, $binding, $candidate) === $unknown, 'Unbound execution confirmed cost.');
}
foreach ([['provider_command' => 'Extend'], ['provider_order_id' => 900000001], ['term' => '1'],
    ['term' => 0], ['term' => 11], ['domain' => ['testnum10.com']], ['action' => 'transfer']] as $fault) {
    verifyMoney(RegistrarMoney::actualCost($order, array_replace($binding, $fault), $execution) === $unknown, 'Invalid captured binding confirmed cost.');
}
foreach (['success', 'pending', 'unknown'] as $state) {
    $candidate = new RegistrarResult($state, null, $order->data);
    verifyMoney(RegistrarMoney::actualCost($candidate, $binding, $execution) === ($state === 'success' ? $confirmed : $unknown), 'Non-success order confirmed cost.');
}
// An Extend renewal must match a single Renew order item for the same domain and term.
// Other action/product combinations and expired commands stay unsupported.
foreach (['Domain', 'Register', 'Renew', 'Extend'] as $type) {
    $data = $order->data; $data['order']['orderdetail']['producttype'] = $type;
    $renewBinding = array_replace($binding, ['action' => 'renew', 'provider_command' => 'Extend']);
    $renewExecution = new RegistrarResult('success', null, ['command' => 'Extend', 'orderid' => '900000001']);
    $expected = $type === 'Renew' ? array_replace($confirmed, ['action' => 'renew']) : $unknown;
    verifyMoney(RegistrarMoney::actualCost(new RegistrarResult('success', null, $data), $renewBinding, $renewExecution) === $expected, 'Renewal action/product cost contract disagrees: '.$type);
}
$renewData = $order->data; $renewData['order']['orderdetail']['producttype'] = 'Renew';
foreach ([['provider_command' => 'UpdateExpiredDomains'], ['provider_command' => 'Purchase'], ['provider_command' => 'extend'],
    ['provider_order_id' => '157770752'], ['term' => 2], ['term' => '1'], ['domain' => 'foreign.com']] as $fault) {
    verifyMoney(RegistrarMoney::actualCost(new RegistrarResult('success', null, $renewData), array_replace($renewBinding, $fault), $renewExecution) === $unknown,
        'Invalid renewal cost binding was accepted.');
}
foreach ([[], ['currency' => ''], ['currency' => 'USD']] as $prices) {
    verifyMoney(RegistrarMoney::checkPriceCurrency($prices) === 'USD', 'Documented Check USD default lost.');
}
foreach ([null, ['currency' => null], ['currency' => []], ['currency' => 'unknown'], ['currency' => ' US dollars ']] as $prices) {
    verifyMoney(RegistrarMoney::checkPriceCurrency($prices) === null, 'Malformed currency became USD.');
}
verifyMoney(RegistrarMoney::checkPriceCurrency(['currency' => 'CAD']) === 'CAD', 'Explicit foreign currency silently became USD.');
$expiredExecution = new RegistrarResult('pending', null, ['command' => 'UpdateExpiredDomains',
    'reactivatedomainname' => ['status' => 'True', 'orderid' => '900000001']]);
verifyMoney(RegistrarMoney::actualCost($order, array_replace($binding, ['action' => 'renew', 'provider_command' => 'UpdateExpiredDomains']), $expiredExecution) === $unknown,
    'Expired-renewal acknowledgement invented an order-item contract.');
verifyMoney(RegistrarMoney::decimal('$8.95') === null, 'Order dollar decoration leaked into general quote parsing.');
// Date-only provider pricing must be corroborated by precise authenticated expiry.
$dateBinding=['domain'=>'calendar-fixture.com','provider_domain_id'=>'456','base_expires_at'=>'2028-10-13','term'=>1,'observed_at'=>'2026-10-14T01:07:00Z'];
$dateExtend=new RegistrarResult('success',null,['expiration'=>'10/12/2028','price'=>'8.95','registrarhold'=>'False','maxextension'=>'8']);
$dateCheck=new RegistrarResult('success',null,['domains'=>['domain'=>['name'=>'calendar-fixture.com','rrpcode'=>'211','ispremium'=>'False','isplatinum'=>'False','iseap'=>'False','prices'=>['currency'=>'','renewal'=>'8.95']]]]);
$dateInfo=new RegistrarResult('success',null,['getdomaininfo'=>['domainname'=>['@attributes'=>['domainnameid'=>'456'],'#text'=>'calendar-fixture.com'],
    'status'=>['expiration'=>'10/12/2028 11:14:00 PM','belongs-to'=>'offline-account']]]);
verifyMoney(RegistrarMoney::renewalQuote($dateExtend,$dateBinding,$dateCheck)['complete']===false,'Four-argument quote invented missing precision.');
$dateQuote=RegistrarMoney::renewalQuote($dateExtend,$dateBinding,$dateCheck,null,$dateInfo);
verifyMoney($dateQuote['complete']&&$dateQuote['base_expires_at']==='2028-10-13'&&$dateQuote['amount']==='8.95'&&$dateQuote['currency']==='USD'&&$dateQuote['command']==='Extend'
    &&$dateQuote['expiry_source']==='GetDomainInfo_precise_la_date_reconciled'&&$dateQuote['expires_at']==='2028-10-13T06:14:00.000000+00:00',
    'Exact provider local-date/UTC precision relation was refused.');
verifyMoney(RegistrarMoney::renewalExpiry($dateExtend,$dateBinding,$dateInfo)->format(DATE_ATOM)==='2028-10-13T06:14:00+00:00','Reconciliation fabricated or truncated authenticated precision.');
foreach(['domain','id','missing_id','binding_id','binding_day','date_only_info','wrong_local_day','malformed_info','malformed_date','extend_timestamp','iso_date_only','extend_conflict','info_conflict','fraction_conflict','pending_info'] as $fault){
    $candidateBinding=$dateBinding;$candidateInfo=$dateInfo->data;$candidateExtend=$dateExtend->data;$state='success';
    if($fault==='domain')$candidateInfo['getdomaininfo']['domainname']['#text']='foreign.com';
    if($fault==='id')$candidateInfo['getdomaininfo']['domainname']['@attributes']['domainnameid']='457';
    if($fault==='missing_id')unset($candidateInfo['getdomaininfo']['domainname']['@attributes']['domainnameid']);
    if($fault==='binding_id')unset($candidateBinding['provider_domain_id']);
    if($fault==='binding_day')$candidateBinding['base_expires_at']='2028-10-14';
    if($fault==='date_only_info')$candidateInfo['getdomaininfo']['status']['expiration']='10/13/2028';
    if($fault==='wrong_local_day')$candidateInfo['getdomaininfo']['status']['expiration']='10/13/2028 12:00:00 AM';
    if($fault==='malformed_info')$candidateInfo['getdomaininfo']['status']['expiration']='invalid';
    if($fault==='malformed_date')$candidateExtend['expiration']='02/30/2028';
    if($fault==='extend_timestamp')$candidateExtend['expiration']='10/12/2028 12:00:00 AM';
    if($fault==='iso_date_only')$candidateExtend['expiration']='2028-10-12';
    if($fault==='extend_conflict')$candidateExtend['registryexpdate']='2028-10-12T08:00:00Z';
    if($fault==='info_conflict')$candidateInfo['registryexpdate']='2028-10-13T05:00:00Z';
    if($fault==='fraction_conflict')$candidateInfo['registryexpdate']='2028-10-13T06:14:00.000001Z';
    if($fault==='pending_info')$state='pending';
    verifyMoney(!RegistrarMoney::renewalQuote(new RegistrarResult('success',null,$candidateExtend),$candidateBinding,$dateCheck,null,new RegistrarResult($state,null,$candidateInfo))['complete'],
        'Unsupported precise date reconciliation accepted: '.$fault);
}
foreach(['2028-10-13T00:00:00Z','2028-10-13T06:14:00Z','2028-10-13T06:59:59.999999Z'] as $instant){
    $utcInfo=$dateInfo->data;$utcInfo['getdomaininfo']['status']['expiration']=['@attributes'=>['utc'=>$instant],'#text'=>'10/12/2028 11:14:00 PM'];
    verifyMoney(!RegistrarMoney::renewalQuote($dateExtend,$dateBinding,$dateCheck,null,new RegistrarResult('success',null,$utcInfo))['complete'],
        'Unobserved UTC attribute shape passed scalar-only reconciliation.');
}
$strictExtend=new RegistrarResult('success',null,array_replace($dateExtend->data,['expiration'=>'2028-10-13']));
$strictQuote=RegistrarMoney::renewalQuote($strictExtend,$dateBinding,$dateCheck);
verifyMoney($strictQuote['complete']&&!array_key_exists('expiry_source',$strictQuote)&&!array_key_exists('expires_at',$strictQuote),
    'Strict legacy quote acquired invented reconciliation provenance.');
restore_error_handler();
echo json_encode(['status' => 'ok', 'checks' => $checks, 'provider' => 'synthetic-read-only', 'database' => 'none']).PHP_EOL;
