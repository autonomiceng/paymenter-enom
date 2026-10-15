<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\Service;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Paymenter\Extensions\Servers\Enom\Api\DomainName;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;

/** Authenticated observations carry no billing or registrar-operation authority. */
final class DomainObservation
{
    public const STATUSES = ['active', 'registration_pending', 'transfer_pending', 'expired', 'grace', 'redemption', 'needs_review'];
    private const SOURCES = ['info', 'expiry', 'renewal', 'renewalInfo'];

    public function __construct(private ?EnomApi $api = null, private ?Closure $guard = null) {}

    public function observe(int $domainId): string
    {
        $domain = Domain::find($domainId);
        if (!$domain || !$this->binding($domain)) { return 'ineligible'; }
        try { $configuration = $this->configuration((int) $domain->server_id); }
        catch (\Throwable) { $this->recordFailure($domainId, 'guard_refused'); return 'guard_refused'; }
        $account = RuntimeGuard::accountFingerprint($configuration);
        // Duplicate native server rows for one provider account share the same budget.
        $lock = Cache::lock('enom:observe:account:'.$account, 180);
        if (!$lock->get()) { return 'busy'; }
        try {
            $capture = DB::transaction(function () use ($domainId, $account) {
                $domain = Domain::lockForUpdate()->find($domainId);
                if (!$domain || !($binding = $this->binding($domain))) { return null; }
                $observation = $domain->observation ?? [];
                $sequence = (int) ($observation['sequence'] ?? 0) + 1;
                $started = now()->toIso8601String();
                $observation = array_replace($observation, ['version' => 1, 'sequence' => $sequence,
                    'account_fingerprint' => $account, 'attempted_at' => $started, 'status' => 'running']);
                $domain->update(['observation' => $observation]);
                return ['binding' => $binding, 'sequence' => $sequence, 'started_at' => $started, 'account' => $account];
            });
            if (!$capture) { return 'ineligible'; }
            $budget = 'enom:observe:requests:'.$account;
            try { $limit = $this->accountLimit($account, $configuration); }
            catch (\Throwable) { return $this->apply($domainId, $capture, [], 'configuration_invalid'); }
            if (RateLimiter::remaining($budget, $limit) < count(self::SOURCES)) {
                return $this->apply($domainId, $capture, [], 'rate_limited');
            }
            $api = $this->api ?? $this->client((int) $domain->server_id, $configuration, fn () => $this->assertAccount((int) $domain->server_id, $account));
            if (!$api->isReadOnly()) { return $this->apply($domainId, $capture, [], 'read_only_required'); }
            $results = [];
            foreach (self::SOURCES as $source) {
                try {
                    $this->assertAccount((int) $domain->server_id, $account);
                    RateLimiter::hit($budget, 60);
                    $results[$source] = $api->$source($capture['binding']['domain']);
                } catch (\Throwable) {
                    return $this->apply($domainId, $capture, $results, 'guard_refused');
                }
                // Membership must be established before accepting any further read.
                if ($source === 'info' && !$this->authenticated($results['info'], $capture['binding'], $configuration['uid'])) {
                    return $this->apply($domainId, $capture, [], 'identity_unconfirmed');
                }
            }
            return $this->apply($domainId, $capture, $results);
        } finally { $lock->release(); }
    }

    /** Staff-requested pricing reads; they never create funding or operations. */
    public function observeRenewalQuote(int $domainId): string
    {
        $domain = Domain::find($domainId);
        if (!$domain || !$this->binding($domain) || !$domain->provider_expires_at) { return 'ineligible'; }
        try { $configuration = $this->configuration((int) $domain->server_id); }
        catch (\Throwable) { $this->recordFailure($domainId, 'guard_refused'); return 'guard_refused'; }
        $account = RuntimeGuard::accountFingerprint($configuration);
        $lock = Cache::lock('enom:observe:account:'.$account, 180);
        if (!$lock->get()) { return 'busy'; }
        try {
            $capture = DB::transaction(function () use ($domainId, $account) {
                $domain = Domain::lockForUpdate()->find($domainId);
                if (!$domain || !($binding = $this->binding($domain)) || !$domain->provider_expires_at) { return null; }
                $observation = $domain->observation ?? [];
                $sequence = (int) ($observation['sequence'] ?? 0) + 1;
                $started = now()->toIso8601String();
                $domain->update(['observation' => array_replace($observation, ['version' => 1, 'sequence' => $sequence,
                    'account_fingerprint' => $account, 'quote_attempted_at' => $started, 'quote_status' => 'running'])]);
                return ['binding' => $binding, 'sequence' => $sequence, 'started_at' => $started, 'account' => $account];
            });
            if (!$capture) { return 'ineligible'; }
            $budget = 'enom:observe:requests:'.$account;
            try { $limit = $this->accountLimit($account, $configuration); }
            catch (\Throwable) { return $this->applyQuote($domainId, $capture, null, 'configuration_invalid'); }
            // Reserve room for the maximum four commands, including expired inventory.
            if (RateLimiter::remaining($budget, $limit) < 4) { return $this->applyQuote($domainId, $capture, null, 'rate_limited'); }
            $serverId = $capture['binding']['server_id'];
            $api = $this->api ?? $this->client((int) $serverId, $configuration, fn () => $this->assertAccount($serverId, $account));
            if (!$api->isReadOnly()) { return $this->applyQuote($domainId, $capture, null, 'read_only_required'); }
            try {
                $evidence = $api->renewalEvidence($capture['binding']['domain'], function (string $kind, $info) use ($serverId, $account, $budget, $capture, $configuration) {
                    $this->assertAccount($serverId, $account);
                    if ($kind !== 'info' && !$this->authenticated($info, $capture['binding'], $configuration['uid'])) {
                        throw new \RuntimeException('Registrar identity is unconfirmed.');
                    }
                    RateLimiter::hit($budget, 60);
                }, new \DateTimeImmutable($capture['started_at']));
            } catch (\Throwable) { return $this->applyQuote($domainId, $capture, null, 'identity_or_guard_unconfirmed'); }
            if (!$this->authenticated($evidence['info'], $capture['binding'], $configuration['uid'])) {
                return $this->applyQuote($domainId, $capture, null, 'identity_unconfirmed');
            }
            $base = $capture['binding']['confirmed_expiry'];
            if ($evidence['info']->confirmedExpiry()?->format('Y-m-d') !== $base) {
                return $this->applyQuote($domainId, $capture, null, 'expiry_drift');
            }
            $quoteBinding = $capture['binding'] + ['term' => 1, 'base_expires_at' => $base,
                'observed_at' => $capture['started_at']];
            // A newly registered fixture can lack an anchor, but an anchored registration
            // can never replace it with the ID from a daily or pricing observation.
            if (!$quoteBinding['provider_domain_id']) {
                $quoteBinding['provider_domain_id'] = EnomApi::normalize('info', $evidence['info'])['domain_name_id'];
            }
            $quote = RegistrarMoney::renewalQuote($evidence['extend'], $quoteBinding, $evidence['check'], $evidence['expired'], $evidence['info']);
            $quote += ['provider_domain_id' => $quoteBinding['provider_domain_id'], 'server_id' => $serverId,
                'service_id' => $capture['binding']['service_id'], 'user_id' => $capture['binding']['user_id'],
                'account_fingerprint' => $account, 'authority_generation' => $capture['binding']['authority_generation'],
                'confirmed_revision' => $capture['binding']['confirmed_revision']];
            return $this->applyQuote($domainId, $capture, $quote, $quote['complete'] ? null : 'quote_unconfirmed');
        } finally { $lock->release(); }
    }

    private function accountLimit(string $account, array $configuration): int
    {
        $configured = RuntimeGuard::observerRequestsPerMinute($configuration);
        $key = 'enom:observe:account-limit:'.$account;
        $remembered = Cache::get($key);
        $limit = is_int($remembered) && $remembered >= 4 && $remembered <= 300 ? min($configured, $remembered) : $configured;
        // Called while the account lock is held. A higher alias cannot reset a
        // previously encountered lower account budget during this daily window.
        Cache::put($key, $limit, 86400);
        return $limit;
    }

    /** Read-only client for the server's own environment and domain endings. */
    private function client(int $serverId, array $configuration, callable $guard): EnomApi
    {
        $suffixes = \Paymenter\Extensions\Servers\Enom\Models\Tld::query()->where('server_id', $serverId)->pluck('tld')
            ->merge(Domain::query()->where('server_id', $serverId)->distinct()->pluck('tld'))->all();
        return (new EnomApi(new RegistrarClient(trim($configuration['uid']), trim($configuration['token']), null, $guard, true,
            RuntimeGuard::environment($configuration))))->withSuffixes($suffixes);
    }

    private function configuration(int $serverId): array
    {
        $configuration = $this->guard ? ($this->guard)($serverId, true) : RuntimeGuard::assertProvider($serverId, true);
        if (!is_array($configuration) || !is_string($configuration['uid'] ?? null) || $configuration['uid'] === ''
            || !is_string($configuration['token'] ?? null) || $configuration['token'] === '') {
            throw new \RuntimeException('Registrar read permission is unavailable.');
        }
        return $configuration;
    }

    private function assertAccount(int $serverId, string $account): void
    {
        $configuration = $this->configuration($serverId);
        if (!hash_equals($account, RuntimeGuard::accountFingerprint($configuration))) {
            throw new \RuntimeException('Registrar account binding changed.');
        }
    }

    private function binding(Domain $domain): ?array
    {
        $service = Service::find($domain->service_id);
        if (!$service || (int) $service->user_id !== (int) $domain->user_id
            || (int) $service->product?->server_id !== (int) $domain->server_id
            || $service->product?->server?->extension !== 'Enom' || !in_array($domain->status, self::STATUSES, true)) { return null; }
        try { $parts = DomainName::parse($domain->domain, [$domain->tld]); }
        catch (\Throwable) { return null; }
        if ($parts['domain'] !== $domain->domain || $parts['sld'] !== $domain->sld || $parts['tld'] !== $domain->tld) { return null; }
        return ['domain' => $domain->domain, 'sld' => $domain->sld, 'tld' => $domain->tld,
            'service_id' => (int) $domain->service_id, 'server_id' => (int) $domain->server_id, 'user_id' => (int) $domain->user_id,
            'domain_status' => $domain->status, 'service_status' => $service->status,
            'authority_generation' => (int) $domain->authority_generation, 'confirmed_revision' => (int) $domain->confirmed_revision,
            'provider_domain_id' => $domain->provider_domain_id,
            'confirmed_expiry' => $domain->provider_expires_at?->format('Y-m-d')];
    }

    private function authenticated($result, array $binding, string $uid): bool
    {
        $info = EnomApi::normalize('info', $result);
        return ($info['status'] ?? null) === 'success' && is_string($info['domain'] ?? null)
            && strtolower($info['domain']) === $binding['domain']
            && ($info['sld'] === null || strtolower((string) $info['sld']) === $binding['sld'])
            && ($info['tld'] === null || strtolower((string) $info['tld']) === $binding['tld'])
            && is_string($info['belongs_to'] ?? null) && strcasecmp($info['belongs_to'], $uid) === 0
            && is_string($info['domain_name_id'] ?? null) && preg_match('/^[1-9][0-9]{0,19}$/D', $info['domain_name_id'])
            && ($binding['provider_domain_id'] === null || $info['domain_name_id'] === $binding['provider_domain_id']);
    }

    private function apply(int $domainId, array $capture, array $results, ?string $error = null): string
    {
        return DB::transaction(function () use ($domainId, $capture, $results, $error) {
            $domain = Domain::lockForUpdate()->find($domainId);
            if (!$domain || $this->binding($domain) !== $capture['binding']
                || (int) (($domain->observation ?? [])['sequence'] ?? 0) !== $capture['sequence']) { return 'stale'; }
            try { $this->assertAccount((int) $domain->server_id, $capture['account']); }
            catch (\Throwable) { return 'stale'; }
            $at = now()->toIso8601String();
            $observation = $domain->observation ?? [];
            $snapshot = $domain->snapshot ?? [];
            $success = true;
            foreach (self::SOURCES as $source) {
                $result = $results[$source] ?? null;
                $healthy = $result && $result->status === 'success';
                $normalized = null;
                if ($healthy && $source === 'expiry') {
                    $expiry = $result->confirmedExpiry();
                    $healthy = $expiry !== null;
                    $normalized = ['expires_at' => $expiry?->format(DATE_ATOM)];
                } elseif ($healthy) {
                    $normalized = EnomApi::normalize($source, $result);
                    $healthy = ($normalized['status'] ?? null) === 'success';
                }
                $health = $observation['sources'][$source] ?? [];
                $health['attempted_at'] = $capture['started_at'];
                $health['status'] = $healthy ? 'fresh' : 'stale';
                $health['error'] = $healthy ? null : ($error ?? ($result ? 'provider_'.$result->status : 'not_read'));
                if ($healthy) {
                    $health['succeeded_at'] = $at;
                    if ($source === 'info') {
                        $registration = strtolower((string) ($normalized['registration_status'] ?? ''));
                        $purchase = strtolower((string) ($normalized['purchase_status'] ?? ''));
                        $health['value'] = ['domain_name_id' => $normalized['domain_name_id'],
                            'registration_status' => in_array($registration, ['registered', 'processing', 'hosted', 'null'], true) ? $registration : 'unknown',
                            'purchase_status' => in_array($purchase, ['paid', 'processing', 'null'], true) ? $purchase : 'unknown',
                            'expires_at' => $normalized['expires_at'], 'restorable' => $normalized['restorable'], 'grace_eligible' => null];
                        // Encrypted observation stores no raw provider account identity.
                        $snapshot['info'] = $health['value'] + ['status' => 'success'];
                    } else {
                        $health['value'] = $normalized;
                        if ($source !== 'expiry') { $snapshot[$source] = $normalized; }
                    }
                    $snapshot['read_status'][$source] = 'success';
                } else {
                    $success = false;
                    $snapshot['read_status'][$source] = $result?->status ?? 'unknown';
                }
                $observation['sources'][$source] = $health;
            }
            if (isset($results['renewalInfo'])) {
                $quote = RegistrarMoney::renewalQuote($results['renewalInfo'], $capture['binding'] + ['observed_at' => $capture['started_at']]);
                if ($quote['state'] !== 'unconfirmed' && ($observation['renewal_quote']['complete'] ?? false) !== true) { $observation['renewal_quote'] = $quote; }
            }
            $expiry = $observation['sources']['expiry']['value']['expires_at'] ?? null;
            $day = is_string($expiry) ? substr($expiry, 0, 10) : null;
            $confirmed = $capture['binding']['confirmed_expiry'];
            $observation['expiry_drift'] = $day && $confirmed ? ($day === $confirmed ? 'none' : ($day > $confirmed ? 'later' : 'earlier')) : 'unknown';
            $observation['grace_eligible'] = null;
            $observation['status'] = $success ? 'fresh' : 'stale';
            $observation['error'] = $error;
            $observation['completed_at'] = $at;
            if ($success) { $observation['succeeded_at'] = $at; }
            // No confirmed expiry/status, service lifecycle, invoice or operation updates.
            $domain->update(['observation' => $observation, 'snapshot' => $snapshot]);
            return $error ?? ($success ? 'observed' : 'partial');
        });
    }

    private function applyQuote(int $domainId, array $capture, ?array $quote, ?string $error): string
    {
        return DB::transaction(function () use ($domainId, $capture, $quote, $error) {
            $domain = Domain::lockForUpdate()->find($domainId);
            if (!$domain || $this->binding($domain) !== $capture['binding']
                || (int) (($domain->observation ?? [])['sequence'] ?? 0) !== $capture['sequence']) { return 'stale'; }
            try { $this->assertAccount((int) $domain->server_id, $capture['account']); }
            catch (\Throwable) { return 'stale'; }
            $observation = $domain->observation ?? [];
            $observation['quote_status'] = $error ? 'stale' : 'fresh';
            $observation['quote_error'] = $error;
            $observation['quote_completed_at'] = now()->toIso8601String();
            if ($quote && $quote['complete']) {
                $observation['renewal_quote'] = $quote;
                $observation['quote_succeeded_at'] = now()->toIso8601String();
            }
            $domain->update(['observation' => $observation]);
            return $error ?? 'quoted';
        });
    }

    private function recordFailure(int $domainId, string $error): void
    {
        DB::transaction(function () use ($domainId, $error) {
            $domain = Domain::lockForUpdate()->find($domainId);
            if (!$domain || !$this->binding($domain)) { return; }
            $observation = $domain->observation ?? [];
            $observation['status'] = 'stale';
            $observation['error'] = $error;
            $observation['attempted_at'] = now()->toIso8601String();
            $domain->update(['observation' => $observation]);
        });
    }
}
