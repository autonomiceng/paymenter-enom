<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use App\Classes\Price;
use App\Classes\Settings;
use App\Models\Currency;
use App\Models\CustomProperty;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Tld;

/**
 * Who pays for a domain.
 *
 * - Standard: the client pays the current catalogue price for the ending.
 * - Custom: the client pays the domain's own service price.
 * - Complimentary: the client pays nothing; the reseller pays Enom's cost.
 *
 * A domain setting wins over the client default. A client is complimentary when the raw
 * native user property `enom_complimentary` holds a staff grant. It is not a CustomProperty,
 * so clients can neither see nor submit it; a CustomProperty using the same key voids it.
 */
final class Pricing
{
    /** Stored on enom_domains.price_mode. Null means "follow the client's default". */
    public const MODES = ['standard', 'custom', 'complimentary'];

    public const CLIENT_PROPERTY = 'enom_complimentary';

    /** @return array{granted_by: ?int, granted_at: ?string, max_domains: ?int}|null */
    public static function clientGrant(?User $user): ?array
    {
        if (!$user || CustomProperty::query()->where('key', self::CLIENT_PROPERTY)->exists()) { return null; }
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

    /** Staff-only. $maxDomains limits how many complimentary domains the client may hold; null is no limit. */
    public static function setClientComplimentary(User $user, bool $complimentary, ?User $staff = null, ?int $maxDomains = null): void
    {
        if ($complimentary) {
            $user->properties()->updateOrCreate(['key' => self::CLIENT_PROPERTY], ['name' => 'Complimentary domains', 'custom_property_id' => null,
                'value' => json_encode(['granted_by' => $staff?->id, 'granted_at' => now()->toIso8601String(),
                    'max_domains' => $maxDomains !== null && $maxDomains > 0 ? $maxDomains : null])]);
        } else {
            $user->properties()->where('key', self::CLIENT_PROPERTY)->delete();
        }
        foreach (Domain::query()->where('user_id', $user->id)->whereNull('price_mode')->get() as $domain) {
            self::syncServicePrice($domain);
            self::retireIfComplimentary($domain);
        }
    }

    /** Whether a complimentary client may add one more domain under their limit. */
    public static function clientHasRoom(User $user): bool
    {
        $grant = self::clientGrant($user);
        if ($grant === null) { return false; }
        if ($grant['max_domains'] === null) { return true; }
        $held = Domain::query()->where('user_id', $user->id)->whereNotIn('status', ['cancelled', 'transferred_away', 'deleted'])->get()
            ->filter(fn (Domain $domain) => self::isComplimentary($domain))->count();
        return $held < $grant['max_domains'];
    }

    /** The mode that applies right now: the domain's own choice, else the client's default. */
    public static function mode(Domain $domain): string
    {
        $own = $domain->price_mode;
        if (in_array($own, self::MODES, true)) { return $own; }
        return self::clientComplimentary($domain->service?->user ?? User::find($domain->user_id)) ? 'complimentary' : 'standard';
    }

    public static function isComplimentary(Domain $domain): bool { return self::mode($domain) === 'complimentary'; }

    /** Where the mode comes from, for display: "domain" (set on the domain) or "client" (the client's default). */
    public static function source(Domain $domain): string
    {
        return in_array($domain->price_mode, self::MODES, true) ? 'domain' : 'client';
    }

    /**
     * Yearly renewal price the client pays, as a decimal string. "0.00" for complimentary.
     * Null when a standard price can't be found (ending removed from the catalogue).
     */
    public static function renewalPrice(Domain $domain): ?string
    {
        return match (self::mode($domain)) {
            'complimentary' => '0.00',
            'custom' => RegistrarMoney::decimal((string) $domain->service?->price),
            default => ($user = $domain->service?->user ?? User::find($domain->user_id))
                ? self::taxed($user, Tld::query()->where('server_id', $domain->server_id)->where('tld', $domain->tld)->first()?->price('renew'), $domain->service?->currency_code ?? 'USD')
                : null,
        };
    }

    /** Save a domain's pricing. Custom needs a positive price, which becomes the service price. */
    public static function setDomainMode(Domain $domain, string $mode, ?string $customPrice = null): void
    {
        if ($mode !== 'inherit' && !in_array($mode, self::MODES, true)) { throw new InvalidArgumentException('Choose who pays for this domain.'); }
        $service = $domain->service;
        if ($mode === 'custom') {
            $price = is_string($customPrice) ? RegistrarMoney::decimal(trim($customPrice)) : null;
            if ($price === null || (float) $price <= 0) { throw new InvalidArgumentException('Enter a custom yearly price above 0.00.'); }
            if ($service) { $service->price = $price; $service->save(); }
        }
        $domain->price_mode = $mode === 'inherit' ? null : $mode;
        $domain->save();
        if ($mode !== 'custom') { self::syncServicePrice($domain); }
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
        // Legacy domains billed by native service dates keep their own price.
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

    /** Most a complimentary registration or renewal may cost, in cents. */
    public static function complimentaryCapMinor(int $serverId): int
    {
        $value = RuntimeGuard::setting($serverId, 'complimentary_cost_cap', '50');
        $decimal = is_numeric($value) ? RegistrarMoney::decimal((string) $value) : null;
        return $decimal === null ? 5000 : max(0, (int) round(((float) $decimal) * 100));
    }
}
