<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use Paymenter\Extensions\Servers\Enom\Api\DomainName;
use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;

/** Safe internal evidence only. Quotes never become charges or grace authority. */
final class RegistrarMoney
{
    public static function decimal(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $value)) { return null; }
        $parts = explode('.', $value);
        return $parts[0].'.'.str_pad($parts[1] ?? '', 2, '0');
    }

    /** Check documents omitted/empty Prices.Currency as US dollars. */
    public static function checkPriceCurrency(mixed $prices): ?string
    {
        if (!is_array($prices)) { return null; }
        if (!array_key_exists('currency', $prices) || $prices['currency'] === '') { return 'USD'; }
        return self::currency($prices['currency']);
    }

    public static function renewalQuote(RegistrarResult $extendInfo, array $binding, ?RegistrarResult $check = null, ?RegistrarResult $expired = null, ?RegistrarResult $authenticatedInfo = null): array
    {
        $info = $extendInfo->value('getextendinfo', $extendInfo->data);
        $amount = $extendInfo->status === 'success' && is_array($info) ? self::decimal($info['price'] ?? null) : null;
        $currency = is_array($info) ? self::currency($info['currency'] ?? null) : null;
        $observedAt = self::businessClock($binding);
        $term = is_int($binding['term'] ?? 1) ? ($binding['term'] ?? 1) : 0;
        $perYear = $amount;
        // Enom quotes one year; a multi-year renewal costs that per year.
        if ($amount !== null && $term >= 1 && $term <= 10) {
            $minor = (int) str_replace('.', '', $amount) * $term;
            $amount = intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
        }
        $quote = ['state' => $amount === null ? 'unconfirmed' : 'advisory', 'source' => 'GetExtendInfo',
            'domain' => $binding['domain'] ?? null, 'action' => 'renew', 'term' => $term,
            'amount' => $amount, 'currency' => $currency, 'complete' => false,
            'grace_eligible' => null, 'reason' => 'base_tld_price_only',
            'base_expires_at' => $binding['base_expires_at'] ?? $binding['confirmed_expiry'] ?? $extendInfo->confirmedExpiry()?->format('Y-m-d'),
            'observed_at' => $observedAt?->format(DATE_ATOM)];
        if (!$check || $check->status !== 'success' || $amount === null || !$observedAt || $term < 1 || $term > 10) { return $quote; }
        $domain = $check->value('domains.domain');
        if (is_array($domain) && array_is_list($domain)) { $domain = count($domain) === 1 ? $domain[0] : null; }
        if (!is_array($domain) || !is_string($domain['name'] ?? null)
            || strtolower($domain['name']) !== ($binding['domain'] ?? null)
            || !in_array($domain['rrpcode'] ?? null, ['211'], true)) { return $quote; }
        foreach (['ispremium', 'isplatinum', 'iseap'] as $flag) {
            if (!is_string($domain[$flag] ?? null) || !in_array(strtolower($domain[$flag]), ['false', '0', 'no'], true)) { return $quote; }
        }
        $prices = $domain['prices'] ?? null;
        if (is_array($prices) && self::checkPriceCurrency($prices) !== 'USD') {
            return array_replace($quote, ['reason' => 'renewal_currency_unaccepted']);
        }
        if (!is_array($prices) || self::checkPriceCurrency($prices) !== 'USD'
            || self::decimal($prices['renewal'] ?? null) !== $perYear
            || (isset($info['currency']) && self::currency($info['currency']) !== 'USD')
            || !is_string($info['registrarhold'] ?? null) || !in_array(strtolower($info['registrarhold']), ['true', '1', 'yes', 'false', '0', 'no'], true)
            || !is_string($info['maxextension'] ?? null) || !preg_match('/^(?:[1-9]|10)$/D', $info['maxextension'])
            || (int) $info['maxextension'] < $term) { return $quote; }
        $expiry = self::renewalExpiry($extendInfo, $binding, $authenticatedInfo);
        if (!$expiry) { return $quote; }
        $pastExpiry = $expiry <= $observedAt;
        if ($pastExpiry && !self::expiredMatch($expired, $binding, $expiry)) { return $quote; }
        if (!$pastExpiry && !in_array(strtolower($info['registrarhold']), ['false', '0', 'no'], true)) { return $quote; }
        $expiryEvidence = $expiry->format('U.u') !== $extendInfo->confirmedExpiry()->format('U.u')
            ? ['expiry_source' => 'GetDomainInfo_precise_la_date_reconciled', 'expires_at' => $expiry->format('Y-m-d\TH:i:s.uP')] : [];
        return array_replace($quote, $expiryEvidence, ['state' => 'quoted', 'source' => 'Check+GetExtendInfo'.($pastExpiry ? '+GetExpiredDomains' : ''),
            'currency' => 'USD', 'currency_source' => !array_key_exists('currency', $prices) || $prices['currency'] === ''
                ? 'documented_check_prices_usd_default' : 'explicit_check_prices_currency',
            'complete' => true, 'grace_eligible' => $pastExpiry ? true : false,
            'command' => $pastExpiry ? 'UpdateExpiredDomains' : 'Extend', 'reason' => $term === 1 ? 'ordinary_one_year_documented_price' : 'ordinary_documented_price_per_year']);
    }

    /**
     * Callers must authenticate account membership on this same info object first.
     * The local zone must match RegistrarResult's provider-date parser. Keep
     * UTC obligations exact; only observed scalar M/D/YYYY evidence reconciles.
     */
    public static function renewalExpiry(RegistrarResult $extendInfo, array $binding, ?RegistrarResult $authenticatedInfo = null): ?\DateTimeImmutable
    {
        $expiry = $extendInfo->confirmedExpiry();
        if (!$expiry) { return null; }
        if (!isset($binding['base_expires_at']) || $expiry->format('Y-m-d') === $binding['base_expires_at']) { return $expiry; }
        if (!$authenticatedInfo || $authenticatedInfo->status !== 'success') { return null; }
        $extend = $extendInfo->value('getextendinfo', $extendInfo->data);
        $date = is_array($extend) ? ($extend['expiration'] ?? null) : null;
        // Only the observed date-only Expiration field receives this reconciliation.
        if (!is_string($date) || !preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/D', $date)) { return null; }
        $info = $authenticatedInfo->value('getdomaininfo');
        $name = is_array($info) ? ($info['domainname'] ?? null) : null;
        $id = is_array($name) ? ($name['@attributes']['domainnameid'] ?? null) : (is_array($info) ? ($info['domainnameid'] ?? null) : null);
        $domain = is_array($name) ? ($name['#text'] ?? null) : $name;
        if (!is_string($domain) || strtolower($domain) !== ($binding['domain'] ?? null)
            || !is_string($binding['provider_domain_id'] ?? null) || $binding['provider_domain_id'] === ''
            || !is_string($id) || $id !== $binding['provider_domain_id']
            || isset($info['domainnameid']) && $info['domainnameid'] !== $id) { return null; }
        $raw = $authenticatedInfo->value('getdomaininfo.status.expiration');
        if (!is_string($raw) || !preg_match('/[T ]\d{1,2}:\d{2}/', $raw)) { return null; }
        $authoritative = (new RegistrarResult('success', null, ['expiration' => $raw]))->confirmedExpiry();
        if (!$authoritative || $authoritative->format('Y-m-d') !== $binding['base_expires_at']) { return null; }
        // Every supplied expiry alias must prove the same precise instant.
        $missing = new \stdClass;
        foreach ([[$authenticatedInfo, $authoritative], [$extendInfo, $expiry]] as [$result, $expected]) {
            foreach (['registryexpdate', 'domaininfo.registryexpdate', 'expirationdate', 'getdomaininfo.status.expiration',
                'expiration', 'getextendinfo.expiration', 'order.orderdetail.expirydatetime'] as $path) {
                $value = $result->value($path, $missing);
                if ($value === $missing) { continue; }
                $parsed = (new RegistrarResult('success', null, ['expiration' => $value]))->confirmedExpiry();
                if (!$parsed || $parsed->format('U.u') !== $expected->format('U.u')) { return null; }
            }
        }
        $local = new \DateTimeZone('America/Los_Angeles');
        return $expiry->setTimezone($local)->format('Y-m-d') === $authoritative->setTimezone($local)->format('Y-m-d')
            ? $authoritative : null;
    }

    private static function businessClock(array $binding): ?\DateTimeImmutable
    {
        if (!array_key_exists('observed_at', $binding)) {
            return function_exists('now') ? \DateTimeImmutable::createFromInterface(now()) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        $at = $binding['observed_at'];
        if (!is_string($at) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $at)) { return null; }
        try {
            $clock = new \DateTimeImmutable($at);
            $errors = \DateTimeImmutable::getLastErrors();
            return $errors && ($errors['warning_count'] || $errors['error_count']) ? null : $clock->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) { return null; }
    }

    private static function expiredMatch(?RegistrarResult $expired, array $binding, \DateTimeImmutable $expiry): bool
    {
        if (!$expired || $expired->status !== 'success') { return false; }
        $details = $expired->value('domaindetail');
        if (!is_array($details)) { return false; }
        $details = array_is_list($details) ? $details : [$details];
        $count = $expired->value('domaincount');
        if (!is_string($count) || !ctype_digit($count) || (int) $count !== count($details)) { return false; }
        $matched = [];
        foreach ($details as $item) {
            if (!is_array($item) || !is_string($item['domainname'] ?? null)) { return false; }
            if (strtolower($item['domainname']) === ($binding['domain'] ?? null)) { $matched[] = $item; }
        }
        if (count($matched) !== 1 || strtolower((string) ($matched[0]['status'] ?? '')) !== 'expired'
            || !is_string($binding['provider_domain_id'] ?? null)
            || ($matched[0]['domainnameid'] ?? null) !== $binding['provider_domain_id']) { return false; }
        $date = new RegistrarResult('success', null, ['expirationdate' => $matched[0]['expiration-date'] ?? null]);
        return $date->confirmedExpiry()?->format('Y-m-d') === $expiry->format('Y-m-d');
    }

    public static function actualCost(RegistrarResult $order, array $binding, ?RegistrarResult $execution = null): array
    {
        $unknown = ['state' => 'unconfirmed', 'source' => 'GetOrderDetail', 'amount' => null, 'currency' => null];
        $id = $binding['provider_order_id'] ?? null;
        $action = $binding['action'] ?? null;
        // An Extend renewal requires one matching Renew order item.
        // Expired renewal commands and unbound historical orders remain unknown.
        if (!in_array($action, ['register', 'renew'], true)) { return $unknown; }
        if ($action === 'renew' && (($binding['provider_command'] ?? null) !== 'Extend' || !is_int($binding['term'] ?? null)
            || $binding['term'] < 1 || $binding['term'] > 10)) { return $unknown; }
        $requiredCommand = $action === 'renew' ? 'extend' : 'purchase';
        $requiredProduct = $action === 'renew' ? 'renew' : 'register';
        $command = $execution?->value('command');
        $executionCommand = is_string($command) ? strtolower($command) : null;
        $capturedCommand = array_key_exists('provider_command', $binding) ? $binding['provider_command'] : 'Purchase';
        $expectedCommand = is_string($capturedCommand) ? strtolower($capturedCommand) : null;
        $header = $order->value('order');
        if ($order->status !== 'success' || !is_array($header) || !is_string($id)
            || !preg_match('/^[1-9][0-9]{0,19}$/D', $id) || ($header['orderid'] ?? null) !== $id
            || !is_string($header['result'] ?? null) || strtolower($header['result']) !== 'true'
            || !$execution || $execution->status !== 'success' || $executionCommand !== $requiredCommand
            || $executionCommand !== $expectedCommand || $execution->value('orderid') !== $id) { return $unknown; }
        $details = $header['orderdetail'] ?? null;
        if (!is_array($details)) { return $unknown; }
        $details = array_is_list($details) ? $details : [$details];
        // No undocumented fee allocation or split-order allocation.
        if (count($details) !== 1 || !is_array($details[0])) { return $unknown; }
        $item = $details[0];
        try {
            if (!is_string($binding['domain'] ?? null) || !is_string($item['description'] ?? null)) { return $unknown; }
            // The binding's own ending is the only suffix the order line may carry.
            $suffix = substr($binding['domain'], (int) strpos($binding['domain'], '.') + 1);
            $expected = DomainName::parse($binding['domain'], [$suffix])['domain'];
            $actual = DomainName::parse($item['description'], [$suffix])['domain'];
        } catch (\Throwable) { return $unknown; }
        $term = $binding['term'] ?? null;
        if ($actual !== $expected || !is_int($term) || $term < 1 || $term > 10
            || ($item['quantity'] ?? null) !== (string) $term
            || !is_string($item['status'] ?? null) || strtolower($item['status']) !== 'successful'
            || !is_string($item['orderstatus'] ?? null) || strtolower($item['orderstatus']) !== 'closed'
            || !is_string($item['producttype'] ?? null) || strtolower($item['producttype']) !== $requiredProduct) { return $unknown; }
        $amount = self::orderAmount($item['amountpaid'] ?? null);
        $total = self::orderAmount($header['orderbillamount'] ?? null);
        if ($amount === null || $total !== $amount) { return $unknown; }
        // The official GetOrderDetail contract states OrderBillAmount is in USD.
        // With exactly one equal-priced item the total supplies its known currency.
        foreach ([$header, $item] as $row) {
            if (array_key_exists('currency', $row) && self::currency($row['currency']) !== 'USD') { return $unknown; }
        }
        return ['state' => 'confirmed', 'source' => 'GetOrderDetail', 'provider_order_id' => $id,
            'domain' => $expected, 'action' => $action, 'term' => $term, 'amount' => $amount,
            'currency' => 'USD', 'currency_source' => 'documented_orderbillamount_usd'];
    }

    /** Order amounts may carry one leading dollar sign; quote parsing stays strict. */
    private static function orderAmount(mixed $value): ?string
    {
        return self::decimal(is_string($value) && str_starts_with($value, '$') ? substr($value, 1) : $value);
    }

    private static function currency(mixed $value): ?string
    {
        return is_string($value) && in_array(strtoupper($value), ['USD', 'CAD', 'EUR', 'GBP', 'AUD'], true) ? strtoupper($value) : null;
    }
}
