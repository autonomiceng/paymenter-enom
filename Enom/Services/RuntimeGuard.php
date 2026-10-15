<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\Server;
use RuntimeException;

/** Reads an Enom server's configuration and decides whether registrar requests may run. */
class RuntimeGuard
{
    public const ENVIRONMENTS = ['test', 'live'];

    public const BINDING = 'enom_account_binding';

    public const LIVE_URL = 'enom_live_url';

    public const ENDPOINTS = [
        'test' => 'https://resellertest.enom.com/interface.asp',
        'live' => 'https://reseller.enom.com/interface.asp',
    ];

    public static function configuration(int $serverId): array
    {
        $server = Server::findOrFail($serverId);
        if ($server->extension !== 'Enom') {
            throw new RuntimeException('Registrar configuration is unavailable.');
        }
        $settings = $server->settings;
        $config = $settings->pluck('value', 'key')->all();
        foreach (['uid', 'token'] as $key) {
            $setting = $settings->firstWhere('key', $key);
            if (!$setting || !$setting->encrypted || !is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                throw new RuntimeException('Add the Enom account ID and API token in the server settings.');
            }
        }
        if (array_key_exists('environment', $config) && !in_array($config['environment'], self::ENVIRONMENTS, true)) {
            throw new RuntimeException('Choose Test or Live as the Enom environment in the server settings.');
        }
        $config['environment'] = self::environment($config);
        $config['enabled'] = (bool) $server->enabled;
        $binding = $config[self::BINDING] ?? null;
        if (is_string($binding) && $binding !== '' && !hash_equals($binding, self::accountFingerprint($config))) {
            throw new RuntimeException('This server\'s Enom account or environment changed after domains were added. Restore the original settings, or add a new Enom server for the other account.');
        }
        if ($config['environment'] === 'live' && rtrim((string) ($config[self::LIVE_URL] ?? ''), '/') !== self::siteUrl()) {
            throw new RuntimeException('Live mode is not confirmed for this site. Confirm it on Domains → Enom setup.');
        }
        return $config;
    }

    /** Explicit `environment` wins. Older installs only had the `sandbox` flag, which always meant test. */
    public static function environment(array $config): string
    {
        $environment = $config['environment'] ?? null;
        return in_array($environment, self::ENVIRONMENTS, true) ? $environment : 'test';
    }

    public static function siteUrl(): string { return rtrim((string) config('app.url'), '/'); }

    /** Remember which Enom account this server's domains belong to. Later account or environment changes are refused. */
    public static function bindAccount(int $serverId): void
    {
        $server = Server::findOrFail($serverId);
        if ($server->settings->firstWhere('key', self::BINDING)?->value) { return; }
        $config = self::configuration($serverId);
        $server->settings()->updateOrCreate(['key' => self::BINDING], ['value' => self::accountFingerprint($config), 'type' => 'string', 'encrypted' => false]);
    }

    /**
     * Allow live requests from this site's URL only. A copied database on another site
     * (such as staging) keeps the old URL and is refused.
     */
    public static function confirmLive(int $serverId): void
    {
        $server = Server::findOrFail($serverId);
        $server->settings()->updateOrCreate(['key' => self::LIVE_URL], ['value' => self::siteUrl(), 'type' => 'string', 'encrypted' => false]);
    }

    /** Safe summary for the setup page. Never includes credentials. */
    public static function status(int $serverId): array
    {
        $server = Server::find($serverId);
        $config = $server?->settings->pluck('value', 'key')->all() ?? [];
        $environment = self::environment($config);
        try { self::configuration($serverId); $problem = null; }
        catch (\Throwable $error) { $problem = $error->getMessage(); }
        return ['environment' => $environment, 'problem' => $problem,
            'credentials' => is_string($config['uid'] ?? null) && $config['uid'] !== '' && is_string($config['token'] ?? null) && $config['token'] !== '',
            'processing' => filter_var($config['operations_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) && !self::legacyDisabled($config),
            'bound' => is_string($config[self::BINDING] ?? null) && $config[self::BINDING] !== '',
            'live_confirmed' => $environment === 'live' && rtrim((string) ($config[self::LIVE_URL] ?? ''), '/') === self::siteUrl(),
            'live_confirmed_for' => $config[self::LIVE_URL] ?? null];
    }

    /** 0.2 installs that set sandbox=false meant "send nothing". Keep that until an environment is chosen. */
    private static function legacyDisabled(array $config): bool
    {
        return !array_key_exists('environment', $config) && array_key_exists('sandbox', $config)
            && !filter_var($config['sandbox'], FILTER_VALIDATE_BOOLEAN);
    }

    public static function endpoint(string $environment): string
    {
        return self::ENDPOINTS[$environment] ?? throw new RuntimeException('Unknown Enom environment.');
    }

    /**
     * Reads (search, status, prices) need credentials only.
     * Writes (registrations, renewals, changes) also need "Process orders and changes".
     */
    public static function assertProvider(int $serverId, bool $readOnly = false): array
    {
        $config = self::configuration($serverId);
        if (!$readOnly && (!filter_var($config['operations_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) || self::legacyDisabled($config))) {
            throw new RuntimeException('Processing orders and changes is turned off for this Enom server.');
        }
        return $config;
    }

    /**
     * Identity of the Enom account a domain was registered under. Test keeps the historical
     * `sandbox:` prefix so existing bindings stay valid; a live account never matches a test one.
     */
    public static function accountFingerprint(array $config): string
    {
        $prefix = self::environment($config) === 'live' ? 'live' : 'sandbox';
        return hash('sha256', $prefix.':'.strtolower((string) ($config['uid'] ?? '')));
    }

    /** Fingerprint for a server's current account, or an impossible value when credentials are missing. */
    public static function serverFingerprint(?Server $server): string
    {
        $config = $server?->settings->pluck('value', 'key')->all() ?? [];
        $uid = $config['uid'] ?? null;
        return is_string($uid) && trim($uid) !== '' ? self::accountFingerprint($config) : 'missing-account';
    }

    public static function isTest(int $serverId): bool
    {
        $server = Server::find($serverId);
        return !$server || self::environment($server->settings->pluck('value', 'key')->all()) === 'test';
    }

    public static function observerRequestsPerMinute(array $configuration): int
    {
        $limit = $configuration['observer_requests_per_minute'] ?? 60;
        if (filter_var($limit, FILTER_VALIDATE_INT) === false || (int) $limit < 4 || (int) $limit > 300) {
            throw new RuntimeException('Registrar observation limit must be between 4 and 300 requests per minute.');
        }
        return (int) $limit;
    }

    public static function leadDays(int $serverId): int
    {
        $lead = Server::findOrFail($serverId)->settings()->where('key', 'lead_days')->first()?->value ?? 30;
        if (filter_var($lead, FILTER_VALIDATE_INT) === false || (int) $lead < 1 || (int) $lead > 90) {
            throw new RuntimeException('Registrar billing lead must be between 1 and 90 days.');
        }
        return (int) $lead;
    }

    /** @return list<string> */
    public static function defaultNameservers(int $serverId): array
    {
        $value = Server::find($serverId)?->settings->firstWhere('key', 'default_nameservers')?->value;
        return self::nameserverList(is_string($value) ? $value : '');
    }

    /** @return list<string> lowercase hostnames, blank lines and duplicates removed */
    public static function nameserverList(string $value): array
    {
        $hosts = [];
        foreach (preg_split('/[\s,]+/', strtolower($value)) ?: [] as $host) {
            $host = rtrim(trim($host), '.');
            if ($host !== '' && !in_array($host, $hosts, true)) { $hosts[] = $host; }
        }
        return $hosts;
    }

    public static function flag(int $serverId, string $key, bool $default = false): bool
    {
        $value = Server::find($serverId)?->settings->firstWhere('key', $key)?->value;
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function setting(int $serverId, string $key, mixed $default = null): mixed
    {
        return Server::find($serverId)?->settings->firstWhere('key', $key)?->value ?? $default;
    }
}
