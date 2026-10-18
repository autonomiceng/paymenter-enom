<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Offline only: environment endpoints, the transfer-code email contract and catalogue response limits.
foreach (['DomainName', 'RegistrarResult', 'RegistrarClient', 'EnomApi'] as $class) {
    require_once __DIR__.'/../../Enom/Api/'.$class.'.php';
}

use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;

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
function xml(string $command, string $body = ''): string {
    return '<interface-response>'.$body.'<Command>'.strtoupper($command).'</Command><ErrCount>0</ErrCount><Done>true</Done></interface-response>';
}
function client(string $body, ?array &$sent = null, string $environment = 'test'): RegistrarClient {
    $sent = [];
    return new RegistrarClient('offline_uid', 'offline_token', function ($command, $params) use ($body, &$sent) { $sent[] = [$command, $params]; return $body; }, null, false, $environment);
}

// Environment: test by default, live on request, anything else refused at construction. Debug output never shows credentials.
$default = new RegistrarClient('offline_uid', 'offline_token', fn () => xml('GetBalance'));
check($default->environment() === 'test', 'Default environment is not test.');
$live = new RegistrarClient('secret_uid', 'secret_token', fn () => xml('GetBalance'), null, true, 'live');
check($live->environment() === 'live' && (new EnomApi($live))->environment() === 'live', 'Live environment not kept.');
ob_start(); var_dump($live); $dump = ob_get_clean();
check(str_contains($dump, '"environment"') && str_contains($dump, '"live"') && !str_contains($dump, 'secret_uid') && !str_contains($dump, 'secret_token'), 'Debug output lacks the environment or leaks credentials.');
check($default->__debugInfo() === ['environment' => 'test', 'injected_transport' => true, 'read_only' => false], 'Test debug info differs.');
foreach (['sandbox', 'staging', 'LIVE', 'production', ''] as $environment) {
    rejects(fn () => new RegistrarClient('u', 't', null, null, false, $environment), 'Environment '.$environment.' was accepted.');
}
$constants = (new ReflectionClass(RegistrarClient::class))->getConstant('ENDPOINTS');
check($constants === ['test' => 'https://resellertest.enom.com/interface.asp', 'live' => 'https://reseller.enom.com/interface.asp'], 'Client endpoints differ from the documented Enom hosts.');

// Transfer code by email: EmailEPP=True is allowed for SynchAuthInfo only, and its EPPEmailMessage answer is success.
$message = '<EPPEmailMessage>The EPP key has been sent to the registrant email address.</EPPEmailMessage><RRPCode>200</RRPCode>';
$api = new EnomApi(client(xml('SynchAuthInfo', $message), $sent));
$emailed = $api->emailTransferCode('Example.com');
check($emailed->status === 'success', 'EPPEmailMessage response was not success: '.$emailed->code);
check($sent === [['SynchAuthInfo', ['SLD' => 'example', 'TLD' => 'com', 'EmailEPP' => 'True', 'RunSynchAutoInfo' => 'True']]], 'Transfer-code email sent unexpected parameters.');
check(client(xml('SynchAuthInfo', '<EPPEmailMessage>  </EPPEmailMessage>'))->execute('SynchAuthInfo', ['SLD' => 'example', 'TLD' => 'com', 'EmailEPP' => 'True'])->code === 'sync_unconfirmed', 'Blank EPPEmailMessage was success.');
check(client(xml('SynchAuthInfo', '<RRPCode>200</RRPCode>'))->execute('SynchAuthInfo', ['SLD' => 'example', 'TLD' => 'com', 'EmailEPP' => 'True'])->code === 'sync_unconfirmed', 'Acknowledgement without a message was success.');
check(client(xml('SynchAuthInfo', '<InfoSynched>True</InfoSynched><RRPCode>200</RRPCode>'))->execute('SynchAuthInfo', ['SLD' => 'example', 'TLD' => 'com', 'EmailEPP' => 'False'])->status === 'success', 'Plain synchronization stopped parsing.');
$blocked = new RegistrarClient('u', 't', fn () => throw new RuntimeException('must not run'));
foreach (['Purchase', 'Contacts', 'TP_CreateOrder', 'GetDomainInfo', 'SetRegLock'] as $command) {
    foreach (['True', 'False'] as $value) {
        rejects(fn () => $blocked->execute($command, ['SLD' => 'example', 'TLD' => 'com', 'EmailEPP' => $value]), 'EmailEPP='.$value.' accepted on '.$command);
    }
}
rejects(fn () => $blocked->execute('SynchAuthInfo', ['SLD' => 'example', 'TLD' => 'com', 'emailepp' => 'True', 'EmailEPP' => 'True']), 'Duplicate EmailEPP spellings accepted.');
check((new RegistrarClient('u', 't', fn () => xml('SynchAuthInfo', $message), null, true))->execute('SynchAuthInfo', ['EmailEPP' => 'True'])->code === 'read_only', 'Read-only client sent a transfer-code email.');

// Catalogue reads list hundreds of endings: larger body and node limits apply to GetTLDList only.
$entries = 30000; // 60,001 elements: over the per-domain node limit, under the catalogue limit.
$tldList = xml('GetTLDList', '<tldlist>'.str_repeat('<tld><tld>com</tld></tld>', $entries).'<tldcount>'.$entries.'</tldcount></tldlist>');
check(strlen($tldList) < 1048576, 'Node-limit fixture unexpectedly exceeds the body limit.');
check(client($tldList)->execute('GetTLDList')->status === 'success', 'Catalogue list over the per-domain node limit was refused.');
check(client(str_replace('GETTLDLIST', 'GETBALANCE', $tldList))->execute('GetBalance')->code === 'invalid_response', 'Per-domain command accepted a catalogue-sized node count.');
$pricing = xml('PE_GetProductPrice', '<productprice><padding>'.str_repeat('x', 1500000).'</padding></productprice>');
check(strlen($pricing) > 1048576 && strlen($pricing) < 8388608, 'Pricing fixture is not between the two body limits.');
check(client($pricing)->execute('PE_GetProductPrice', ['Years' => 1])->code === 'invalid_response', 'Per-term pricing over 1 MiB was accepted.');
check(client(str_replace('PE_GETPRODUCTPRICE', 'GETBALANCE', $pricing))->execute('GetBalance')->code === 'invalid_response', 'Per-domain command accepted a body over 1 MiB.');
$oversized = xml('GetTLDList', '<tldlist><padding>'.str_repeat('x', 8388608).'</padding></tldlist>');
check(client($oversized)->execute('GetTLDList')->code === 'invalid_response', 'Catalogue body over 8 MiB was accepted.');
check(client(xml('GetTLDList', '<!DOCTYPE x [<!ENTITY a "b">]><tldlist/>'))->execute('GetTLDList')->code === 'invalid_response', 'Catalogue response with a DTD was accepted.');
// Catalogue commands are reads; a read-only client may send them, and they carry no domain.
$readOnly = new RegistrarClient('u', 't', fn ($command) => xml($command), null, true);
foreach (['GetTLDList', 'PE_GetProductPrice', 'GetExtAttributes', 'GetTLDDetails', 'GetBalance'] as $command) {
    check($readOnly->execute($command)->code !== 'read_only', $command.' was treated as a write.');
}
check($readOnly->execute('SetRegLock')->code === 'read_only', 'SetRegLock was treated as a read.');


echo 'Registrar client: '.$checks.' checks passed (offline).'.PHP_EOL;
