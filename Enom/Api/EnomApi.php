<?php

namespace Paymenter\Extensions\Servers\Enom\Api;

/** Command facade for one Enom account. Domain endings come from the server's TLD catalogue. */
final class EnomApi
{
    private const CONTACT_FIELDS = [
        'first_name' => 'FirstName', 'last_name' => 'LastName', 'organization_name' => 'OrganizationName',
        'job_title' => 'JobTitle', 'address1' => 'Address1', 'address2' => 'Address2', 'city' => 'City',
        'state_province' => 'StateProvince', 'state_province_choice' => 'StateProvinceChoice',
        'postal_code' => 'PostalCode', 'country' => 'Country', 'email_address' => 'EmailAddress',
        'phone' => 'Phone', 'phone_ext' => 'PhoneExt', 'fax' => 'Fax',
    ];

    /** @var list<string> */
    private array $suffixes = ['com', 'net', 'us'];

    public function __construct(private RegistrarClient $client) {}
    public function isReadOnly(): bool { return $this->client->isReadOnly(); }
    public function environment(): string { return $this->client->environment(); }

    /** Endings this account may address: the catalogue plus endings of existing registrations. */
    public function withSuffixes(array $suffixes): self
    {
        $clean = [];
        foreach ([...$this->suffixes, ...$suffixes] as $suffix) {
            $suffix = strtolower(ltrim(trim((string) $suffix), '.'));
            if ($suffix !== '' && preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)*$/D', $suffix) && !in_array($suffix, $clean, true)) { $clean[] = $suffix; }
        }
        $this->suffixes = $clean;
        return $this;
    }

    /** @return list<string> */
    public function suffixes(): array { return $this->suffixes; }

    private function parse(string $domain): array
    {
        $parts = DomainName::parse($domain, $this->suffixes);
        if (str_starts_with($parts['sld'], 'xn--')) {
            throw new \InvalidArgumentException('International domains are unavailable in this catalogue.');
        }
        return $parts;
    }

    public function tldList(): RegistrarResult { return $this->client->execute('GetTLDList'); }
    public function productPrice(string $tld, string $action, int $years = 1): RegistrarResult
    {
        $tld = strtolower($tld);
        if (!preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)*$/D', $tld)) { throw new \InvalidArgumentException('Invalid domain ending.'); }
        $type = match ($action) { 'register' => 10, 'renew' => 16, 'transfer' => 19,
            default => throw new \InvalidArgumentException('Unknown price action.') };
        $result = $this->client->execute('PE_GetProductPrice', ['TLD' => $tld, 'ProductType' => $type, 'Years' => $this->term($years)]);
        if ($result->status !== 'success') { return $result; }
        $enabled = $result->value('productprice.productenabled');
        $price = $result->value('productprice.price');
        if (!is_string($enabled) || !in_array(strtolower($enabled), ['true', '1'], true)
            || !is_string($price) || !preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,4})?$/D', $price)) {
            return new RegistrarResult('unknown', 'product_price_unavailable');
        }
        return $result;
    }
    public function tldExtendedAttributes(string $tld): RegistrarResult { return $this->client->execute('GetExtAttributes', ['TLD' => strtolower($tld)]); }
    public function tldInfo(string $tld): RegistrarResult { return $this->client->execute('GetTLDDetails', ['TLD' => strtolower($tld)]); }
    /** Child nameservers (glue). The host must sit under a domain in this account. */
    public function registerHost(string $host, string $ip): RegistrarResult { return $this->client->execute('RegisterNameServer', ['Add' => 'true', 'NSName' => self::host($host), 'IP' => self::ip($ip)]); }
    public function updateHost(string $host, string $oldIp, string $newIp): RegistrarResult { return $this->client->execute('UpdateNameServer', ['NS' => self::host($host), 'OldIP' => self::ip($oldIp), 'NewIP' => self::ip($newIp)]); }
    public function deleteHost(string $host): RegistrarResult { return $this->client->execute('DeleteNameServer', ['NS' => self::host($host)]); }
    public function hostStatus(string $host): RegistrarResult { return $this->client->execute('CheckNSStatus', ['CheckNSName' => self::host($host)]); }

    private static function host(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.){2,}[a-z0-9-]{2,63}$/D', $host)) { throw new \InvalidArgumentException('Invalid nameserver host.'); }
        return $host;
    }

    private static function ip(string $ip): string
    {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { throw new \InvalidArgumentException('Invalid IP address.'); }
        return $ip;
    }

    public function emailTransferCode(string $domain): RegistrarResult { return $this->call('SynchAuthInfo', $domain, ['EmailEPP' => 'True', 'RunSynchAutoInfo' => 'True']); }
    public function balance(): RegistrarResult { return $this->client->execute('GetBalance'); }
    private function call(string $command, string $domain, array $parameters = []): RegistrarResult
    {
        $parts = $this->parse($domain);
        return $this->client->execute($command, ['SLD' => $parts['sld'], 'TLD' => $parts['tld']] + $parameters);
    }

    public function search(string $domain): RegistrarResult { return $this->call('Check', $domain, ['Version' => 2, 'IncludePrice' => 1, 'IncludeProperties' => 1, 'IncludeEAP' => 1]); }
    public function info(string $domain): RegistrarResult { return $this->call('GetDomainInfo', $domain); }
    public function expiry(string $domain): RegistrarResult { return $this->call('GetDomainExp', $domain); }
    public function renewalInfo(string $domain): RegistrarResult { return $this->call('GetExtendInfo', $domain); }
    public function contacts(string $domain): RegistrarResult { return $this->call('GetContacts', $domain); }
    public function nameservers(string $domain): RegistrarResult { return $this->call('GetDNS', $domain); }
    public function lock(string $domain): RegistrarResult { return $this->call('GetRegLock', $domain); }
    public function privacy(string $domain): RegistrarResult { return $this->call('GetWPPSInfo', $domain); }
    public function renewal(string $domain): RegistrarResult { return $this->call('GetRenew', $domain); }
    public function hosts(string $domain): RegistrarResult { return $this->call('GetHosts', $domain); }
    public function srv(string $domain): RegistrarResult { return $this->call('GetDomainSRVHosts', $domain); }
    public function dnssec(string $domain): RegistrarResult { return $this->call('GetDnsSec', $domain); }
    public function orders(string $begin, string $end, int $start = 1): RegistrarResult
    {
        foreach ([$begin, $end] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!m/d/Y', $date);
            if (!$parsed || $parsed->format('m/d/Y') !== $date) { throw new \InvalidArgumentException('Invalid order date window.'); }
        }
        if ($start < 1 || $start > 9999 || strtotime($end) < strtotime($begin) || strtotime($end) > strtotime('+6 months', strtotime($begin))) {
            throw new \InvalidArgumentException('Invalid order history range.');
        }
        return $this->client->execute('GetOrderList', ['BeginDate' => $begin, 'EndDate' => $end, 'Start' => $start]);
    }

    public function order(string $id): RegistrarResult { return $this->identified('GetOrderDetail', 'OrderID', $id); }
    public function transferOrder(string $id): RegistrarResult { return $this->identified('TP_GetOrder', 'TransferOrderID', $id); }
    public function transferDetail(string $id): RegistrarResult { return $this->identified('TP_GetOrderDetail', 'TransferOrderDetailID', $id); }

    private function identified(string $command, string $key, string $id): RegistrarResult
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id)) { throw new \InvalidArgumentException('Invalid provider order identifier.'); }
        return $this->client->execute($command, [$key => $id]);
    }

    private function term(int $years): int
    {
        if ($years < 1 || $years > 10) { throw new \InvalidArgumentException('Terms are 1 to 10 years.'); }
        return $years;
    }

    public function register(string $domain, array $contacts, array $nameservers = [], int $years = 1, array $registry = []): RegistrarResult
    {
        return $this->call('Purchase', $domain, [
            'NumYears' => $this->term($years), 'UnLockRegistrar' => 0, 'RenewName' => 0,
            'EmailNotify' => 0, 'UseCreditCard' => 'no', 'QueueOrder' => 0, 'AllowQueuing' => 0, 'IgnoreNSFail' => 'No',
        ] + $this->contactSets($contacts) + $this->ns($nameservers) + self::registryFields($registry));
    }

    /** Registry fields (GetExtAttributes names such as us_nexus) as request parameters. */
    private static function registryFields(array $registry): array
    {
        $fields = [];
        foreach ($registry as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $name) || !is_string($value) || $value === ''
                || strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('Invalid registry details.');
            }
            $fields[$name] = $value;
        }
        return $fields;
    }

    public function renew(string $domain, int $years = 1): RegistrarResult
    {
        return $this->call('Extend', $domain, ['NumYears' => $this->term($years), 'UseCreditCard' => 'no']);
    }

    public function expiredDomains(): RegistrarResult { return $this->client->execute('GetExpiredDomains'); }

    public function expiredRenew(string $domain, int $years = 1): RegistrarResult
    {
        $parts = $this->parse($domain);
        // Use account credit. Omit card fields and premium price overrides.
        return $this->client->execute('UpdateExpiredDomains', ['DomainName' => $parts['domain'], 'NumYears' => $this->term($years), 'UseCreditCard' => 'no']);
    }

    /** Fresh authenticated reads; callers still validate their captured account binding. */
    public function renewalEvidence(string $domain, ?callable $beforeRead = null, ?\DateTimeInterface $observedAt = null): array
    {
        $parts = $this->parse($domain);
        $clock = $observedAt ? \DateTimeImmutable::createFromInterface($observedAt)
            : (function_exists('now') ? \DateTimeImmutable::createFromInterface(now()) : new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        if ($beforeRead) { $beforeRead('info', null); }
        $info = $this->info($parts['domain']);
        $normalized = self::normalize('info', $info);
        if (($normalized['status'] ?? null) !== 'success' || !is_string($normalized['domain'] ?? null)
            || strtolower($normalized['domain']) !== $parts['domain']) {
            return ['domain' => $parts['domain'], 'observed_at' => $clock->format(DATE_ATOM), 'info' => $info,
                'extend' => new RegistrarResult('unknown', 'identity_unconfirmed'),
                'check' => new RegistrarResult('unknown', 'identity_unconfirmed'), 'expired' => null];
        }
        if ($beforeRead) { $beforeRead('extend', $info); }
        $extend = $this->renewalInfo($parts['domain']);
        if ($beforeRead) { $beforeRead('check', $info); }
        $check = $this->client->execute('Check', ['SLD' => $parts['sld'], 'TLD' => $parts['tld'], 'Version' => 2, 'IncludePrice' => 1, 'IncludeProperties' => 1, 'IncludeEAP' => 1]);
        $expiry = $info->confirmedExpiry();
        $expired = null;
        if ($expiry && $expiry <= $clock) {
            if ($beforeRead) { $beforeRead('expired', $info); }
            $expired = $this->expiredDomains();
        }
        return ['domain' => $parts['domain'], 'observed_at' => $clock->format(DATE_ATOM), 'info' => $info,
            'extend' => $extend, 'check' => $check, 'expired' => $expired];
    }

    public function transfer(string $domain, array $contacts, string $epp, array $registry = []): RegistrarResult
    {
        $parts = $this->parse($domain);
        if ($epp === '' || strlen($epp) > 512 || preg_match('/[\x00-\x1f\x7f]/', $epp)) {
            throw new \InvalidArgumentException('Invalid transfer authorization.');
        }
        return $this->client->execute('TP_CreateOrder', [
            'OrderType' => 'Autoverification', 'DomainCount' => 1, 'SLD1' => $parts['sld'], 'TLD1' => $parts['tld'],
            'AuthInfo1' => $epp, 'PreConfig' => 0, 'Lock' => 1, 'Renew' => 0, 'UseCreditCard' => 'no',
        ] + $this->contactSets($contacts) + self::registryFields($registry));
    }

    public function updateContact(string $domain, string $type, array $fields): RegistrarResult
    {
        if (strtolower($type) === 'all') {
            // One request sets every role, so they can't end up half-changed.
            return $this->call('Contacts', $domain, $this->contactSets(array_fill_keys(['registrant', 'admin', 'tech', 'auxbilling'], $fields)));
        }
        $prefix = $this->contactType($type);
        return $this->call('Contacts', $domain, ['ContactType' => strtoupper($prefix)] + $this->contactFields($prefix, $fields));
    }

    private function contactType(string $type): string
    {
        return match (strtolower($type)) {
            'registrant' => 'Registrant', 'admin' => 'Admin', 'tech' => 'Tech', 'auxbilling', 'aux_billing' => 'AuxBilling',
            default => throw new \InvalidArgumentException('Unsupported contact type.'),
        };
    }

    private function contactSets(array $contacts): array
    {
        if (!isset($contacts['registrant'])) { $contacts = ['registrant' => $contacts]; }
        $result = [];
        foreach ($contacts as $type => $fields) {
            if (!is_array($fields)) { throw new \InvalidArgumentException('Invalid domain contacts.'); }
            $result += $this->contactFields($this->contactType((string) $type), $fields);
        }
        return $result;
    }

    private function contactFields(string $prefix, array $fields): array
    {
        $parameters = [];
        foreach ($fields as $key => $value) {
            $suffix = self::CONTACT_FIELDS[$key] ?? null;
            if ($suffix === null) {
                foreach (self::CONTACT_FIELDS as $candidate) {
                    if (strcasecmp((string) $key, $candidate) === 0 || strcasecmp((string) $key, $prefix.$candidate) === 0) { $suffix = $candidate; break; }
                }
            }
            if (!$suffix || !is_string($value) || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new \InvalidArgumentException('Invalid contact field.'); }
            $parameters[$prefix.$suffix] = trim($value);
        }
        foreach (['FirstName', 'LastName', 'Address1', 'City', 'PostalCode', 'Country', 'EmailAddress', 'Phone'] as $required) {
            if (($parameters[$prefix.$required] ?? '') === '') { throw new \InvalidArgumentException('Complete contact information is required.'); }
        }
        if (!filter_var($parameters[$prefix.'EmailAddress'], FILTER_VALIDATE_EMAIL)
            || !preg_match('/^[A-Z]{2}$/D', strtoupper($parameters[$prefix.'Country']))
            || !preg_match('/^\+[0-9]{1,4}\.[0-9]{4,15}$/D', $parameters[$prefix.'Phone'])) { throw new \InvalidArgumentException('Invalid contact address or telephone.'); }
        $parameters[$prefix.'Country'] = strtoupper($parameters[$prefix.'Country']);
        $parameters[$prefix.'OrganizationName'] ??= '';
        $parameters[$prefix.'Fax'] ??= '';
        return $parameters;
    }

    private function ns(array $servers): array
    {
        if (!$servers) { return ['UseDNS' => 'default']; }
        if (count($servers) < 2 || count($servers) > 12) { throw new \InvalidArgumentException('Use two to twelve nameservers.'); }
        $result = [];
        foreach (array_values($servers) as $i => $server) {
            if (!is_string($server) || strlen($server) > 253 || str_ends_with($server, '..')
                || !filter_var($server, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($server, '.')) { throw new \InvalidArgumentException('Invalid nameserver hostname.'); }
            $server = strtolower(str_ends_with($server, '.') ? substr($server, 0, -1) : $server);
            if (filter_var($server, FILTER_VALIDATE_IP)) { throw new \InvalidArgumentException('Use nameserver hostnames, not IP addresses.'); }
            $result['NS'.($i + 1)] = $server;
        }
        if (count(array_unique($result)) !== count($result)) { throw new \InvalidArgumentException('Nameservers must be unique.'); }
        return $result;
    }

    public function setNameservers(string $domain, array $servers): RegistrarResult { return $this->call('ModifyNS', $domain, $this->ns($servers)); }
    public function setLock(string $domain, bool $locked): RegistrarResult { return $this->call('SetRegLock', $domain, ['UnlockRegistrar' => $locked ? 0 : 1]); }
    public function synchronizeEpp(string $domain): RegistrarResult { return $this->call('SynchAuthInfo', $domain, ['EmailEPP' => 'False', 'RunSynchAutoInfo' => 'True']); }
    public function disableProviderRenewal(string $domain): RegistrarResult { return $this->call('SetRenew', $domain, ['RenewFlag' => 0, 'WPPSRenew' => 0]); }
    public function purchasePrivacy(string $domain, int $years = 1): RegistrarResult { return $this->call('PurchaseServices', $domain, ['Service' => 'WPPS', 'NumYears' => $this->term($years), 'RenewName' => 0, 'EmailNotify' => 0, 'UseCreditCard' => 'no']); }
    public function renewPrivacy(string $domain, int $years = 1): RegistrarResult { return $this->call('RenewServices', $domain, ['Service' => 'WPPS', 'NumYears' => $this->term($years), 'UseCreditCard' => 'no']); }
    public function enablePrivacy(string $domain, bool $enabled): RegistrarResult { return $this->call($enabled ? 'EnableServices' : 'DisableServices', $domain, ['Service' => 'WPPS']); }

    public function setHosts(string $domain, array $records): RegistrarResult
    {
        if (!$records || count($records) > 50) { throw new \InvalidArgumentException('Provide one to fifty complete host records.'); }
        $parameters = [];
        foreach (array_values($records) as $i => $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException('Invalid host record.'); }
            $name = $row['name'] ?? $row['host'] ?? $row['hostname'] ?? null;
            $type = strtoupper((string) ($row['type'] ?? $row['record_type'] ?? ''));
            $address = $row['address'] ?? null;
            if (!is_string($name) || strlen($name) > 253 || !preg_match('/^(?:@|\*|[a-z0-9_*.-]+)$/iD', $name)
                || !is_string($address) || $address === '' || strlen($address) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $address)
                || !in_array($type, ['A', 'AAAA', 'CNAME', 'MX', 'MXE', 'TXT', 'URL', 'FRAME'], true)) { throw new \InvalidArgumentException('Invalid host record.'); }
            if (($type === 'A' || $type === 'MXE') && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || $type === 'AAAA' && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { throw new \InvalidArgumentException('Invalid record IP address.'); }
            if (in_array($type, ['MX', 'CNAME'], true) && !$this->fqdn($address)) { throw new \InvalidArgumentException('Use a fully qualified target ending in a period.'); }
            if (in_array($type, ['URL', 'FRAME'], true) && (!filter_var($address, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($address, PHP_URL_SCHEME)), ['http', 'https'], true))) { throw new \InvalidArgumentException('Use an HTTP or HTTPS redirect target.'); }
            $x = $i + 1;
            $parameters['HostName'.$x] = $name;
            $parameters['RecordType'.$x] = $type;
            $parameters['Address'.$x] = $address;
            if ($type === 'MX') { $parameters['MXPref'.$x] = $this->integer($row['mx_pref'] ?? $row['mxpref'] ?? 10); }
        }
        return $this->call('SetHosts', $domain, $parameters);
    }

    private function integer(mixed $value, int $max = 65535): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[0-9]+\z/', (string) $value)) {
            throw new \InvalidArgumentException('Invalid record number.');
        }
        $digits = ltrim((string) $value, '0') ?: '0';
        $limit = (string) $max;
        if (strlen($digits) > strlen($limit) || strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0) {
            throw new \InvalidArgumentException('Invalid record number.');
        }
        return (int) $digits;
    }

    private function fqdn(string $name): bool
    {
        return str_ends_with($name, '.') && strlen($name) <= 254 && (bool) filter_var(substr($name, 0, -1), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    public function setSrv(string $domain, array $records): RegistrarResult
    {
        if (!$records || count($records) > 50) { throw new \InvalidArgumentException('Provide a bounded complete SRV record list.'); }
        $parameters = [];
        foreach (array_values($records) as $i => $row) {
            $service = $row['service'] ?? '';
            $protocol = strtolower((string) ($row['protocol'] ?? ''));
            $target = $row['target'] ?? '';
            if (!is_string($service) || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,12}[a-z0-9])?$/D', $service)
                || !in_array($protocol, ['tcp', 'udp'], true) || !is_string($target) || !$this->fqdn($target)) { throw new \InvalidArgumentException('Invalid SRV record.'); }
            $x = $i + 1;
            $parameters['Service'.$x] = $service;
            $parameters['Protocol'.$x] = strtoupper($protocol);
            $parameters['Target'.$x] = $target;
            foreach (['priority', 'weight', 'port'] as $field) { $parameters[ucfirst($field).$x] = $this->integer($row[$field] ?? null); }
            if (isset($row['host_id'])) { $parameters['HostID'.$x] = (string) $this->integer($row['host_id'], PHP_INT_MAX); }
        }
        return $this->call('SetDomainSRVHosts', $domain, $parameters);
    }

    private function ds(array $record): array
    {
        $algorithm = $this->integer($record['algorithm'] ?? $record['alg'] ?? null, 255);
        $digestType = $this->integer($record['digest_type'] ?? null, 255);
        $tag = $this->integer($record['key_tag'] ?? null);
        $digest = strtoupper((string) ($record['digest'] ?? ''));
        if (!in_array($algorithm, [3, 5, 7, 8, 10, 12, 13, 14], true) || !in_array($digestType, [1, 2], true)
            || !ctype_xdigit($digest) || strlen($digest) !== ($digestType === 1 ? 40 : 64)) { throw new \InvalidArgumentException('Invalid DNSSEC DS record.'); }
        return ['Alg' => $algorithm, 'Digest' => $digest, 'DigestType' => $digestType, 'KeyTag' => $tag];
    }
    public function addDnssec(string $domain, array $record): RegistrarResult { return $this->call('AddDnsSec', $domain, $this->ds($record)); }
    public function deleteDnssec(string $domain, array $record): RegistrarResult { return $this->call('DeleteDnsSec', $domain, $this->ds($record)); }

    private static function list(mixed $value): array
    {
        if ($value === null || $value === '') { return []; }
        return is_array($value) && array_is_list($value) ? $value : [$value];
    }
    private static function flag(mixed $value): ?bool
    {
        if (!is_string($value)) { return null; }
        return match (strtolower($value)) { '1', 'true', 'yes' => true, '0', 'false', 'no' => false, default => null };
    }

    private static function dnssecTuple(array $record): ?array
    {
        $fields = ['algorithm', 'digest', 'digesttype', 'keytag'];
        if (count($record) !== 4 || array_diff(array_keys($record), $fields)) { return null; }
        foreach ($fields as $field) { if (!is_string($record[$field] ?? null)) { return null; } }
        foreach (['algorithm' => 255, 'digesttype' => 255, 'keytag' => 65535] as $field => $maximum) {
            if (!ctype_digit($record[$field]) || strlen($record[$field]) > 5 || (int) $record[$field] > $maximum) { return null; }
        }
        $algorithm = (int) $record['algorithm'];
        $digestType = (int) $record['digesttype'];
        $digest = strtoupper($record['digest']);
        if (!in_array($algorithm, [3, 5, 7, 8, 10, 12, 13, 14], true) || !in_array($digestType, [1, 2], true)
            || !ctype_xdigit($digest) || strlen($digest) !== ($digestType === 1 ? 40 : 64)) { return null; }
        return ['algorithm' => $algorithm, 'digest' => $digest, 'digest_type' => $digestType, 'key_tag' => (int) $record['keytag']];
    }

    private static function dnssecReadback(array $data): ?array
    {
        if (!array_key_exists('dnssecdata', $data) && !array_key_exists('dnssecdatacount', $data)) {
            // Retain the documented flat tuple contract, with the same strict DS validation.
            if (array_key_exists('success', $data) && self::flag($data['success']) !== true) { return null; }
            $tuple = self::dnssecTuple(array_intersect_key($data, array_flip(['algorithm', 'digest', 'digesttype', 'keytag'])));
            return $tuple === null ? null : [$tuple];
        }
        $count = $data['dnssecdatacount'] ?? null;
        $container = $data['dnssecdata'] ?? null;
        if (!is_string($count) || preg_match('/^(?:0|[1-9][0-9]?)$/D', $count) !== 1 || (int) $count > 50
            || self::flag($data['success'] ?? null) !== true || !is_array($container)
            || array_diff(array_keys($container), ['result', 'keydata'])
            || array_intersect(array_keys($data), ['algorithm', 'digest', 'digesttype', 'keytag'])) { return null; }
        $acknowledgement = $container['result'] ?? null;
        if (!is_array($acknowledgement) || array_diff(array_keys($acknowledgement), ['responsecode', 'responsemessage'])
            || ($acknowledgement['responsecode'] ?? null) !== '200'
            || (array_key_exists('responsemessage', $acknowledgement) && !is_string($acknowledgement['responsemessage']))) { return null; }
        if ($count === '0') { return array_key_exists('keydata', $container) ? null : []; }
        if (!is_array($container['keydata'] ?? null)) { return null; }
        $records = self::list($container['keydata']);
        if (count($records) !== (int) $count) { return null; }
        $tuples = [];
        foreach ($records as $record) {
            if (!is_array($record) || ($tuple = self::dnssecTuple($record)) === null || in_array($tuple, $tuples, true)) { return null; }
            $tuples[] = $tuple;
        }
        return $tuples;
    }

    /** Explicit documented XML paths only; absent fields stay unknown. */
    public static function normalize(string $kind, RegistrarResult $result): array
    {
        if (!in_array($result->status, ['success', 'pending'], true)) { return ['status' => $result->status]; }
        $data = $result->data;
        $normalized = ['status' => $result->status];
        if ($kind === 'search') {
            $domain = $result->value('domains.domain', []);
            if (is_array($domain) && array_is_list($domain)) { $domain = count($domain) === 1 ? $domain[0] : []; }
            $normalized += ['available' => ($domain['rrpcode'] ?? null) === '210', 'premium' => self::flag($domain['ispremium'] ?? null), 'platinum' => self::flag($domain['isplatinum'] ?? null), 'eap' => self::flag($domain['iseap'] ?? null), 'currency' => $domain['prices']['currency'] ?? null, 'prices' => $domain['prices'] ?? [], 'properties' => $domain['properties'] ?? []];
            $normalized += ['is_premium' => $normalized['premium'], 'is_platinum' => $normalized['platinum'], 'is_eap' => $normalized['eap']];
            $normalized['transferable'] = self::flag($domain['properties']['transferable'] ?? null);
            $normalized['min_reg_year'] = isset($domain['properties']['minregyear']) && ctype_digit((string) $domain['properties']['minregyear']) ? (int) $domain['properties']['minregyear'] : null;
            $normalized['max_reg_year'] = isset($domain['properties']['maxregyear']) && ctype_digit((string) $domain['properties']['maxregyear']) ? (int) $domain['properties']['maxregyear'] : null;
        } elseif ($kind === 'info') {
            $info = $result->value('getdomaininfo', $data);
            $status = $info['status'] ?? $info;
            $belongsTo = $status['belongs-to'] ?? null;
            $normalized += ['domain_name_id' => $info['domainname']['@attributes']['domainnameid'] ?? $info['domainnameid'] ?? null, 'belongs_to' => is_array($belongsTo) ? ($belongsTo['#text'] ?? null) : $belongsTo, 'registrar' => $status['registrar'] ?? null, 'registration_status' => $status['registrationstatus'] ?? null, 'purchase_status' => $status['purchase-status'] ?? null, 'expires_at' => $result->confirmedExpiry()?->format(DATE_ATOM), 'provider_order_id' => $data['orderid'] ?? null];
            $name = $info['domainname'] ?? null;
            $normalized += ['domain' => is_array($name) ? ($name['#text'] ?? null) : $name,
                'sld' => is_array($name) ? ($name['@attributes']['sld'] ?? null) : null,
                'tld' => is_array($name) ? ($name['@attributes']['tld'] ?? null) : null,
                'restorable' => self::flag($status['restorable'] ?? null),
                // Restorable is not ordinary grace-renewal permission.
                'grace_eligible' => null];
        } elseif ($kind === 'nameservers') {
            $normalized['nameservers'] = self::list($data['dns'] ?? null);
            $normalized['uses_enom_dns'] = is_string($data['usedns'] ?? null) ? in_array(strtolower($data['usedns']), ['default', 'ours'], true) : null;
        } elseif ($kind === 'lock') {
            $normalized['locked'] = self::flag($data['reg-lock'] ?? null);
        } elseif ($kind === 'privacy') {
            $info = $result->value('getwppsinfo', $data);
            foreach (['allowed' => 'wppsallowed', 'exists' => 'wppsexists', 'enabled' => 'wppsenabled', 'auto_renew' => 'wppsautorenew'] as $key => $field) { $normalized[$key] = self::flag($info[$field] ?? null); }
            $normalized['expires_at'] = $info['wppsexpdate'] ?? null;
            $normalized['confirmed_expires_at'] = $result->confirmedPrivacyExpiry()?->format(DATE_ATOM);
            $normalized['price'] = $info['wppsprice'] ?? null;
        } elseif ($kind === 'contacts') {
            $info = $result->value('getcontacts', $data);
            foreach (['registrant', 'admin', 'tech', 'auxbilling'] as $type) {
                $contact = $info[$type] ?? [];
                if (!is_array($contact) || ($contact && array_is_list($contact))) { return ['status' => 'unknown']; }
                $normalized['contacts'][$type] = [];
                // Registries that don't collect a role (common since GDPR) report it as NotCollected.
                $match = $contact[$type.'contactmatch'] ?? null;
                $normalized['collected'][$type] = !(is_string($match) && strcasecmp($match, 'NotCollected') === 0);
                foreach (self::CONTACT_FIELDS as $key => $suffix) {
                    $values = [];
                    foreach ([$type.strtolower($suffix), strtolower($suffix)] as $field) {
                        if (!array_key_exists($field, $contact)) { continue; }
                        $value = $contact[$field];
                        if (is_array($value)) {
                            if (array_diff(array_keys($value), ['#text', '@attributes'])
                                || (array_key_exists('@attributes', $value) && (!is_array($value['@attributes'])
                                    || count(array_filter($value['@attributes'], 'is_string')) !== count($value['@attributes'])))) {
                                return ['status' => 'unknown'];
                            }
                            $value = $value['#text'] ?? null;
                        }
                        if (!is_string($value)) { return ['status' => 'unknown']; }
                        $values[] = $value;
                    }
                    if (count($values) === 2 && $values[0] !== $values[1]) { return ['status' => 'unknown']; }
                    if ($values) { $normalized['contacts'][$type][$key] = $values[0]; }
                }
            }
            $normalized['pending_verification'] = self::flag($info['pendingverification'] ?? null);
            $normalized['transfer_locked'] = self::flag($info['transferlock'] ?? null);
        } elseif ($kind === 'hosts') {
            $normalized['hosts'] = [];
            foreach (self::list($data['host'] ?? null) as $host) {
                if (!is_array($host)) { continue; }
                $normalized['hosts'][] = ['name' => $host['name'] ?? '', 'type' => $host['type'] ?? '', 'address' => $host['address'] ?? '', 'mx_pref' => isset($host['mxpref']) ? (int) $host['mxpref'] : 10];
            }
        } elseif ($kind === 'srv') {
            $normalized['srv'] = [];
            foreach (self::list($result->value('srv-records.srv', $data['srv'] ?? null)) as $host) {
                if (!is_array($host)) { continue; }
                $normalized['srv'][] = ['host_id' => $host['hostid'] ?? null, 'service' => ltrim($host['hostname'] ?? $host['name'] ?? '', '_'), 'protocol' => strtolower(ltrim($host['protocol'] ?? '', '_')), 'priority' => isset($host['priority']) ? (int) $host['priority'] : null, 'weight' => isset($host['weight']) ? (int) $host['weight'] : null, 'port' => isset($host['port']) ? (int) $host['port'] : null, 'target' => $host['address'] ?? ''];
            }
        } elseif ($kind === 'dnssec') {
            // Actual zero/single KeyData shapes are observed. Repeated KeyData is tested synthetically.
            $records = self::dnssecReadback($data);
            $normalized['dnssec'] = $records ?? [];
            $normalized['shape_verified'] = $records !== null;
            if (!$normalized['shape_verified']) { $normalized['status'] = 'unknown'; }
        } elseif ($kind === 'transfer') {
            $normalized['orders'] = self::list($data['transferorder'] ?? null);
            $header = $data['transferorder'] ?? [];
            $detail = is_array($header) ? ($header['transferorderdetail'] ?? []) : [];
            $single = is_array($detail) && !array_is_list($detail);
            $normalized['domain'] = $single && isset($detail['sld'], $detail['tld']) ? strtolower($detail['sld'].'.'.$detail['tld']) : null;
            $normalized['completed'] = $single && ($header['statusid'] ?? null) === '3' && ($detail['statusid'] ?? null) === '5';
            $normalized['provider_order_id'] = $header['transferorderid'] ?? null;
        } elseif ($kind === 'orders') {
            $normalized['orders'] = self::list($result->value('orderlist.orderdetail'));
            $normalized['next_start'] = $result->value('orderlist.nextstartposition');
        } elseif ($kind === 'order') {
            $normalized['provider_order_id'] = $result->value('order.orderid');
            $normalized['details'] = self::list($result->value('order.orderdetail'));
            $normalized['expires_at'] = $result->confirmedExpiry()?->format(DATE_ATOM);
            $details = $normalized['details'];
            $single = count($details) === 1 && is_array($details[0]);
            $normalized['domain'] = $single ? strtolower((string) ($details[0]['description'] ?? '')) : null;
            $normalized['completed'] = $single && strtolower((string) ($details[0]['status'] ?? '')) === 'successful' && strtolower((string) ($details[0]['orderstatus'] ?? '')) === 'closed';
            $normalized['term'] = $single && ctype_digit((string) ($details[0]['quantity'] ?? '')) ? (int) $details[0]['quantity'] : null;
        } elseif ($kind === 'renewal') {
            $normalized['auto_renew'] = self::flag($data['auto-renew'] ?? $data['renewname'] ?? null);
        } elseif ($kind === 'renewalInfo') {
            $info = $result->value('getextendinfo', $data);
            if (!is_array($info)) { return ['status' => 'unknown']; }
            $maximum = $info['maxextension'] ?? null;
            $normalized += ['expires_at' => $result->confirmedExpiry()?->format(DATE_ATOM),
                'registrar_hold' => self::flag($info['registrarhold'] ?? null),
                'max_extension' => is_string($maximum) && preg_match('/^[0-9]{1,2}$/D', $maximum) ? (int) $maximum : null,
                'grace_eligible' => null];
        } elseif ($kind === 'balance') {
            $available = $data['availablebalance'] ?? null;
            // GetBalance uses USD decimals with optional US thousands separators.
            // Keep this grammar local to balance evidence, never general prices.
            $normalized['available_balance'] = is_string($available)
                && preg_match('/^(?:0|[1-9][0-9]*|[1-9][0-9]{0,2}(?:,[0-9]{3})+)(?:\.[0-9]{1,2})?$/D', $available)
                ? str_replace(',', '', $available) : null;
        }
        return $normalized;
    }

    public function snapshot(string $domain, array $kinds = ['info', 'contacts', 'nameservers', 'lock', 'privacy', 'hosts', 'srv', 'dnssec', 'renewal']): array
    {
        $snapshot = ['nameservers' => [], 'contacts' => [], 'contact_status' => ['pending_verification' => null, 'transfer_locked' => null], 'locked' => null, 'privacy' => [], 'hosts' => [], 'srv' => [], 'dnssec' => [], 'dnssec_shape_verified' => false, 'read_status' => []];
        foreach (array_unique($kinds) as $kind) {
            if (!in_array($kind, ['info', 'contacts', 'nameservers', 'lock', 'privacy', 'hosts', 'srv', 'dnssec', 'renewal'], true)) { throw new \InvalidArgumentException('Unsupported snapshot section.'); }
            $normalized = self::normalize($kind, $this->$kind($domain));
            $snapshot['read_status'][$kind] = $normalized['status'];
            if ($normalized['status'] !== 'success') { continue; }
            if ($kind === 'contacts') {
                $snapshot['contact_status'] = ['pending_verification' => $normalized['pending_verification'] ?? null, 'transfer_locked' => $normalized['transfer_locked'] ?? null];
                $snapshot['contact_roles'] = array_keys(array_filter($normalized['collected'] ?? ['registrant' => true, 'admin' => true, 'tech' => true, 'auxbilling' => true])) ?: ['registrant'];
            }
            if ($kind === 'dnssec') { $snapshot['dnssec_shape_verified'] = $normalized['shape_verified'] ?? false; }
            // UseDNS=default or ours: the domain points at Enom's own name servers.
            if ($kind === 'nameservers') { $snapshot['registrar_dns'] = $normalized['uses_enom_dns'] ?? null; }
            if (in_array($kind, ['contacts', 'nameservers', 'hosts', 'srv', 'dnssec'], true)) { $snapshot[$kind] = $normalized[$kind] ?? []; }
            elseif ($kind === 'lock') { $snapshot['locked'] = $normalized['locked'] ?? null; }
            else { $snapshot[$kind] = $normalized; }
        }
        return $snapshot;
    }
}
