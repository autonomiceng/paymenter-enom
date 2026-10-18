<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Classes\Price;
use App\Classes\Settings;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Tld;
use App\Models\CustomProperty;

/** Standard catalogue prices and staff-approved complimentary renewals. */
final class Pricing
{
    public const CLIENT_PROPERTY = 'enom_complimentary';
    public const MODES = ['standard', 'complimentary'];

    public static function clientGrant(?User $user): ?array
    {
        if (!$user || CustomProperty::query()->where('key', self::CLIENT_PROPERTY)->exists()) { return null; }
        // Writers and dispatch hold the client row lock before reading this, so a plain read is current.
        $property = $user->properties()->where('key', self::CLIENT_PROPERTY)->whereNull('custom_property_id')->first();
        if (!$property) { return null; }
        $grant = json_decode((string) $property->value, true);
        if ($property->value === '1') { $grant = []; }
        if (!is_array($grant)) { return null; }
        $max = $grant['max_domains'] ?? null;
        return ['granted_by' => is_int($grant['granted_by'] ?? null) ? $grant['granted_by'] : null,
            'granted_at' => is_string($grant['granted_at'] ?? null) ? $grant['granted_at'] : null,
            'max_domains' => is_int($max) && $max > 0 ? $max : null];
    }

    public static function clientComplimentary(?User $user): bool { return self::clientGrant($user) !== null; }

    public static function setClientComplimentary(User $user, bool $complimentary, ?User $staff = null, ?int $maxDomains = null): void
    {
        // The client row is the authority lock shared with order placement and dispatch (user before domain).
        DB::transaction(function () use ($user, $complimentary, $staff, $maxDomains) {
            User::whereKey($user->id)->lockForUpdate()->first();
            if ($complimentary && $maxDomains !== null) {
                $following = self::liveDomains($user->id)->where(fn ($q) => $q->whereNull('price_mode')->orWhere('price_mode', 'complimentary'))->count();
                if ($following > $maxDomains) {
                    throw new InvalidArgumentException('This client already has '.$following.' domains that would become complimentary. Set a limit of at least '.$following.', or leave it blank.');
                }
            }
            self::writeClientGrant($user, $complimentary, $staff, $maxDomains);
        });
        foreach (Domain::query()->where('user_id', $user->id)->whereNull('price_mode')->get() as $domain) {
            self::syncServicePrice($domain);
            self::retireIfComplimentary($domain);
        }
    }

    private static function writeClientGrant(User $user, bool $complimentary, ?User $staff, ?int $maxDomains): void
    {
        if ($complimentary) {
            $user->properties()->updateOrCreate(['key' => self::CLIENT_PROPERTY], ['name' => 'Complimentary domains', 'custom_property_id' => null,
                'value' => json_encode(['granted_by' => $staff?->id, 'granted_at' => now()->toIso8601String(),
                    'max_domains' => $maxDomains !== null && $maxDomains > 0 ? $maxDomains : null])]);
        } else {
            $user->properties()->where('key', self::CLIENT_PROPERTY)->delete();
        }
    }

    private static function liveDomains(int $userId)
    {
        return Domain::query()->where('user_id', $userId)
            ->whereNotIn('status', ['cancelled', 'transferred_away', 'deleted', 'registration_rejected', 'transfer_rejected']);
    }

    public static function clientHasRoom(User $user): bool
    {
        $grant = self::clientGrant($user);
        if ($grant === null) { return false; }
        if ($grant['max_domains'] === null) { return true; }
        $held = self::liveDomains($user->id)->where(fn ($q) => $q->whereNull('price_mode')->orWhere('price_mode', 'complimentary'))->count();
        return $held < $grant['max_domains'];
    }

    public static function mode(Domain $domain): string
    {
        return $domain->price_mode === 'complimentary' ? 'complimentary' : 'standard';
    }

    public static function isComplimentary(Domain $domain): bool { return self::mode($domain) === 'complimentary'; }

    public static function source(Domain $domain): string { return 'domain'; }

    /**
     * Yearly renewal price the client pays, as a decimal string. "0.00" for complimentary.
     * Null when a standard price can't be found (ending removed from the catalogue).
     */
    public static function renewalPrice(Domain $domain): ?string
    {
        return match (self::mode($domain)) {
            'complimentary' => '0.00',
            default => ($user = $domain->service?->user ?? User::find($domain->user_id))
                ? self::taxed($user, Tld::query()->where('server_id', $domain->server_id)->where('tld', $domain->tld)->first()?->price('renew'), $domain->service?->currency_code ?? 'USD')
                : null,
        };
    }

    public static function zeroRetail(Domain $domain): bool
    {
        return $domain->service !== null && RegistrarMoney::decimal((string) $domain->service->price) === '0.00';
    }

    /** Staff authorization is checked by StaffDomains before changing renewal funding. */
    public static function setDomainMode(Domain $domain, string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) { throw new InvalidArgumentException('Choose standard or complimentary renewal.'); }
        DB::transaction(function () use ($domain, $mode) {
            User::whereKey($domain->user_id)->lockForUpdate()->firstOrFail();
            Domain::whereKey($domain->id)->lockForUpdate()->update(['price_mode' => $mode]);
        });
        $domain->refresh();
        self::syncServicePrice($domain);
        self::retireIfComplimentary($domain);
    }

    /** A domain that became complimentary drops clean unpaid renewal invoices. Paid or partly paid history is kept. */
    public static function retireIfComplimentary(Domain $domain): void
    {
        if (!$domain->billing_managed || !self::isComplimentary($domain)) { return; }
        DB::transaction(fn () => app(DomainOperations::class)->retireUnpaidRenewals(Domain::lockForUpdate()->findOrFail($domain->id)));
    }

    /** Keep the native service price equal to what the next renewal will cost. */
    public static function syncServicePrice(Domain $domain): void
    {
        $service = $domain->service;
        $price = self::renewalPrice($domain);
        if (!$domain->billing_managed || !$service || $price === null || RegistrarMoney::decimal((string) $service->price) === $price) { return; }
        $service->price = $price;
        $service->save();
    }

    /** Amount the client pays, with exclusive tax applied the same way as native checkout. */
    public static function taxed(User $user, ?string $amount, string $currency): ?string
    {
        if ($amount === null) { return null; }
        return number_format((float) (new Price(['price' => $amount, 'currency' => Currency::findOrFail($currency)],
            apply_exclusive_tax: true, tax: Settings::tax($user)))->price, 2, '.', '');
    }

    /** Most a complimentary renewal may cost, in cents. */
    public static function complimentaryCapMinor(int $serverId): int
    {
        $value = RuntimeGuard::setting($serverId, 'complimentary_cost_cap', '50');
        $decimal = is_numeric($value) ? RegistrarMoney::decimal((string) $value) : null;
        return $decimal === null ? 5000 : PricingRule::minor($decimal);
    }
}
