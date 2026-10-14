<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Real native models/controller/session and middleware; SQLite and injected transports only.
require __DIR__.'/request-gates.php';
$successful = false;
$checks = 0;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController;
use Paymenter\Extensions\Servers\Enom\Http\FormKeys;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Services\DomainManager;
use Paymenter\Extensions\Servers\Enom\Services\DomainOperations;

function typeRequest($domain, string $action, array $input): Request {
    global $app, $user;
    $request = Request::create('http://enom-tests.invalid/domains/'.$domain->id.'/manage/'.$action, 'POST', $input);
    $session = new Store('type-regression', new ArraySessionHandler(60)); $session->start();
    $request->setLaravelSession($session); $request->setUserResolver(fn () => $user);
    $request->request->set('_request_key', FormKeys::issue($request, 'domain:'.$domain->id.':'.$action));
    $app->instance('request', $request); $app->instance('session', $session);
    $app['redirect']->setSession($session); $app['url']->setRequest($request);
    return $request;
}
function submitType(DomainController $controller, Request $request, $domain, string $action) {
    return (new TrimStrings)->handle($request, fn ($request) => (new ConvertEmptyStringsToNull)->handle($request,
        fn ($request) => $controller->manage($request, $domain->id, $action)));
}

$controller = new DomainController($manager, $orders, $operations);
[$domain] = activeFixture();
$original = [['name' => 'proof', 'type' => 'TXT', 'address' => '  proof  ', 'mx_pref' => 10],
    ['name' => '@', 'type' => 'A', 'address' => '192.0.2.1', 'mx_pref' => 10]];
$domain->update(['capabilities' => ['hosts' => true], 'snapshot' => ['hosts' => $original]]);
$desired = $original; $desired[1]['address'] = '192.0.2.2';
$request = typeRequest($domain, 'hosts', ['confirmed' => '1', 'preimage_digest' => DomainManager::digest($original),
    'records' => array_map(fn ($row) => array_map('strval', $row), $desired)]);
submitType($controller, $request, $domain, 'hosts');
$recorded = Operation::where('domain_id', $domain->id)->where('action', 'hosts')->sole();
verify($recorded->payload['records'][0]['address'] === '  proof  ', 'Native middleware/controller silently changed untouched TXT content.');
verify($recorded->payload['records'][1]['address'] === '192.0.2.2' && $providerCalls === 0, 'TXT request did not preserve the other DNS edit or called provider before queue processing.');

$wire = [];
$adapterCommands = [];
$adapter = new EnomApi(new RegistrarClient('offline', 'offline', function ($command, $parameters) use (&$wire, &$adapterCommands) {
    $adapterCommands[] = $command;
    if ($command === 'SetHosts') { $wire = $parameters; }
    $body = $command === 'GetHosts' ? '<host><name>proof</name><type>TXT</type><address>  proof  </address></host><host><name>@</name><type>A</type><address>192.0.2.2</address></host>' : '';
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
}));
$adapter->setHosts('demo.com', $recorded->payload['records']);
verify($wire['Address1'] === '  proof  ', 'Provider transport stripped intended TXT bytes.');
$readback = EnomApi::normalize('hosts', $adapter->hosts('demo.com'));
verify($readback['hosts'][0]['address'] === '  proof  ', 'Registrar XML parsing stripped TXT readback bytes.');
verify(DomainOperations::canonicalRecords('hosts', $readback['hosts']) === DomainOperations::canonicalRecords('hosts', $recorded->payload['records']), 'Equal complete TXT readback cannot confirm the queued request.');
foreach (["bad\ntext", "bad\0text", "bad\x7ftext"] as $invalidText) {
    $invalid = $desired; $invalid[0]['address'] = $invalidText;
    $request = typeRequest($domain, 'hosts', ['confirmed' => '1', 'preimage_digest' => DomainManager::digest($original),
        'records' => array_map(fn ($row) => array_map('strval', $row), $invalid)]);
    $key = $request->input('_request_key'); $before = allRows();
    submitType($controller, $request, $domain, 'hosts');
    verify(allRows() == $before && $request->session()->get('errors')->any(), 'Raw TXT control bytes passed the native controller into the operation queue.');
    verify(FormKeys::verify($request, 'domain:'.$domain->id.':hosts') === $key
        && $request->session()->get('_old_input.records.0.address') === $invalidText, 'Invalid TXT retry lost its ordinary input or original form key.');
}
foreach ([['POST', '/profile'], ['POST', '/domains/1/manage/contacts'], ['POST', '/domains/1/manage/hosts-extra'], ['GET', '/domains/1/manage/hosts']] as [$method, $path]) {
    $native = Request::create('http://enom-tests.invalid'.$path, $method, ['ordinary' => '  native  ']);
    (new TrimStrings)->handle($native, fn ($request) => $request);
    verify($native->input('ordinary') === 'native', 'TXT preservation changed trimming outside the exact POST hosts action.');
}
[$nsDomain] = activeFixture(); $nsDomain->update(['capabilities' => ['nameservers' => true]]);
foreach ([['ns1.example.com', 'NS1.EXAMPLE.COM.'], ['192.0.2.1', 'ns2.example.com'],
    ['192.0.2.1.', 'ns2.example.com'], ['ns1.example.com..', 'ns2.example.com']] as $invalidServers) {
    $entered = implode("\n", $invalidServers);
    $request = typeRequest($nsDomain, 'nameservers', ['confirmed' => '1', 'nameservers' => $entered]);
    $key = $request->input('_request_key'); $before = allRows();
    submitType($controller, $request, $nsDomain, 'nameservers');
    verify(allRows() == $before && $request->session()->get('errors')->any(), 'Invalid canonical nameservers reached the controller operation writer.');
    verify($request->session()->get('_old_input.nameservers') === $entered
        && FormKeys::verify($request, 'domain:'.$nsDomain->id.':nameservers') === $key, 'Invalid nameserver retry lost entered text or original form key.');
    $beforeCommands = $adapterCommands;
    try { $adapter->setNameservers('demo.com', $invalidServers); verify(false, 'Invalid canonical nameservers reached the adapter transport.'); }
    catch (InvalidArgumentException) { verify($adapterCommands === $beforeCommands, 'Invalid nameservers called transport before rejection.'); }
}
$request = typeRequest($nsDomain, 'nameservers', ['confirmed' => '1', 'nameservers' => "NS1.EXAMPLE.COM.\nns2.example.com."]);
submitType($controller, $request, $nsDomain, 'nameservers');
$nsOperation = Operation::where('domain_id', $nsDomain->id)->where('action', 'nameservers')->sole();
verify($nsOperation->payload['nameservers'] === ['ns1.example.com', 'ns2.example.com'], 'Single terminal dots and case do not canonicalize before queueing.');

[$srvDomain] = activeFixture();
$srv = [['service' => 'sip', 'protocol' => 'tcp', 'priority' => '10', 'weight' => '5', 'port' => '5060', 'target' => 'sip.example.com.', 'host_id' => '987']];
$srvDomain->update(['capabilities' => ['srv' => true], 'snapshot' => ['srv' => $srv]]);
$enteredSrv = $srv; $enteredSrv[0]['protocol'] = 'UDP';
$request = typeRequest($srvDomain, 'srv', ['confirmed' => '1', 'preimage_digest' => DomainManager::digest($srv), 'records' => $enteredSrv]);
submitType($controller, $request, $srvDomain, 'srv');
$srvOperation = Operation::where('domain_id', $srvDomain->id)->where('action', 'srv')->first();
verify($srvOperation && $srvOperation->payload['records'][0]['protocol'] === 'udp' && $srvOperation->payload['records'][0]['host_id'] === 987, 'Documented uppercase SRV protocol does not normalize while retaining existing record identity.');
$badSrv = $enteredSrv; $badSrv[0]['protocol'] = 'icmp';
$request = typeRequest($srvDomain, 'srv', ['confirmed' => '1', 'preimage_digest' => DomainManager::digest($srv), 'records' => $badSrv]);
$key = $request->input('_request_key'); $before = allRows();
submitType($controller, $request, $srvDomain, 'srv');
verify(allRows() == $before && $request->session()->get('errors')->has('records.0.protocol')
    && $request->session()->get('_old_input.records.0.protocol') === 'icmp'
    && FormKeys::verify($request, 'domain:'.$srvDomain->id.':srv') === $key, 'Invalid protocol did not retain a field error, entered row and original key before queueing.');
$snapshot = $manager->snapshot($user, $srvDomain->id);
$html = $app['view']->make('enom::partials.dns', ['domainId' => $srvDomain->id, 'snapshot' => $snapshot,
    'cached' => $snapshot['cached'], 'available' => fn ($action) => $action === 'srv',
    'keys' => ['srv' => $key], 'errors' => $request->session()->get('errors')])->render();
$document = new DOMDocument; @$document->loadHTML($html); $xpath = new DOMXPath($document);
$select = $xpath->query('//select[@name="records[0][protocol]"]')->item(0);
verify($select && $select->getAttribute('aria-invalid') === 'true'
    && $select->getAttribute('aria-describedby') === 'srv-0-protocol-error'
    && $xpath->query('//*[@id="srv-0-protocol-error"]')->length === 1, 'Protocol editor lacks an explicit accessible select with its own validation error.');
verify($xpath->query('//select[@name="records[0][protocol]"]/option[@value="tcp"]')->length === 1
    && $xpath->query('//select[@name="records[0][protocol]"]/option[@value="udp"]')->length === 1, 'Protocol editor does not expose the two supported choices.');

echo 'UI type regressions: '.$checks.' passed; native middleware/controller, SQLite, no provider calls.'.PHP_EOL;
$successful = true;
