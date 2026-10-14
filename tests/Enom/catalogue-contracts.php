<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// Offline only. Sanitized, trimmed captures of Enom TEST API responses (tests/fixtures/enom-test-api)
// pass through the real RegistrarClient parser. No framework, credentials, database or provider requests.
spl_autoload_register(function (string $class): void {
    $prefix = 'Paymenter\\Extensions\\Servers\\Enom\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../../Enom/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) { require_once $path; }
    }
});

use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException('Failed: '.$message); }
}
function fixture(string $name): string {
    $body = file_get_contents(dirname(__DIR__).'/fixtures/enom-test-api/'.$name);
    check(is_string($body) && !preg_match('/<TrackingKey>[^<]|<RequestDateTime>[^<]/', $body), 'Fixture '.$name.' is sanitized.');
    return $body;
}
function parsed(string $command, string $body, array $parameters = []): RegistrarResult {
    $sent = [];
    $result = (new RegistrarClient('offline_uid', 'offline_token', function ($name, $params) use ($body, &$sent) { $sent[] = [$name, $params]; return $body; }, null, true))
        ->execute($command, $parameters);
    check(count($sent) === 1 && $sent[0][0] === $command, $command.' reached the injected transport exactly once.');
    return $result;
}

// GetTLDList: unique lowercase endings, multi-label endings kept, the blank entry Enom returns is skipped.
$list = parsed('GetTLDList', fixture('enom-gettldlist.xml'));
check($list->status === 'success', 'Captured GetTLDList parses as success.');
$names = Catalogue::tldNames($list);
check($names === ['com', 'net', 'org', 'us', 'co.uk', 'ai', 'io', 'tm', 'br.com'], 'GetTLDList endings were not extracted in response order: '.json_encode($names));
check(!in_array('', Catalogue::tldNames($list), true), 'Blank ending entry was imported.');
$hostile = new RegistrarResult('success', null, ['tldlist' => ['tld' => [['tld' => 'COM'], ['tld' => ' com '], ['tld' => 'bad tld'], ['tld' => '-x'], ['tld' => 'xn--p1ai'], ['tld' => '../etc'], ['tld' => ['nested' => 'org']]], 'tldcount' => '7']]);
// Hyphenated (IDN) endings are outside the ending pattern, so they never enter the catalogue.
check(Catalogue::tldNames($hostile) === ['com'], 'Malformed, duplicate or IDN endings were accepted: '.json_encode(Catalogue::tldNames($hostile)));
check(Catalogue::tldNames(new RegistrarResult('success', null, [])) === [], 'Missing list produced endings.');

// PE_GetDomainPricing: reseller cost per action, null when Enom disables that action, cents normalized.
$pricing = parsed('PE_GetDomainPricing', fixture('enom-pe-getdomainpricing.xml'), ['Years' => 1]);
check($pricing->status === 'success', 'Captured PE_GetDomainPricing parses as success.');
$costs = Catalogue::costs($pricing);
check(array_keys($costs) === ['com', 'net', 'org', 'us', 'co.uk', 'io', 'tm', 'br.com'] && !isset($costs['ai']), 'Pricing endings differ from the capture (ai is absent from Enom pricing).');
check($costs['com'] === ['register' => '8.95', 'renew' => '8.95', 'transfer' => '9.50'], '.com costs (transfer 9.5 => 9.50) were misread: '.json_encode($costs['com']));
check($costs['net'] === ['register' => '15.55', 'renew' => '8.95', 'transfer' => '8.95'], '.net costs misread.');
check($costs['co.uk'] === ['register' => '6.99', 'renew' => '6.99', 'transfer' => '6.99'], 'Multi-label .co.uk costs misread.');
check($costs['io'] === ['register' => '39.50', 'renew' => '39.50', 'transfer' => null], 'Disabled .io transfer was given a cost.');
check($costs['tm'] === ['register' => '99.00', 'renew' => null, 'transfer' => null], 'Disabled .tm renew/transfer were given costs.');
check($costs['br.com'] === ['register' => null, 'renew' => '49.95', 'transfer' => null], 'Disabled .br.com registration was given a cost.');
$single = new RegistrarResult('success', null, ['pricestructure' => ['product' => ['tld' => 'COM', 'resellerpricereg' => '8.955', 'registerenabled' => 'true',
    'resellerpricerenew' => '-1', 'renewenabled' => 'True', 'resellerpricetran' => '1e3', 'transferenabled' => 'True']]]);
check(Catalogue::costs($single) === ['com' => ['register' => '8.96', 'renew' => null, 'transfer' => null]], 'Singleton product, half-up cents or invalid amounts mishandled: '.json_encode(Catalogue::costs($single)));
check(Catalogue::costs(new RegistrarResult('success', null, ['pricestructure' => ['product' => [['tld' => 'bad tld', 'resellerpricereg' => '1', 'registerenabled' => 'True'], 'junk']]])) === [], 'Malformed products produced costs.');

// GetExtAttributes: .us has a required nexus attribute; .com has none.
$us = parsed('GetExtAttributes', fixture('enom-getextattributes-us.xml'), ['TLD' => 'us']);
check($us->status === 'success' && Catalogue::requiresExtraDetails($us), '.us nexus attribute was not detected as required.');
$com = parsed('GetExtAttributes', fixture('enom-getextattributes-com.xml'), ['TLD' => 'com']);
check($com->status === 'success' && !Catalogue::requiresExtraDetails($com), '.com was treated as needing extra registrant details.');
$optional = new RegistrarResult('success', null, ['attributes' => ['attribute' => ['name' => 'optional', 'required' => '0']]]);
check(!Catalogue::requiresExtraDetails($optional), 'Optional singleton attribute was treated as required.');
check(Catalogue::requiresExtraDetails(new RegistrarResult('success', null, ['attributes' => ['attribute' => ['name' => 'nexus', 'required' => '1']]])), 'Required singleton attribute was missed.');
check(Catalogue::requiresExtraDetails(new RegistrarResult('success', null, ['attributes' => ['attribute' => [['required' => '2'], ['required' => '1']]]])), 'Required attribute later in a list was missed.');

// GetTLDDetails: the minimum registration term Catalogue::enable() reads.
$ai = parsed('GetTLDDetails', fixture('enom-gettlddetails-ai.xml'), ['TLD' => 'ai']);
check($ai->status === 'success' && $ai->value('tlds.tld.registration.minimum') === '2', '.ai two-year minimum was not readable at the path enable() uses.');
$comDetails = parsed('GetTLDDetails', fixture('enom-gettlddetails-com.xml'), ['TLD' => 'com']);
check($comDetails->status === 'success' && $comDetails->value('tlds.tld.registration.minimum') === '1', '.com one-year minimum was not readable.');

// GetBalance (synthetic balances) parses; the setup page reads AvailableBalance.
$balance = parsed('GetBalance', fixture('enom-getbalance.xml'));
check($balance->status === 'success' && $balance->value('availablebalance') === '1,234.56', 'GetBalance capture did not parse.');

// EnomApi sends the catalogue commands with the documented parameters only.
$sent = [];
$api = new EnomApi(new RegistrarClient('offline_uid', 'offline_token', function ($command, $params) use (&$sent) { $sent[] = [$command, $params]; return fixture('enom-getextattributes-com.xml'); }, null, true));
$api->tldList(); $api->domainPricing(); $api->tldExtendedAttributes('US'); $api->tldInfo('AI');
check($sent === [['GetTLDList', []], ['PE_GetDomainPricing', ['Years' => 1]], ['GetExtAttributes', ['TLD' => 'us']], ['GetTLDDetails', ['TLD' => 'ai']]], 'Catalogue commands sent unexpected parameters: '.json_encode($sent));

// Retail: fixed amount markup adds cents; percent is a percentage of cost; rounding is half-up to the cent.
$retail = [
    ['8.95', 'markup_amount', '5', '13.95'], ['8.95', 'markup_amount', '0.05', '9.00'], ['0', 'markup_amount', '1.99', '1.99'],
    ['10.00', 'markup_percent', '30', '13.00'], ['8.95', 'markup_percent', '30', '11.64'],   // 11.635 rounds up
    ['8.95', 'markup_percent', '25.5', '11.23'], ['0.01', 'markup_percent', '50', '0.02'],   // 0.015 rounds up
    ['0.01', 'markup_percent', '49.99', '0.01'], ['15.55', 'markup_percent', '0', '15.55'], ['99.99', 'markup_percent', '100', '199.98'],
    ['1234567.89', 'markup_percent', '10', '1358024.68'],
];
foreach ($retail as [$cost, $mode, $markup, $expected]) {
    check(Catalogue::retail($cost, $mode, $markup) === $expected, "retail($cost, $mode, $markup) = ".Catalogue::retail($cost, $mode, $markup)." not $expected");
}
foreach ([['8.95', 'fixed', '5'], ['8.95', 'markup_percent', '-1'], ['8.955', 'markup_amount', '1'], ['abc', 'markup_amount', '1'], ['8.95', 'gross_margin', '10']] as [$cost, $mode, $markup]) {
    try { Catalogue::retail($cost, $mode, $markup); check(false, "retail($cost, $mode, $markup) was accepted."); }
    catch (InvalidArgumentException) { check(true, 'Invalid retail input refused.'); }
}

echo 'Catalogue contracts: '.$checks.' checks passed (sanitized TEST API captures, offline).'.PHP_EOL;
