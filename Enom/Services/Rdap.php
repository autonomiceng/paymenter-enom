<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/** Public transfer checks. Only IANA bootstrap endpoints are used; referrals are ignored. */
class Rdap
{
    private const BOOTSTRAP = 'https://data.iana.org/rdap/dns.json';
    private const BODY_LIMIT = 2097152;
    private ?array $bootstrap = null;

    /** Injected transport returns ['status' => int, 'body' => string], without following redirects. */
    public function __construct(private $transport = null)
    {
        if ($transport !== null && !is_callable($transport)) { throw new \InvalidArgumentException('Invalid lookup transport.'); }
    }

    public function precheck(string $domain, int $creationDays = 60, int $transferDays = 60, ?DateTimeImmutable $today = null): array
    {
        $unknown = ['allowed' => true, 'code' => null, 'warning' => "We couldn't check {$domain}'s transfer status in advance. If its current registrar refuses the transfer, you get the full amount back as account credit.", 'expires_at' => null];
        try {
            if (strlen($domain) > 253 || !preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.[a-z]{2,63}\z/D', $domain)
                || $creationDays < 0 || $creationDays > 365 || $transferDays < 0 || $transferDays > 365) { return $unknown; }
            $bootstrap = $this->bootstrap ??= $this->request(self::BOOTSTRAP);
            $ending = substr($domain, strrpos($domain, '.') + 1);
            $endpoint = null;
            foreach ($bootstrap['services'] ?? [] as $service) {
                if (!is_array($service) || !is_array($service[0] ?? null) || !in_array($ending, $service[0], true)) { continue; }
                if ($endpoint !== null) { return $unknown; }
                foreach ($service[1] ?? [] as $candidate) {
                    if (is_string($candidate) && $this->safeUrl($candidate)) { $endpoint = rtrim($candidate, '/'); break; }
                }
            }
            if ($endpoint === null) { return $unknown; }
            $data = $this->request($endpoint.'/domain/'.rawurlencode($domain));
            if (($data['objectClassName'] ?? null) !== 'domain' || !is_string($data['ldhName'] ?? null)
                || strtolower($data['ldhName']) !== $domain || !is_array($data['status'] ?? null) || !$data['status']) { return $unknown; }
            $statuses = [];
            foreach ($data['status'] as $status) {
                if (!is_string($status)) { return $unknown; }
                $statuses[] = strtolower(preg_replace('/[\s_-]+/', '', $status));
            }
            $dates = [];
            foreach ($data['events'] ?? [] as $event) {
                if (!is_array($event)) { return $unknown; }
                $action = $event['eventAction'] ?? null;
                if (!in_array($action, ['registration', 'transfer', 'expiration'], true)) { continue; }
                $date = self::date($event['eventDate'] ?? null);
                if (!$date || isset($dates[$action])) { return $unknown; }
                $dates[$action] = $date;
            }
            $today = ($today ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
            $created = $dates['registration'] ?? null;
            $expiry = $dates['expiration'] ?? null;
            $transferred = $dates['transfer'] ?? null;
            if (!$created || !$expiry || $created > $today || $expiry <= $created
                || $transferred && ($transferred > $today || $transferred < $created || $transferred >= $expiry)) { return $unknown; }
            $result = ['allowed' => true, 'code' => null, 'warning' => null, 'expires_at' => $expiry->format('Y-m-d')];
            foreach (['clienttransferprohibited' => 'transfer_locked', 'servertransferprohibited' => 'transfer_locked',
                'redemptionperiod' => 'transfer_redemption', 'pendingdelete' => 'transfer_pending_delete', 'pendingtransfer' => 'transfer_pending'] as $status => $code) {
                if (in_array($status, $statuses, true)) { return array_replace($result, ['allowed' => false, 'code' => $code]); }
            }
            if ($today < $created->modify('+'.$creationDays.' days')) { return array_replace($result, ['allowed' => false, 'code' => 'transfer_creation_lock']); }
            if ($transferred && $today < $transferred->modify('+'.$transferDays.' days')) { return array_replace($result, ['allowed' => false, 'code' => 'transfer_previous_lock']); }
            if (self::addYears($expiry, 1)->setTime(0, 0) > self::addYears($today, 10)->setTime(0, 0)) {
                return array_replace($result, ['allowed' => false, 'code' => 'transfer_maximum_term']);
            }
            return $result;
        } catch (Throwable) { return $unknown; }
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)\z/D', $value)) { return null; }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            return $errors && ($errors['warning_count'] || $errors['error_count']) ? null : $date->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) { return null; }
    }

    private static function addYears(DateTimeImmutable $date, int $years): DateTimeImmutable
    {
        $year = (int) $date->format('Y') + $years;
        $month = (int) $date->format('m');
        $first = $date->setDate($year, $month, 1);
        return $first->setDate($year, $month, min((int) $date->format('d'), (int) $first->format('t')));
    }

    private function safeUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && (!isset($parts['port']) || $parts['port'] === 443) && isset($parts['host'])
            && preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/iD', $parts['host'])
            && str_contains($parts['host'], '.') && !filter_var($parts['host'], FILTER_VALIDATE_IP)
            && !preg_match('/(?:^|\.)(?:localhost|local|internal|invalid|test)\z/iD', $parts['host'])
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $url);
    }

    private function request(string $url): array
    {
        if (!$this->safeUrl($url)) { throw new RuntimeException('Lookup endpoint is unavailable.'); }
        $response = $this->transport !== null ? ($this->transport)($url) : $this->send($url);
        if (!is_array($response) || ($response['status'] ?? null) !== 200 || !is_string($response['body'] ?? null)
            || strlen($response['body']) > self::BODY_LIMIT) { throw new RuntimeException('Lookup response is unavailable.'); }
        $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new RuntimeException('Lookup response is unavailable.'); }
        return $data;
    }

    private function send(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!$address) { continue; }
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with(strtolower($address), '::ffff:')) { throw new RuntimeException('Lookup endpoint is unavailable.'); }
            $addresses[] = str_contains($address, ':') ? '['.$address.']' : $address;
        }
        if (!$addresses || getenv('SSLKEYLOGFILE')) { throw new RuntimeException('Lookup endpoint is unavailable.'); }
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_VERBOSE => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_RESOLVE => [$host.':443:'.implode(',', $addresses)],
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json, application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::BODY_LIMIT) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        try {
            if (curl_exec($curl) === false) { throw new RuntimeException('Lookup response is unavailable.'); }
            return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body];
        } finally { curl_close($curl); }
    }
}
