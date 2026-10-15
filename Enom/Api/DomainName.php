<?php

namespace Paymenter\Extensions\Servers\Enom\Api;

final class DomainName
{
    public static function parse(string $input, array $allowedTlds = ['com']): array
    {
        $domain = strtolower(trim($input));
        if ($domain === '' || strlen($domain) > 253 || preg_match('/[\s\x00-\x20\/\\\\:@?#]/u', $domain)) {
            throw new \InvalidArgumentException('Enter a supported domain name.');
        }
        if (preg_match('/[^\x00-\x7f]/', $domain)) {
            if (!function_exists('idn_to_ascii')) {
                throw new \InvalidArgumentException('International domain conversion is unavailable.');
            }
            $domain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($domain === false) {
                throw new \InvalidArgumentException('Invalid international domain name.');
            }
            $domain = strtolower($domain);
        }
        $suffixes = array_map(static fn ($tld) => strtolower((string) $tld), $allowedTlds);
        usort($suffixes, static fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($suffixes as $tld) {
            if (!preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)*$/D', $tld) || !str_ends_with($domain, '.'.$tld)) {
                continue;
            }
            $sld = substr($domain, 0, -strlen($tld) - 1);
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $sld)
                || (substr($sld, 2, 2) === '--' && !str_starts_with($sld, 'xn--'))) {
                throw new \InvalidArgumentException('Invalid second-level domain name.');
            }
            return ['domain' => $domain, 'sld' => $sld, 'tld' => $tld];
        }
        throw new \InvalidArgumentException('This domain extension is unavailable.');
    }
}
