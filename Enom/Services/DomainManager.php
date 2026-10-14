<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\User;
use InvalidArgumentException;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;

/** Owner-scoped presentation and validated durable management requests. */
class DomainManager
{
    public const ACTIONS = ['nameservers', 'contacts', 'lock', 'epp_sync', 'privacy_enable', 'privacy_disable', 'hosts', 'srv', 'dnssec_add', 'dnssec_delete'];

    public const CONTACT_FIELDS = ['first_name', 'last_name', 'organization_name', 'address1', 'address2', 'city', 'state_province', 'postal_code', 'country', 'email_address', 'phone', 'phone_ext', 'job_title', 'fax'];
    public const REQUIRED_CONTACT_FIELDS = ['first_name', 'last_name', 'address1', 'city', 'postal_code', 'country', 'email_address', 'phone'];
    public const CONTACT_MAX_BYTES = 255;
    public const NANP_COUNTRIES = ['US', 'CA'];
    public const CONTACT_MESSAGES = [
        'text' => 'Remove tabs or control characters, or shorten this entry. The limit is 255 bytes; accented characters and symbols may use 2 to 4 bytes each.',
        'required' => 'Complete this required contact field.',
        'country' => 'Choose a country from the list.',
        'email' => 'Enter an address like name@example.com.',
        'phone' => 'Include a dialing code, for example +1.5552345678 or +44 20 7946 0123. For US/Canada national-format numbers, area codes and exchanges cannot start with 0 or 1.',
        'phone_country' => 'Choose a country or include the dialing code.',
    ];

    public function __construct(private DomainOperations $operations) {}

    public function owned(User $user, int $domainId): Domain
    {
        $domain = Domain::query()->where('user_id', $user->id)->whereKey($domainId)->firstOrFail();
        $service = $domain->service;
        abort_unless($service && (int) $service->user_id === (int) $user->id
            && (int) $service->product?->server_id === (int) $domain->server_id
            && $service->product?->server?->extension === 'Enom', 404);

        return $domain;
    }

    public function snapshot(User $user, int $domainId): array
    {
        $domain = $this->owned($user, $domainId);
        $cached = self::safeSnapshot($domain->snapshot ?? []);
        $unresolved = $domain->operations()->whereIn('state', DomainOperations::UNRESOLVED_STATES)->pluck('state');
        // Owner-scoped display fields only. Safe codes pass through an allowlist; nothing else is exposed.
        $recent = Operation::query()->where('domain_id', $domain->id)->latest('id')->limit(20)
            ->get(['id', 'domain_id', 'service_id', 'action', 'state', 'invoice_id', 'invoice_item_id', 'safe_code', 'created_at']);
        // A recorded invoice ID is shown only after exact owner, domain, service and item checks.
        // Unverified or foreign links never enter this DTO, whoever is viewing.
        $links = DomainAdministration::linkedInvoices($recent, [(int) $domain->id => ['service_id' => (int) $domain->service_id, 'user_id' => (int) $domain->user_id]]);
        $operations = $recent->map(fn ($operation) => [
            'id' => $operation->id, 'action' => $operation->action,
            'state' => $operation->state, 'created_at' => $operation->created_at,
            'invoice_id' => $links[(int) $operation->id]['id'] ?? null, 'outcome' => DomainPresentation::outcome($operation->safe_code),
        ]);
        $automation = DomainAdministration::customer($domain);
        $staffHold = DomainOperations::writesPaused($domain);
        $capabilities = $unresolved->isNotEmpty() || $staffHold ? [] : array_filter($domain->capabilities ?? [], fn ($value) => $value === true);
        if ($domain->billing_managed && (!$automation['enabled'] || !$domain->provider_expires_at
            || !in_array($domain->status, ['active', 'expired', 'grace'], true)
            || !($price = RegistrarMoney::decimal((string) $domain->service->price)) || $price === '0.00')) {
            // Registrar support alone does not make a customer invoice available.
            unset($capabilities['renew']);
        }

        return [
            'id' => $domain->id, 'domain' => $domain->domain, 'status' => $domain->status,
            'progress' => DomainAdministration::progress([$domain])[(int) $domain->id] ?? null,
            'provider_expires_at' => $domain->provider_expires_at,
            'billing_due_at' => $domain->service->expires_at, 'service_id' => $domain->service_id,
            'last_synced_at' => $domain->last_synced_at,
            'renewal_enabled' => !$domain->service->cancellation,
            'writes_blocked' => $unresolved->isNotEmpty() || $staffHold, 'needs_review' => $unresolved->contains('uncertain') || $staffHold,
            'capabilities' => $capabilities,
            'operations' => $operations, 'cached' => $cached,
            'automation' => $automation,
            // Public retail fact only. A zero price never produces a customer renewal invoice.
            'zero_retail' => RegistrarMoney::decimal((string) $domain->service->price) === '0.00',
            'hosts_digest' => self::digest($cached['hosts']), 'srv_digest' => self::digest($cached['srv']),
        ];
    }

    public function request(User $user, int $domainId, string $action, array $payload): Operation
    {
        $domain = $this->owned($user, $domainId);
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Choose a supported domain action.');
        }
        if (($domain->capabilities[$action] ?? false) !== true) {
            throw new InvalidArgumentException('This action is unavailable for this domain. Refresh its status or contact support.');
        }
        $nonce = $payload['_request_key'] ?? '';
        if (!is_string($nonce) || !preg_match('/\A[a-f0-9]{64}\z/', $nonce)) {
            throw new InvalidArgumentException('Reload this page before saving changes.');
        }
        $validated = self::validatePayload($action, $payload);
        if (in_array($action, ['hosts', 'srv'], true)) {
            $original = $domain->snapshot ?? [];
            if (!is_array($original[$action] ?? null) || count($original[$action]) > 50) {
                throw new InvalidArgumentException('The complete DNS list is unavailable. Refresh registrar status before editing.');
            }
            $snapshot = self::safeSnapshot($original);
            if (!hash_equals(self::digest($snapshot[$action]), $validated['preimage_digest'])) {
                throw new InvalidArgumentException('The DNS records changed. Reload the page and review them before saving.');
            }
        }

        return $this->operations->enqueueManagement($user, $domainId, $action, $validated, $nonce);
    }

    public static function digest(array $records): string
    {
        return hash('sha256', json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Deliberately excludes EPP, provider envelopes, order IDs and arbitrary error text. */
    public static function safeSnapshot(array $snapshot): array
    {
        $result = ['nameservers' => [], 'contacts' => [], 'locked' => null, 'privacy' => [], 'contact_status' => [], 'hosts' => [], 'srv' => [], 'dnssec' => []];
        $nameservers = is_array($snapshot['nameservers'] ?? null) ? $snapshot['nameservers'] : [];
        foreach (array_slice($nameservers, 0, 12) as $server) {
            if (is_string($server) && strlen($server) <= 253) {
                $result['nameservers'][] = $server;
            }
        }
        $contacts = is_array($snapshot['contacts'] ?? null) ? $snapshot['contacts'] : [];
        foreach (['registrant', 'admin', 'tech', 'auxbilling'] as $type) {
            $contact = is_array($contacts[$type] ?? null) ? $contacts[$type] : [];
            foreach (self::CONTACT_FIELDS as $field) {
                $value = $contact[$field] ?? null;
                if (is_string($value) && strlen($value) <= 255) {
                    $result['contacts'][$type][$field] = $value;
                }
            }
        }
        $result['locked'] = is_bool($snapshot['locked'] ?? null) ? $snapshot['locked'] : null;
        foreach (['pending_verification', 'transfer_locked'] as $key) {
            $value = $snapshot['contact_status'][$key] ?? null;
            $result['contact_status'][$key] = is_bool($value) ? $value : null;
        }
        foreach (['allowed', 'exists', 'enabled'] as $key) {
            $result['privacy'][$key] = is_bool($snapshot['privacy'][$key] ?? null) ? $snapshot['privacy'][$key] : null;
        }
        foreach (['hosts' => ['name', 'host', 'type', 'address', 'mx_pref'], 'srv' => ['service', 'protocol', 'priority', 'weight', 'port', 'target', 'host_id'], 'dnssec' => ['algorithm', 'digest', 'digest_type', 'key_tag']] as $kind => $fields) {
            $records = is_array($snapshot[$kind] ?? null) ? $snapshot[$kind] : [];
            foreach (array_slice($records, 0, 50) as $record) {
                if (!is_array($record)) {
                    continue;
                }
                $safe = [];
                foreach ($fields as $field) {
                    if (isset($record[$field]) && is_scalar($record[$field]) && strlen((string) $record[$field]) <= 2048) {
                        $safe[$field] = $record[$field];
                    }
                }
                if ($safe) {
                    $result[$kind][] = $safe;
                }
            }
        }

        return $result;
    }

    /** Pure validation seam shared by customer forms and offline tests. */
    public static function validatePayload(string $action, array $payload): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Choose a supported domain action.');
        }
        if (!in_array($payload['confirmed'] ?? null, [true, 1, '1'], true)) {
            throw new InputValidationException(['confirmed' => 'Confirm the changes before saving.']);
        }
        if ($action === 'nameservers') {
            $servers = $payload['nameservers'] ?? [];
            if (is_string($servers)) {
                $servers = preg_split('/\R/', trim($servers));
            }
            if (!is_array($servers) || count($servers) < 2 || count($servers) > 12) {
                throw new InvalidArgumentException('Enter between 2 and 12 nameservers.');
            }
            $servers = array_map(fn ($server) => strtolower(self::text($server, 253)), $servers);
            foreach ($servers as $index => $server) {
                if (!self::hostname($server)) {
                    throw new InputValidationException(['nameservers' => 'Enter valid nameserver hostnames.']);
                }
                $server = str_ends_with($server, '.') ? substr($server, 0, -1) : $server;
                if (filter_var($server, FILTER_VALIDATE_IP)) {
                    throw new InputValidationException(['nameservers' => 'Use nameserver hostnames, not IP addresses.']);
                }
                $servers[$index] = $server;
            }
            if (count(array_unique($servers)) !== count($servers)) {
                throw new InputValidationException(['nameservers' => 'Each nameserver must be different.']);
            }

            return ['nameservers' => $servers];
        }
        if ($action === 'contacts') {
            $type = $payload['contact_type'] ?? '';
            if (!in_array($type, ['registrant', 'admin', 'tech', 'auxbilling'], true)) {
                throw new InputValidationException(['contact_type' => 'Choose a supported contact type.']);
            }
            if (!is_array($payload['contact'] ?? null)) {
                throw new InputValidationException(['contact' => 'Complete the contact fields.']);
            }
            $contact = [];
            $errors = [];
            foreach (self::CONTACT_FIELDS as $field) {
                try {
                    $contact[$field] = self::text($payload['contact'][$field] ?? '', self::CONTACT_MAX_BYTES, false);
                } catch (InvalidArgumentException) {
                    $contact[$field] = '';
                    $errors['contact.'.$field] = self::CONTACT_MESSAGES['text'];
                }
            }
            foreach (self::REQUIRED_CONTACT_FIELDS as $field) {
                if ($contact[$field] === '') {
                    $errors['contact.'.$field] ??= self::CONTACT_MESSAGES['required'];
                }
            }
            $contact['country'] = strtoupper($contact['country']);
            $countries = function_exists('config') ? config('app.countries', []) : [];
            if (!preg_match('/\A[A-Z]{2}\z/', $contact['country']) || ($countries && !array_key_exists($contact['country'], $countries))) {
                $errors['contact.country'] = self::CONTACT_MESSAGES['country'];
            }
            if (!filter_var($contact['email_address'], FILTER_VALIDATE_EMAIL)) {
                $errors['contact.email_address'] = self::CONTACT_MESSAGES['email'];
            }
            $phone = self::phone($contact['phone'], $contact['country']);
            if ($phone === null) {
                $errors['contact.phone'] = self::CONTACT_MESSAGES['phone'];
            } else {
                $contact['phone'] = $phone;
            }
            if ($errors) {
                throw new InputValidationException($errors);
            }

            return ['contact_type' => $type, 'contact' => $contact];
        }
        if ($action === 'lock') {
            if (!in_array($payload['locked'] ?? null, ['0', '1', 0, 1, false, true], true)) {
                throw new InvalidArgumentException('Choose the registrar lock setting.');
            }

            return ['locked' => (bool) $payload['locked']];
        }
        if (in_array($action, ['epp_sync', 'privacy_enable', 'privacy_disable'], true)) {
            return [];
        }
        if (in_array($action, ['hosts', 'srv'], true)) {
            $records = $payload['records'] ?? null;
            $digest = $payload['preimage_digest'] ?? '';
            if (!is_array($records) || count($records) > 100 || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
                throw new InvalidArgumentException('Reload the DNS editor and review the complete record list.');
            }
            $validated = [];
            foreach ($records as $index => $record) {
                if (!is_array($record)) {
                    throw new InvalidArgumentException('Enter valid DNS records.');
                }
                if (in_array($record['_delete'] ?? null, ['1', 1, true], true)) {
                    continue;
                }
                $nonempty = array_filter($record, fn ($value, $key) => $key !== '_delete' && $value !== '' && $value !== null, ARRAY_FILTER_USE_BOTH);
                if (!$nonempty) {
                    continue;
                }
                $validated[] = $action === 'hosts' ? self::hostRecord($record) : self::srvRecord($record, $index);
            }
            if (count($validated) > 50) {
                throw new InvalidArgumentException('Keep no more than 50 DNS records.');
            }
            // The documented zero-record clearing contract is unverified. Fail closed.
            if (!$validated) {
                throw new InvalidArgumentException('Removing every DNS record is unavailable in this sandbox. Keep at least one record.');
            }

            return ['records' => $validated, 'preimage_digest' => $digest];
        }

        return ['ds' => self::dsRecord($payload['ds'] ?? [])];
    }

    private static function text(mixed $value, int $max, bool $required = true): string
    {
        if (!is_string($value) || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Enter valid text without control characters.');
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new InvalidArgumentException('Complete every required field.');
        }

        return $value;
    }

    public static function phone(string $value, string $country): ?string
    {
        // An explicit separator identifies the dialing code without guessing it from country.
        if (preg_match('/\A\+([1-9][0-9]{0,2})[. -]+([0-9() -]+)\z/', $value, $parts)) {
            $subscriber = preg_replace('/[() -]/', '', $parts[2]);
            if (preg_match('/\A[0-9]{4,14}\z/', $subscriber) && strlen($parts[1].$subscriber) <= 15) {
                return '+'.$parts[1].'.'.$subscriber;
            }
        }
        // NANP national input has an unambiguous +1 code for these two countries.
        if (in_array($country, self::NANP_COUNTRIES, true) && preg_match('/\A\+?[0-9() .-]+\z/', $value)) {
            $digits = preg_replace('/[^0-9]/', '', $value);
            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                $digits = substr($digits, 1);
            } elseif (str_starts_with($value, '+')) {
                return null;
            }
            if (preg_match('/\A[2-9][0-9]{2}[2-9][0-9]{6}\z/', $digits)) {
                return '+1.'.$digits;
            }
        }

        return null;
    }

    private static function hostname(string $value): bool
    {
        return strlen($value) <= 253 && (bool) preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?\z/i', $value);
    }

    private static function integer(mixed $value, int $max = 65535): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[0-9]+\z/', (string) $value) || (int) $value > $max) {
            throw new InvalidArgumentException('Enter a whole number within the supported range.');
        }

        return (int) $value;
    }

    private static function hostRecord(array $record): array
    {
        $name = self::text($record['name'] ?? $record['host'] ?? '', 253);
        $type = strtoupper(self::text($record['type'] ?? '', 10));
        $address = self::text($record['address'] ?? '', 2048, false);
        // TXT content is data. Validate its bytes without stripping meaningful spaces.
        if ($type === 'TXT') { $address = $record['address'] ?? ''; }
        if ($address === '') { throw new InvalidArgumentException('Complete every required field.'); }
        if (!preg_match('/\A(?:@|\*|(?:\*\.)?[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\.?)\z/i', $name)
            || !in_array($type, ['A', 'AAAA', 'CNAME', 'URL', 'FRAME', 'MX', 'MXE', 'TXT'], true)) {
            throw new InvalidArgumentException('Choose a supported DNS record type and valid host name.');
        }
        if (($type === 'A' && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            || ($type === 'AAAA' && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
            || (in_array($type, ['CNAME', 'MX'], true) && (!str_ends_with($address, '.') || !self::hostname($address)))
            || (in_array($type, ['URL', 'FRAME'], true) && (!filter_var($address, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($address, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)))
            || ($type === 'MXE' && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
            throw new InvalidArgumentException('Enter an address that matches the DNS record type. Mail and alias targets need a trailing dot.');
        }

        return ['name' => $name, 'type' => $type, 'address' => $address, 'mx_pref' => $type === 'MX' ? self::integer(($record['mx_pref'] ?? '') === '' ? '10' : $record['mx_pref']) : 10];
    }

    private static function srvRecord(array $record, int|string $index): array
    {
        $service = self::text($record['service'] ?? '', 14);
        try {
            $protocol = strtolower(self::text($record['protocol'] ?? '', 3));
        } catch (InvalidArgumentException) {
            throw new InputValidationException(['records.'.$index.'.protocol' => 'Choose TCP or UDP.']);
        }
        if (!in_array($protocol, ['tcp', 'udp'], true)) {
            throw new InputValidationException(['records.'.$index.'.protocol' => 'Choose TCP or UDP.']);
        }
        $target = self::text($record['target'] ?? '', 253);
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,12}[a-z0-9])?\z/', $service)
            || !in_array($protocol, ['tcp', 'udp'], true) || !str_ends_with($target, '.') || !self::hostname($target)) {
            throw new InvalidArgumentException('Use a valid SRV service, tcp or udp, and a fully qualified target ending in a dot.');
        }
        $result = ['service' => $service, 'protocol' => $protocol, 'target' => $target];
        foreach (['priority', 'weight', 'port'] as $field) {
            $result[$field] = self::integer($record[$field] ?? '');
        }
        if (($record['host_id'] ?? '') !== '') {
            $result['host_id'] = self::integer($record['host_id'], PHP_INT_MAX);
        }

        return $result;
    }

    private static function dsRecord(array $ds): array
    {
        $algorithm = self::integer($ds['algorithm'] ?? '');
        $digestType = self::integer($ds['digest_type'] ?? '');
        $digest = strtoupper(self::text($ds['digest'] ?? '', 64));
        if (!in_array($algorithm, [3, 5, 7, 8, 10, 12, 13, 14], true) || !in_array($digestType, [1, 2], true)
            || !ctype_xdigit($digest) || strlen($digest) !== ($digestType === 1 ? 40 : 64)) {
            throw new InvalidArgumentException('Enter a supported DS algorithm and a matching SHA-1 or SHA-256 hexadecimal digest.');
        }

        return ['algorithm' => $algorithm, 'digest_type' => $digestType, 'digest' => $digest, 'key_tag' => self::integer($ds['key_tag'] ?? '')];
    }
}
