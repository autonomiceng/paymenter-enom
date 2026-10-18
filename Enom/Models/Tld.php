<?php

namespace Paymenter\Extensions\Servers\Enom\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\PricingRule;

/** One ending, its pricing rule and the last exact-term wholesale costs. */
class Tld extends Model
{
    public const MODES = ['fixed', 'markup_amount', 'markup_percent'];
    public const ACTIONS = ['register', 'renew', 'transfer'];

    protected $table = 'enom_tlds';
    protected $guarded = [];
    protected $hidden = ['cost_register', 'cost_register_terms', 'cost_renew', 'cost_transfer', 'markup'];
    protected $casts = [
        'register_price' => 'decimal:2', 'transfer_price' => 'decimal:2', 'cost_register' => 'decimal:2',
        'enabled' => 'boolean', 'register_enabled' => 'boolean', 'renew_enabled' => 'boolean', 'transfer_enabled' => 'boolean',
        'use_default_rule' => 'boolean', 'markup' => 'decimal:2', 'renew_price' => 'decimal:2',
        'fixed_register' => 'decimal:2', 'fixed_renew' => 'decimal:2', 'fixed_transfer' => 'decimal:2',
        'cost_register_terms' => 'array', 'sale_holds' => 'array', 'cost_renew' => 'decimal:4', 'cost_transfer' => 'decimal:4',
        'costs_synced_at' => 'immutable_datetime', 'sort_order' => 'integer',
        'min_years' => 'integer', 'max_years' => 'integer', 'registry_fields' => 'array',
    ];

    public function server() { return $this->belongsTo(Server::class); }
    public function getDisplayNameAttribute(): string { return '.'.$this->tld; }

    public function orderable(?string $action = null): bool
    {
        // Temporary 0.4 row adapter; removed in the explicit adapter-removal PR.
        if ($this->temporaryLegacyRow()) { return $this->temporaryLegacyOrderable(); }
        if (!$this->enabled || $this->price('renew') === null) { return false; }
        return match ($action) {
            'register' => $this->register_enabled && $this->terms() !== [],
            'transfer' => $this->transfer_enabled && $this->price('transfer') !== null,
            null => $this->orderable('register') || $this->orderable('transfer'),
            default => false,
        };
    }

    public function price(string $action): ?string
    {
        // Temporary 0.4 row adapter; removed in the explicit adapter-removal PR.
        if ($this->temporaryLegacyRow()) { return $this->temporaryLegacyPrice($action); }
        if ($action === 'renew') { return $this->renew_enabled && $this->renew_price !== null && PricingRule::units($this->renew_price, 2) > 0 ? $this->renew_price : null; }
        if ($action === 'register') { return $this->registrationTotal(1); }
        return $action === 'transfer' ? $this->salePrice('transfer', 1) : null;
    }

    public function cost(string $action, int $years = 1): ?string
    {
        // Temporary 0.4 row adapter; removed in the explicit adapter-removal PR.
        if ($this->temporaryLegacyRow()) { return $this->temporaryLegacyCost($action); }
        if (!in_array($action, self::ACTIONS, true) || $years < 1 || $years > 10) { return null; }
        return PricingRule::wholesale($action === 'register' ? ($this->cost_register_terms[$years] ?? null) : $this->{'cost_'.$action});
    }

    /** @return list<int> Registration years with a current USD sale price. */
    public function terms(): array
    {
        // Temporary 0.4 row adapter; removed in the explicit adapter-removal PR.
        if ($this->temporaryLegacyRow()) { return $this->temporaryLegacyTerms(); }
        if (!$this->enabled || !$this->register_enabled || $this->price('renew') === null) { return []; }
        $terms = [];
        for ($years = max(1, (int) $this->min_years); $years <= min(10, (int) $this->max_years); $years++) {
            if ($this->registrationTotal($years) !== null) { $terms[] = $years; }
        }
        return $terms;
    }

    public function registrationTotal(int $years): ?string
    {
        // Temporary 0.4 row adapter; removed in the explicit adapter-removal PR.
        if ($this->temporaryLegacyRow()) { return $this->temporaryLegacyRegistrationTotal($years); }
        if ($years < max(1, (int) $this->min_years) || $years > min(10, (int) $this->max_years)) { return null; }
        return $this->salePrice('register', $years);
    }

    private function salePrice(string $action, int $years): ?string
    {
        if (!$this->enabled || !$this->{$action.'_enabled'} || $this->price('renew') === null) { return null; }
        $product = app(Catalogue::class)->productFor($this, $action);
        $price = $product?->plans()->where('type', 'recurring')->where('billing_unit', 'year')->where('billing_period', $years)
            ->first()?->prices()->where('currency_code', 'USD')->first();
        if (!$price || PricingRule::units((string) $price->price, 2) <= 0 || PricingRule::units((string) $price->setup_fee, 2) !== 0) { return null; }
        return PricingRule::decimal(PricingRule::units((string) $price->price, 2));
    }

    private function temporaryLegacyOrderable(): bool
    {
        return $this->enabled && $this->temporaryLegacyPrice('register') !== null && $this->temporaryLegacyPrice('renew') !== null && $this->temporaryLegacyPrice('transfer') !== null;
    }

    private function temporaryLegacyPrice(string $action): ?string
    {
        $value = $this->{$action.'_price'} ?? null;
        return is_numeric($value) && (float) $value > 0 ? number_format((float) $value, 2, '.', '') : null;
    }

    private function temporaryLegacyCost(string $action): ?string
    {
        $value = $this->{'cost_'.$action} ?? null;
        return is_numeric($value) && (float) $value >= 0 ? number_format((float) $value, 2, '.', '') : null;
    }

    private function temporaryLegacyTerms(): array
    {
        $min = max(1, min(10, (int) ($this->min_years ?: 1)));
        $max = max($min, min(10, (int) ($this->max_years ?: 10)));
        return range($min, $max);
    }

    private function temporaryLegacyRegistrationTotal(int $years): ?string
    {
        $register = $this->temporaryLegacyPrice('register');
        $renew = $this->temporaryLegacyPrice('renew');
        if ($register === null || $renew === null || !in_array($years, $this->temporaryLegacyTerms(), true)) { return null; }
        $minor = (int) round(((float) $register) * 100) + (int) round(((float) $renew) * 100) * ($years - 1);
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    // Temporary compatibility for 0.4 rows and unsaved legacy test fixtures.
    private function temporaryLegacyRow(): bool
    {
        return !array_key_exists('register_enabled', $this->attributes) || isset($this->attributes['register_price']);
    }
}
