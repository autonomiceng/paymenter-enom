<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';

// No framework bootstrap, credentials, network or database access.
require dirname(__DIR__, 2).'/Enom/Services/DomainManager.php';
require dirname(__DIR__, 2).'/Enom/Services/InputValidationException.php';
require dirname(__DIR__, 2).'/Enom/Services/DomainOperations.php';

use Paymenter\Extensions\Servers\Enom\Services\DomainManager as Manager;

$count = 0;
function check(bool $condition, string $name): void
{
    global $count;
    if (!$condition) { throw new RuntimeException('FAIL: '.$name); }
    $count++;
}
function rejects(string $action, array $payload, string $name): void
{
    try { Manager::validatePayload($action, $payload); }
    catch (InvalidArgumentException) { check(true, $name); return; }
    throw new RuntimeException('FAIL: '.$name);
}
$confirmed = ['confirmed' => '1'];
$ns = Manager::validatePayload('nameservers', $confirmed + ['nameservers' => "NS1.EXAMPLE.COM\nns2.example.com"]);
check($ns === ['nameservers' => ['ns1.example.com', 'ns2.example.com']], 'nameservers canonicalized');
rejects('nameservers', ['nameservers' => ['ns1.example.com', 'ns2.example.com']], 'confirmation required');
rejects('nameservers', $confirmed + ['nameservers' => ['ns1.example.com']], 'cannot clear delegation');
rejects('nameservers', $confirmed + ['nameservers' => ['ns1.example.com', 'ns1.example.com']], 'duplicate nameservers');
rejects('nameservers', $confirmed + ['nameservers' => ['https://ns1.example.com', 'ns2.example.com']], 'URL not nameserver');
rejects('purchase', $confirmed, 'paid action cannot bypass orders');
$contact = ['first_name' => 'Demo', 'last_name' => 'Customer', 'organization_name' => 'Sandbox', 'address1' => '123 Demo Road', 'city' => 'Test', 'postal_code' => '12345', 'country' => 'us', 'email_address' => 'demo@example.com', 'phone' => '+1.5551234567'];
$contacts = Manager::validatePayload('contacts', $confirmed + ['contact_type' => 'registrant', 'contact' => $contact + ['token' => 'private', 'epp' => 'secret']]);
check($contacts['contact_type'] === 'registrant' && $contacts['contact']['country'] === 'US', 'one complete contact canonicalized');
check(!isset($contacts['contact']['token']) && !isset($contacts['contact']['epp']), 'unknown contact fields stripped');
// 0.3: one request may set every role, and addresses at reserved test domains are refused before reaching Enom.
check(Manager::validatePayload('contacts', $confirmed + ['contact_type' => 'all', 'contact' => $contact])['contact_type'] === 'all', 'all-roles contact accepted');
rejects('contacts', $confirmed + ['contact_type' => 'billing', 'contact' => $contact], 'unknown contact role refused');
foreach (['demo@example.invalid', 'demo@example.test', 'demo@host.example', 'demo@machine.local', 'demo@localhost'] as $reserved) {
    $reservedContact = $contact; $reservedContact['email_address'] = $reserved;
    rejects('contacts', $confirmed + ['contact_type' => 'registrant', 'contact' => $reservedContact], 'reserved email domain refused: '.$reserved);
}
foreach (['epp_email', 'epp_sync'] as $noPayload) { check(Manager::validatePayload($noPayload, $confirmed) === [], $noPayload.' carries no payload'); }
foreach (['+1 (555) 234-5678', '+15552345678', '1-555-234-5678', '(555) 234-5678', '5552345678'] as $phone) {
    $browserContact = $contact; $browserContact['phone'] = $phone;
    check(Manager::validatePayload('contacts', $confirmed + ['contact_type' => 'registrant', 'contact' => $browserContact])['contact']['phone'] === '+1.5552345678', 'safe browser US phone normalized: '.$phone);
}
$international = $contact; $international['country'] = 'GB'; $international['phone'] = '+44 (20) 7946-0123';
check(Manager::validatePayload('contacts', $confirmed + ['contact_type' => 'registrant', 'contact' => $international])['contact']['phone'] === '+44.2079460123', 'explicit international code and browser separators normalized');
$international['phone'] = '02079460123';
rejects('contacts', $confirmed + ['contact_type' => 'registrant', 'contact' => $international], 'ambiguous international national number never guesses dialing code');
$international['phone'] = '+44 20 7946 0123 ext 9';
rejects('contacts', $confirmed + ['contact_type' => 'registrant', 'contact' => $international], 'phone extension not silently merged into number');
$individual = $contact; unset($individual['organization_name']);
check(Manager::validatePayload('contacts', $confirmed + ['contact_type' => 'registrant', 'contact' => $individual])['contact']['organization_name'] === '', 'organization optional for individual registrants');
rejects('contacts', $confirmed + ['contact_type' => 'billing', 'contact' => $contact], 'account billing contact excluded');
rejects('contacts', $confirmed + ['contact_type' => 'admin', 'contact' => array_diff_key($contact, ['email_address' => 1])], 'partial contact excluded');
$bad = $contact; $bad['phone'] = '555';
rejects('contacts', $confirmed + ['contact_type' => 'admin', 'contact' => $bad], 'international phone required');
$bad = $contact; $bad['first_name'] = "Demo\nSecret";
rejects('contacts', $confirmed + ['contact_type' => 'admin', 'contact' => $bad], 'control characters rejected');
check(Manager::validatePayload('lock', $confirmed + ['locked' => '0']) === ['locked' => false], 'unlock value stays false');
rejects('lock', $confirmed + ['locked' => 'false'], 'invalid lock value');
check(Manager::validatePayload('epp_sync', $confirmed + ['EmailEPP' => true, 'epp' => 'secret']) === [], 'EPP cannot email or reveal via payload');
$records = [['name' => '@', 'type' => 'A', 'address' => '192.0.2.1'], ['name' => 'mail', 'type' => 'MX', 'address' => 'mail.example.test.', 'mx_pref' => '20']];
$digest = Manager::digest($records);
$hosts = Manager::validatePayload('hosts', $confirmed + ['preimage_digest' => $digest, 'records' => [...$records, ['name' => '', 'type' => '', 'address' => '', 'mx_pref' => '']]]);
check(count($hosts['records']) === 2 && $hosts['records'][1]['mx_pref'] === 20, 'whole DNS list keeps untouched rows and removes blank rows');
check($hosts['records'][0]['mx_pref'] === 10, 'mail preference documented default');
foreach (['A' => '192.0.2.1', 'AAAA' => '2001:db8::1', 'TXT' => 'test', 'CNAME' => 'example.test.', 'URL' => 'https://example.test', 'FRAME' => 'https://example.test', 'MXE' => '192.0.2.1'] as $type => $address) {
    $record = ['name' => '@', 'type' => $type, 'address' => $address, 'mx_pref' => '20'];
    check(Manager::validatePayload('hosts', $confirmed + ['preimage_digest' => $digest, 'records' => [$record]])['records'][0]['mx_pref'] === 10, 'nonMX priority never persists an unsent value: '.$type);
    $actual = $record; $actual['mx_pref'] = 10;
    check(\Paymenter\Extensions\Servers\Enom\Services\DomainOperations::canonicalRecords('hosts', [$actual]) === \Paymenter\Extensions\Servers\Enom\Services\DomainOperations::canonicalRecords('hosts', [$record]), 'nonMX irrelevant priority cannot create uncertain registrar outcome: '.$type);
}
$mxBefore = ['name' => 'mail', 'type' => 'MX', 'address' => 'mail.example.test.', 'mx_pref' => 10];
$mxAfter = $mxBefore; $mxAfter['mx_pref'] = 20;
check(\Paymenter\Extensions\Servers\Enom\Services\DomainOperations::canonicalRecords('hosts', [$mxBefore]) !== \Paymenter\Extensions\Servers\Enom\Services\DomainOperations::canonicalRecords('hosts', [$mxAfter]), 'MX readback still requires exact priority');
rejects('hosts', $confirmed + ['records' => $records], 'DNS preimage required');
rejects('hosts', $confirmed + ['preimage_digest' => $digest, 'records' => []], 'unverified empty-list clearing unavailable');
rejects('hosts', $confirmed + ['preimage_digest' => $digest, 'records' => [['name' => '@', 'type' => 'A', 'address' => 'mail.example.test']]], 'DNS address type checked');
rejects('hosts', $confirmed + ['preimage_digest' => $digest, 'records' => [['name' => 'www', 'type' => 'CNAME', 'address' => 'example.test']]], 'alias trailing dot required');
rejects('hosts', $confirmed + ['preimage_digest' => $digest, 'records' => array_fill(0, 51, $records[0])], '50-record limit');
$delete = Manager::validatePayload('hosts', $confirmed + ['preimage_digest' => $digest, 'records' => [$records[0], $records[1] + ['_delete' => '1']]]);
check(count($delete['records']) === 1, 'explicit selected record deletion');
$srv = [['service' => 'sip', 'protocol' => 'tcp', 'priority' => '10', 'weight' => '5', 'port' => '5060', 'target' => 'sip.example.test.', 'host_id' => '987']];
$validated = Manager::validatePayload('srv', $confirmed + ['preimage_digest' => Manager::digest($srv), 'records' => $srv]);
check($validated['records'][0]['host_id'] === 987 && $validated['records'][0]['port'] === 5060, 'SRV identity preserved and numbers normalized');
$badSrv = $srv; $badSrv[0]['port'] = '65536';
rejects('srv', $confirmed + ['preimage_digest' => $digest, 'records' => $badSrv], 'SRV numeric bound');
$ds = ['algorithm' => '13', 'digest_type' => '2', 'digest' => str_repeat('ab', 32), 'key_tag' => '12345'];
check(Manager::validatePayload('dnssec_add', $confirmed + ['ds' => $ds])['ds']['digest'] === str_repeat('AB', 32), 'DS digest canonicalized');
$badDs = $ds; $badDs['digest'] = str_repeat('a', 40);
rejects('dnssec_delete', $confirmed + ['ds' => $badDs], 'DS tuple digest length');
$badDs = $ds; $badDs['algorithm'] = '15';
rejects('dnssec_add', $confirmed + ['ds' => $badDs], 'unsupported provider algorithm');
$safe = Manager::safeSnapshot(['nameservers' => ['ns1.example.test'], 'epp' => 'private', 'token' => 'private', 'raw_xml' => 'private', 'contacts' => ['registrant' => $contact + ['epp' => 'private']], 'privacy' => ['allowed' => true, 'price' => 'private'], 'dnssec' => [$ds + ['request' => 'private']]]);
check(!str_contains(json_encode($safe), 'private'), 'snapshot excludes credentials, EPP, provider raw and arbitrary fields');
check($safe['contacts']['registrant']['email_address'] === 'demo@example.com', 'authorized contact fields retained');
check(Manager::digest($records) !== Manager::digest(array_reverse($records)), 'DNS preimage detects full list changes');
check(Manager::safeSnapshot(['nameservers' => 'bad', 'contacts' => 'bad', 'hosts' => 'bad'])['hosts'] === [], 'malformed cache shape safely omitted');
$unknownContactStatus = ['pending_verification' => null, 'transfer_locked' => null];
check(Manager::safeSnapshot([])['contact_status'] === $unknownContactStatus, 'absent contact status remains unknown');
check(Manager::safeSnapshot(['contact_status' => ['pending_verification' => true, 'transfer_locked' => true]])['contact_status'] === ['pending_verification' => true, 'transfer_locked' => true], 'pending verification and contact transfer lock preserved');
check(Manager::safeSnapshot(['contact_status' => ['pending_verification' => false, 'transfer_locked' => false]])['contact_status'] === ['pending_verification' => false, 'transfer_locked' => false], 'known false contact status preserved without claiming verification completed');
check(Manager::safeSnapshot(['contact_status' => ['pending_verification' => '<script>secret</script>', 'transfer_locked' => 0]])['contact_status'] === $unknownContactStatus, 'nonboolean contact status stays unknown');
$injectedContactStatus = Manager::safeSnapshot(['contact_status' => ['pending_verification' => true, 'transfer_locked' => null, 'raw_error' => 'private-status-secret', 'epp' => 'private-status-secret']]);
check(!str_contains(json_encode($injectedContactStatus), 'private-status-secret') && count($injectedContactStatus['contact_status']) === 2, 'contact-status injection excluded');
check(Manager::safeSnapshot(['contact_status' => 'private-status-secret'])['contact_status'] === $unknownContactStatus, 'malformed contact status remains unknown');
fwrite(STDOUT, "UI input contracts: {$count} passed\n");
