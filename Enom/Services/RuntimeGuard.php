<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\Server;
use RuntimeException;

class RuntimeGuard
{
    public const APP_PATH = '/srv/paymenter-example';
    public const APP_URL = 'https://billing.example.test';
    public const DATABASE = 'example_database';

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
            if (!$setting || !$setting->encrypted || !is_string($config[$key] ?? null) || $config[$key] === '') {
                throw new RuntimeException('Encrypted registrar credentials are required.');
            }
        }
        return $config;
    }

    public static function assertProvider(int $serverId, bool $readOnly = false): array
    {
        $connection = config('database.default');
        if (realpath(base_path()) !== self::APP_PATH || rtrim((string) config('app.url'), '/') !== self::APP_URL
            || config('database.connections.'.$connection.'.database') !== self::DATABASE) {
            throw new RuntimeException('Registrar test requests require the configured application path, URL and database.');
        }
        $config = self::configuration($serverId);
        // Old installations had one permission. An explicit reads_enabled value
        // overrides that legacy default, including an explicit false value.
        $permission = $readOnly ? ($config['reads_enabled'] ?? $config['operations_enabled'] ?? false) : ($config['operations_enabled'] ?? false);
        if (!filter_var($config['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN) || !filter_var($permission, FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Registrar sandbox permission is disabled.');
        }
        return $config;
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
}
