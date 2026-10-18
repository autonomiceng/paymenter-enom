<?php
require_once __DIR__.'/../../Enom/Services/Rdap.php';
use Paymenter\Extensions\Servers\Enom\Services\Rdap;

$checks = 0;
function verify(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
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
$calls = 0;
$rdap = new Rdap(function () use (&$calls) { $calls++; throw new RuntimeException('private transport detail'); });
verify($rdap->precheck('example.com')['warning'] !== null && $calls === 1, 'Unavailable lookup retried or failed closed.');
verify($rdap->precheck('example.com/path')['warning'] !== null && $calls === 1, 'Invalid domain sent to transport.');
echo 'Transfer RDAP: '.$checks.' passed; injected HTTPS responses; no network.'.PHP_EOL;
