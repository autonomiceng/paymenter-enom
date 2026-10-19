<?php
require_once __DIR__.'/../../Enom/Services/Rdap.php';
use Paymenter\Extensions\Servers\Enom\Services\Rdap;

$checks = 0;
function verify(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
$completedStatus = new ReflectionMethod(Rdap::class, 'completedStatus');
verify($completedStatus->invoke(null, false, CURLE_RECV_ERROR, 404) === 404, 'Unclean close lost the completed 404 status.');
foreach ([0, 200, 404, 503] as $status) {
    verify($completedStatus->invoke(null, true, CURLE_OK, $status) === $status, 'Successful transport changed its status: '.$status);
}
foreach ([[CURLE_RECV_ERROR, 200], [CURLE_RECV_ERROR, 0], [CURLE_RECV_ERROR, 503],
    [CURLE_OPERATION_TIMEDOUT, 404], [CURLE_PARTIAL_FILE, 404], [CURLE_WRITE_ERROR, 404], [CURLE_OK, 404]] as [$errno, $status]) {
    verify($completedStatus->invoke(null, false, $errno, $status) === null, 'Transport failure accepted: '.$errno.' / '.$status);
}
$now = new DateTimeImmutable('2026-10-17T12:00:00Z');
$body = ['objectClassName' => 'domain', 'ldhName' => 'example.com', 'status' => ['active'], 'events' => [
    ['eventAction' => 'registration', 'eventDate' => '2020-01-01T00:00:00Z'],
    ['eventAction' => 'expiration', 'eventDate' => '2027-01-01T00:00:00Z'],
]];
function lookup(array $body, ?DateTimeImmutable $now = null, int $created = 60, int $transferred = 60): array {
    global $requests;
    $requests = [];
    $rdap = new Rdap(function ($url) use ($body, &$requests) {
        $requests[] = $url;
        return ['status' => 200, 'body' => json_encode(str_contains($url, '/dns.json')
            ? ['services' => [[['com'], ['https://rdap.example.com/']]]] : $body)];
    });
    return $rdap->precheck('example.com', $created, $transferred, $now ?? new DateTimeImmutable('2026-10-17T12:00:00Z'));
}
$result = lookup($body);
verify($result === ['allowed' => true, 'code' => null, 'warning' => null, 'expires_at' => '2027-01-01'], 'Valid lookup failed.');
verify($requests === ['https://data.iana.org/rdap/dns.json', 'https://rdap.example.com/domain/example.com'], 'Lookup ignored the bootstrap/name endpoint.');
$compound = $body; $compound['ldhName'] = 'example.co.nz';
$compoundRequests = [];
$compoundRdap = new Rdap(function ($url) use ($compound, &$compoundRequests) {
    $compoundRequests[] = $url;
    return ['status' => 200, 'body' => json_encode(str_contains($url, '/dns.json') ? ['services' => [[['nz'], ['https://rdap.example.com/']]]] : $compound)];
});
verify($compoundRdap->precheck('example.co.nz', 60, 60, $now)['warning'] === null
    && $compoundRequests[1] === 'https://rdap.example.com/domain/example.co.nz', 'Compound ending did not use its IANA bootstrap endpoint.');
foreach (['clientTransferProhibited' => 'transfer_locked', 'server transfer prohibited' => 'transfer_locked', 'redemptionPeriod' => 'transfer_redemption',
    'pending delete' => 'transfer_pending_delete', 'pendingTransfer' => 'transfer_pending', 'CLIENT_TRANSFER_PROHIBITED' => 'transfer_locked'] as $status => $code) {
    $candidate = $body; $candidate['status'] = [$status];
    verify(lookup($candidate)['code'] === $code && !lookup($candidate)['allowed'], 'Lock status not normalized: '.$status);
}
foreach (['registration' => 'transfer_creation_lock', 'transfer' => 'transfer_previous_lock'] as $action => $code) {
    foreach ([60, 30] as $days) {
        foreach ([-1, 0, 1] as $seconds) {
            $date = $now->modify('-'.$days.' days')->modify(($seconds >= 0 ? '+' : '').$seconds.' seconds');
            $candidate = $body;
            if ($action === 'registration') { $candidate['events'][0]['eventDate'] = $date->format(DATE_ATOM); }
            else { $candidate['events'][] = ['eventAction' => $action, 'eventDate' => $date->format(DATE_ATOM)]; }
            $actual = lookup($candidate, $now, $days, $days);
            verify($actual['allowed'] === ($seconds <= 0) && ($seconds <= 0 || $actual['code'] === $code), 'Lock boundary failed: '.$action.' '.$days.' '.$seconds);
        }
    }
}
$candidate = $body; $candidate['events'][0]['eventDate'] = $now->format(DATE_ATOM);
verify(lookup($candidate, $now, 0, 0)['allowed'], 'Zero-day creation window refused.');
foreach (['2035-10-16T00:00:00Z' => true, '2035-10-17T23:59:59Z' => true, '2035-10-18T00:00:00Z' => false] as $expiry => $allowed) {
    $candidate = $body; $candidate['events'][1]['eventDate'] = $expiry;
    verify(lookup($candidate)['allowed'] === $allowed, 'Ten-year calendar boundary failed.');
}
$candidate = $body; $candidate['events'][1]['eventDate'] = '2033-02-28T00:00:00Z';
verify(lookup($candidate, new DateTimeImmutable('2024-02-29T00:00:00Z'))['allowed'], 'Leap-day calendar cap failed.');
$candidate['events'][1]['eventDate'] = '2033-03-01T00:00:00Z';
verify(!lookup($candidate, new DateTimeImmutable('2024-02-29T00:00:00Z'))['allowed'], 'Leap-day cap overflow accepted.');
$unknowns = [];
$candidate = $body; $candidate['ldhName'] = 'other.com'; $unknowns[] = $candidate;
$candidate = $body; unset($candidate['status']); $unknowns[] = $candidate;
$candidate = $body; unset($candidate['events']); $unknowns[] = $candidate;
$candidate = $body; $candidate['events'][0]['eventDate'] = '2027-01-01T00:00:00Z'; $unknowns[] = $candidate;
$candidate = $body; $candidate['events'][0]['eventDate'] = '2020-02-30T00:00:00Z'; $unknowns[] = $candidate;
$candidate = $body; $candidate['events'][] = $candidate['events'][0]; $unknowns[] = $candidate;
$candidate = $body; $candidate['events'][] = ['eventAction' => 'transfer', 'eventDate' => '2027-01-01T00:00:00Z']; $unknowns[] = $candidate;
foreach ($unknowns as $candidate) { $actual = lookup($candidate); verify($actual['allowed'] && is_string($actual['warning']) && $actual['expires_at'] === null, 'Incomplete data fabricated a definitive pass.'); }
foreach (['http://rdap.example.com/', 'https://127.0.0.1/', 'https://[::1]/', 'https://user:pass@rdap.example.com/', 'https://rdap.example.com:444/', 'https://rdap.local/', 'https://rdap.example.com/?url=x'] as $url) {
    $calls = 0;
    $rdap = new Rdap(function () use ($url, &$calls) { $calls++; return ['status' => 200, 'body' => json_encode(['services' => [[['com'], [$url]]]])]; });
    verify($rdap->precheck('example.com')['warning'] !== null && $calls === 1, 'Unsafe bootstrap URL fetched: '.$url);
}
foreach ([['status' => 302, 'body' => '{}'], ['status' => 200, 'body' => str_repeat('x', 2097153)], ['status' => 200, 'body' => '{'], ['status' => 503, 'body' => '{}']] as $response) {
    verify((new Rdap(fn () => $response))->precheck('example.com')['warning'] !== null, 'Redirect, size or invalid response accepted.');
}
// Only an authoritative domain-object 404 is definitive; bootstrap and transport failures stay unknown.
$notRegistered = ['allowed' => false, 'code' => 'transfer_not_registered', 'warning' => null, 'expires_at' => null];
$unknown = ['allowed' => true, 'code' => null, 'warning' => Rdap::UNCHECKED, 'expires_at' => null];
foreach ([
    'empty' => ['', true],
    'rdap-404' => ['{"errorCode":404}', true],
    'no-error-code' => ['{"title":"Not found"}', true],
    'empty-object' => ['{}', true],
    'html-proxy' => ['<html>Proxy not found</html>', false],
    'conflicting-code' => ['{"errorCode":500}', false],
    'invalid-json' => ['{"errorCode":404', false],
    'array' => ['[]', false],
    'array-of-object' => ['[{"errorCode":404}]', false],
    'null' => ['null', false],
    'number' => ['404', false],
    'string' => ['"not found"', false],
    'boolean' => ['true', false],
    'null-code' => ['{"errorCode":null}', false],
    'string-code' => ['{"errorCode":"404"}', false],
    'float-code' => ['{"errorCode":404.0}', false],
    'whitespace' => [' ', false],
] as $case => [$responseBody, $definitive]) {
    $calls404 = [];
    $rdap404 = new Rdap(function ($url) use ($responseBody, &$calls404) {
        $calls404[] = $url;
        return $url === 'https://data.iana.org/rdap/dns.json'
            ? ['status' => 200, 'body' => json_encode(['services' => [[['com'], ['https://rdap.example.com/']]]])]
            : ['status' => 404, 'body' => $responseBody];
    });
    verify($rdap404->precheck('example.com') === ($definitive ? $notRegistered : $unknown), 'Domain endpoint 404 classification: '.$case);
    verify($calls404 === ['https://data.iana.org/rdap/dns.json', 'https://rdap.example.com/domain/example.com'], 'Domain endpoint 404 route: '.$case);
}
$bootstrap404 = new Rdap(fn () => ['status' => 404, 'body' => '{"errorCode":404}']);
$actual = $bootstrap404->precheck('example.com');
verify($actual['allowed'] && $actual['code'] === null && $actual['warning'] !== null, 'Bootstrap 404 claimed the name is not registered.');
foreach ([500, 429, 302, 200, 'timeout'] as $failure) {
    $rdapFailure = new Rdap(function ($url) use ($failure) {
        if ($url === 'https://data.iana.org/rdap/dns.json') {
            return ['status' => 200, 'body' => json_encode(['services' => [[['com'], ['https://rdap.example.com/']]]])];
        }
        if ($failure === 'timeout') { throw new RuntimeException('Synthetic timeout'); }
        return ['status' => $failure, 'body' => '{'];
    });
    $actual = $rdapFailure->precheck('example.com');
    verify($actual['allowed'] && $actual['code'] === null && $actual['warning'] !== null && $actual['expires_at'] === null, 'Domain failure did not stay unknown: '.$failure);
}
$calls = 0;
$rdap = new Rdap(function () use (&$calls) { $calls++; throw new RuntimeException('private transport detail'); });
verify($rdap->precheck('example.com')['warning'] !== null && $calls === 1, 'Unavailable lookup retried or failed closed.');
verify($rdap->precheck('example.com/path')['warning'] !== null && $calls === 1, 'Invalid domain sent to transport.');
echo 'Transfer RDAP: '.$checks.' passed; injected HTTPS responses; no network.'.PHP_EOL;
