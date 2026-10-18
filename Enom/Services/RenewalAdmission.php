<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Models\Service;
use Paymenter\Extensions\Servers\Enom\Api\DomainName;
use Paymenter\Extensions\Servers\Enom\Api\EnomApi;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarClient;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use Throwable;

/** Fresh read admission only. The executor owns claims, persistence and writes. */
final class RenewalAdmission
{
    public const MAX_READS = 6;
    public const READ_BUDGET_SECONDS = self::MAX_READS * RegistrarClient::REQUEST_TIMEOUT_SECONDS;

    public function __construct(private DomainOperations $operations) {}

    /** @return array{allowed:bool,code:string,quote:?array,command:?string,account_fingerprint?:string,provider_domain_id?:string} */
    public function check(Operation $operation, EnomApi $api): array
    {
        $quote = null;
        try {
            $operation = $operation->fresh() ?? $operation;
            $domain = $operation->domain;
            if ($code = $this->localRefusal($operation, $domain)) { return $this->refuse($code); }
            $configuration = $this->operations->providerConfiguration((int) $domain->server_id);
            $account = self::accountFingerprint($configuration);
            $policy = $operation->policy ?? [];
            if (isset($policy['account_fingerprint']) && $policy['account_fingerprint'] !== $account) {
                return $this->refuse('renewal_account_changed');
            }
            $binding = $this->binding($operation, $domain);
            $clock = now()->toImmutable();
            $complimentary = $operation->funding_basis === 'complimentary';
            if ($domain->renewal_policy['automatic_spend_paused'] ?? false) {
                return $this->refuse('automatic_spend_paused');
            }
            $guard = function () use ($domain, $account): void {
                $current = $this->operations->providerConfiguration((int) $domain->server_id);
                if (!hash_equals($account, self::accountFingerprint($current))) {
                    throw new \LogicException('renewal_account_changed');
                }
            };
            $reads = 0;
            $read = function () use (&$reads, $guard): void {
                if (++$reads > self::MAX_READS) { throw new \LogicException('renewal_read_budget_exhausted'); }
                $guard();
            };
            $evidence = $api->renewalEvidence($domain->domain, function (string $kind, $info) use ($read, $domain, $configuration) {
                $read();
                if ($info instanceof RegistrarResult && ($code = $this->membershipRefusal($info, $domain, $configuration['uid']))) {
                    throw new \LogicException($code);
                }
            }, $clock);
            if ($code = $this->membershipRefusal($evidence['info'], $domain, $configuration['uid'])) { return $this->refuse($code); }
            $expiry = $evidence['info']->confirmedExpiry();
            if (!$expiry) { return $this->refuse('renewal_state_unconfirmed'); }
            $expired = $expiry <= $clock;
            if ($expired && !filter_var($configuration['grace_renewals_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return $this->refuse('grace_capability_unaccepted');
            }
            $info = EnomApi::normalize('renewalInfo', $evidence['extend']);
            if (($info['status'] ?? null) !== 'success' || !is_int($info['max_extension'] ?? null) || $info['max_extension'] < 1
                || !is_bool($info['registrar_hold'] ?? null)
                || !RegistrarMoney::renewalExpiry($evidence['extend'], $binding, $evidence['info'])) {
                return $this->refuse('renewal_state_unconfirmed');
            }
            if ($info['registrar_hold'] && !$expired) { return $this->refuse('registrar_hold'); }
            // Enom's fresh limit is binding whatever the quote says; grace renewals are one year at a time.
            if ((int) $operation->term > $info['max_extension']) { return $this->refuse('renewal_term_above_registry_limit'); }
            if ($expired && (int) $operation->term !== 1) { return $this->refuse('grace_renewal_one_year_only'); }
            if (!$this->ordinaryDomain($evidence['check'], $domain->domain)) { return $this->refuse('renewal_premium_or_unknown'); }
            $quote = RegistrarMoney::renewalQuote($evidence['extend'], $binding + ['observed_at' => $clock->toIso8601String()],
                $evidence['check'], $evidence['expired'], $evidence['info']);
            $quote += ['provider_domain_id' => $domain->provider_domain_id, 'server_id' => (int) $domain->server_id,
                'account_fingerprint' => $account, 'authority_generation' => $domain->authority_generation,
                'confirmed_revision' => $domain->confirmed_revision];
            if (($quote['reason'] ?? null) === 'renewal_currency_unaccepted') {
                return $this->refuse('renewal_currency_unaccepted', $quote);
            }
            $complete = ($quote['complete'] ?? false) === true;
            if (($expired || $complimentary) && (!$complete || $quote['currency'] !== 'USD' || $operation->currency !== 'USD'
                || ($expired && ($quote['grace_eligible'] ?? null) !== true))) {
                return $this->refuse($expired ? 'grace_evidence_unconfirmed' : 'complimentary_quote_unconfirmed', $quote);
            }
            if ($expired || $complimentary) {
                $cost = self::minor($quote['amount']);
                $limit = $complimentary ? ($policy['complimentary_cap_minor'] ?? null) : ($policy['original_net_minor'] ?? null);
                if ($cost === null || !is_int($limit) || $limit < 0 || $cost > $limit) {
                    return $this->refuse($complimentary ? 'complimentary_cost_above_limit'
                        : 'grace_original_price_exceeded', $quote);
                }
            }
            $read();
            $renewal = EnomApi::normalize('renewal', $api->renewal($domain->domain));
            if (($renewal['status'] ?? null) !== 'success' || ($renewal['auto_renew'] ?? null) !== false) {
                return $this->refuse('provider_auto_renew_unconfirmed', $quote);
            }
            // AvailableBalance is documented USD. It authorizes no credit card use.
            // Unknown ordinary pre-expiry cost stays unknown and cannot be compared.
            if ($complete) {
                $read();
                $balance = EnomApi::normalize('balance', $api->balance());
                $available = self::minor($balance['available_balance'] ?? null);
                $cost = self::minor($quote['amount']);
                if (($balance['status'] ?? null) !== 'success' || $quote['currency'] !== 'USD' || $available === null || $cost === null || $available < $cost) {
                    return $this->refuse('registrar_balance_insufficient_or_unknown', $quote);
                }
            }
            $guard();
            $fresh = $operation->fresh();
            if (!$fresh || !$fresh->domain || $this->binding($fresh, $fresh->domain) !== $binding) {
                return $this->refuse('renewal_binding_changed', $quote);
            }
            if ($code = $this->localRefusal($fresh, $fresh->domain)) { return $this->refuse($code, $quote); }
            if ($fresh->domain->renewal_policy['automatic_spend_paused'] ?? false) {
                return $this->refuse('automatic_spend_paused', $quote);
            }
            $finalConfiguration = $this->operations->providerConfiguration((int) $domain->server_id);
            if (!hash_equals($account, self::accountFingerprint($finalConfiguration))) {
                return $this->refuse('renewal_account_changed', $quote);
            }
            if ($expired && !filter_var($finalConfiguration['grace_renewals_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return $this->refuse('grace_capability_unaccepted', $quote);
            }
            return ['allowed' => true, 'code' => 'renewal_admitted', 'quote' => $quote,
                'command' => $expired ? 'UpdateExpiredDomains' : 'Extend', 'account_fingerprint' => $account,
                'provider_domain_id' => $domain->provider_domain_id];
        } catch (Throwable $error) {
            // Never expose guard errors, raw provider responses or credentials.
            $code = $error instanceof \LogicException && in_array($error->getMessage(),
                ['renewal_account_changed', 'provider_membership_unconfirmed', 'provider_expiry_changed', 'renewal_read_budget_exhausted'], true)
                ? $error->getMessage() : 'renewal_reads_unavailable';
            return $this->refuse($code, $quote);
        }
    }

    public static function accountFingerprint(array $configuration): string
    {
        if (!is_string($configuration['uid'] ?? null) || $configuration['uid'] === '') {
            throw new \RuntimeException('Registrar account configuration is unavailable.');
        }
        return RuntimeGuard::accountFingerprint($configuration);
    }

    private function localRefusal(Operation $operation, ?Domain $domain): ?string
    {
        $service = $domain?->service;
        if ($operation->action !== 'renew' || (int) $operation->term < 1 || (int) $operation->term > 10 || !$domain || !$service
            || !in_array($operation->state, ['queued', 'running'], true)
            || !$this->operations->validService($service, $domain)
            || (int) $operation->service_id !== (int) $service->id
            || $service->status !== Service::STATUS_ACTIVE || !in_array($domain->status, ['active', 'expired', 'grace'], true)
            || $service->cancellation()->exists()) { return 'renewal_lifecycle_invalid'; }
        $tld = Tld::where('server_id', $domain->server_id)->where('tld', $domain->tld)->first();
        if ($domain->billing_managed && (!$tld || $tld->price('renew') === null)) { return 'renewal_catalogue_unavailable'; }
        if (!$domain->provider_expires_at || !$operation->base_expires_at
            || !$operation->base_expires_at->equalTo($domain->provider_expires_at)
            || !$operation->expected_expires_at || !$operation->expected_expires_at->equalTo($operation->base_expires_at->addYearsNoOverflow(max(1, (int) $operation->term)))) {
            return 'expiry_changed';
        }
        if (!is_string($domain->provider_domain_id) || preg_match('/^[1-9][0-9]{0,19}$/D', $domain->provider_domain_id) !== 1) {
            return 'provider_anchor_missing';
        }
        $policy = $operation->policy ?? [];
        if ($domain->billing_managed) {
            if (empty($policy['billing_managed']) || $service->expires_at !== null
                || ($policy['domain'] ?? null) !== $domain->domain
                || ($policy['service_id'] ?? null) !== (int) $service->id
                || ($policy['server_id'] ?? null) !== (int) $domain->server_id
                || (($policy['user_id'] ?? null) !== (int) $domain->user_id)
                || ($policy['authority_generation'] ?? null) !== $domain->authority_generation
                || ($policy['confirmed_revision'] ?? null) !== $domain->confirmed_revision
                || ($policy['currency'] ?? null) !== $operation->currency || ($policy['term'] ?? null) !== (int) $operation->term) {
                return 'renewal_policy_binding_changed';
            }
        }
        if (!in_array($operation->funding_basis, ['paid_invoice', 'complimentary'], true) || !$this->operations->funded($operation)) {
            return 'renewal_funding_invalid';
        }
        if (DomainOperations::hasRenewalBlocker($domain, $operation->id)) {
            return 'renewal_peer_blocked';
        }
        try {
            $parts = DomainName::parse($domain->domain, [$domain->tld]);
            if ($parts['domain'] !== $domain->domain || $parts['sld'] !== $domain->sld || $parts['tld'] !== $domain->tld
                || str_starts_with($parts['sld'], 'xn--')) { return 'renewal_domain_unsupported'; }
        } catch (Throwable) { return 'renewal_domain_unsupported'; }
        return null;
    }

    private function membershipRefusal(RegistrarResult $result, Domain $domain, string $uid): ?string
    {
        $info = EnomApi::normalize('info', $result);
        if (($info['status'] ?? null) !== 'success' || !is_string($info['domain'] ?? null)
            || strtolower($info['domain']) !== $domain->domain
            || !is_string($info['belongs_to'] ?? null) || strcasecmp($info['belongs_to'], $uid) !== 0
            || ($info['domain_name_id'] ?? null) !== $domain->provider_domain_id
            || ($info['sld'] !== null && strtolower((string) $info['sld']) !== $domain->sld)
            || ($info['tld'] !== null && strtolower((string) $info['tld']) !== $domain->tld)) {
            return 'provider_membership_unconfirmed';
        }
        if (!$result->confirmedExpiry() || $result->confirmedExpiry()->format('Y-m-d') !== $domain->provider_expires_at->format('Y-m-d')) {
            return 'provider_expiry_changed';
        }
        return null;
    }

    private function ordinaryDomain(RegistrarResult $result, string $name): bool
    {
        $domain = $result->value('domains.domain');
        if (is_array($domain) && array_is_list($domain)) { $domain = count($domain) === 1 ? $domain[0] : null; }
        $check = EnomApi::normalize('search', $result);
        return $result->status === 'success' && is_array($domain) && is_string($domain['name'] ?? null)
            && strtolower($domain['name']) === $name && ($domain['rrpcode'] ?? null) === '211'
            && ($check['is_premium'] ?? null) === false && ($check['is_platinum'] ?? null) === false && ($check['is_eap'] ?? null) === false;
    }

    private function binding(Operation $operation, Domain $domain): array
    {
        return ['domain' => $domain->domain, 'service_id' => (int) $domain->service_id, 'user_id' => (int) $domain->user_id,
            'server_id' => (int) $domain->server_id, 'provider_domain_id' => $domain->provider_domain_id,
            'authority_generation' => $domain->authority_generation, 'confirmed_revision' => $domain->confirmed_revision,
            'base_expires_at' => $domain->provider_expires_at?->format('Y-m-d'), 'policy' => $operation->policy,
            'operation_state' => $operation->state, 'funding_basis' => $operation->funding_basis,
            'invoice_id' => $operation->invoice_id, 'invoice_item_id' => $operation->invoice_item_id,
            'amount' => $operation->amount, 'currency' => $operation->currency, 'term' => (int) $operation->term,
            'operation_base_expires_at' => $operation->base_expires_at?->format('Y-m-d'),
            'expected_expires_at' => $operation->expected_expires_at?->format('Y-m-d')];
    }

    private static function minor(mixed $amount): ?int
    {
        $decimal = RegistrarMoney::decimal($amount);
        return $decimal === null ? null : PricingRule::minor($decimal);
    }

    private function refuse(string $code, ?array $quote = null): array
    {
        return ['allowed' => false, 'code' => $code, 'quote' => $quote, 'command' => null];
    }
}
