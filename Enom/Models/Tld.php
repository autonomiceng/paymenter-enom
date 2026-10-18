<?php

namespace Paymenter\Extensions\Servers\Enom\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;
use Paymenter\Extensions\Servers\Enom\Services\PricingRule;
use Throwable;

/** One ending, its pricing rule and the last exact-term wholesale costs. */
class Tld extends Model
{
    public const MODES = ['fixed', 'markup_amount', 'markup_percent'];
    public const ACTIONS = ['register', 'renew', 'transfer'];

    private bool $saleReadFailed = false;

    protected $table = 'enom_tlds';
    protected $guarded = [];
    protected $hidden = ['cost_register_terms', 'cost_renew', 'cost_transfer', 'markup'];
    protected $casts = [
        'enabled' => 'boolean', 'register_enabled' => 'boolean', 'renew_enabled' => 'boolean', 'transfer_enabled' => 'boolean',
        'use_default_rule' => 'boolean', 'markup' => 'decimal:2', 'renew_price' => 'decimal:2',
        'fixed_register' => 'decimal:2', 'fixed_renew' => 'decimal:2', 'fixed_transfer' => 'decimal:2',
        'cost_register_terms' => 'array', 'sale_holds' => 'array', 'cost_renew' => 'decimal:4', 'cost_transfer' => 'decimal:4',
        'costs_synced_at' => 'immutable_datetime', 'sort_order' => 'integer',
        'min_years' => 'integer', 'max_years' => 'integer', 'registry_fields' => 'array',
    ];

    public function refresh()
    {
        $this->saleReadFailed = false;
        return parent::refresh();
    }

    public function server() { return $this->belongsTo(Server::class); }
    public function getDisplayNameAttribute(): string { return '.'.$this->tld; }

    public function orderable(?string $action = null): bool
    {
        if (!$this->enabled || !$this->saleRenewalAvailable()) { return false; }
        $register = $this->register_enabled && $this->terms() !== [];
        $transfer = $this->transfer_enabled && $this->price('transfer') !== null;
        return !$this->saleReadFailed && match ($action) {
            'register' => $register,
            'transfer' => $transfer,
            null => $register || $transfer,
            default => false,
        };
    }

    public function price(string $action): ?string
    {
        if ($action === 'renew') { return $this->renew_enabled && $this->renew_price !== null && PricingRule::units($this->renew_price, 2) > 0 ? $this->renew_price : null; }
        if ($action === 'register') { return $this->registrationTotal(1); }
        return $action === 'transfer' ? $this->salePrice('transfer', 1) : null;
    }

    public function cost(string $action, int $years = 1): ?string
    {
        if (!in_array($action, self::ACTIONS, true) || $years < 1 || $years > 10) { return null; }
        return PricingRule::wholesale($action === 'register' ? ($this->cost_register_terms[$years] ?? null) : $this->{'cost_'.$action});
    }

    /** @return list<int> Registration years with a current USD sale price. */
    public function terms(): array
    {
        if (!$this->enabled || !$this->register_enabled || !$this->saleRenewalAvailable()) { return []; }
        $terms = [];
        for ($years = max(1, (int) $this->min_years); $years <= min(10, (int) $this->max_years); $years++) {
            if ($this->registrationTotal($years) !== null) { $terms[] = $years; }
        }
        return $this->saleReadFailed ? [] : $terms;
    }

    public function registrationTotal(int $years): ?string
    {
        if ($years < max(1, (int) $this->min_years) || $years > min(10, (int) $this->max_years)) { return null; }
        return $this->salePrice('register', $years);
    }

    private function salePrice(string $action, int $years): ?string
    {
        if (!$this->enabled || !$this->{$action.'_enabled'} || !$this->saleRenewalAvailable()) { return null; }
        try {
            $product = app(Catalogue::class)->productFor($this, $action);
            $price = $product?->plans()->where('type', 'recurring')->where('billing_unit', 'year')->where('billing_period', $years)
                ->first()?->prices()->where('currency_code', 'USD')->first();
            if (!$price || PricingRule::units((string) $price->price, 2) <= 0 || PricingRule::units((string) $price->setup_fee, 2) !== 0) { return null; }
            return PricingRule::decimal(PricingRule::units((string) $price->price, 2));
        } catch (Throwable $exception) {
            $this->saleReadFailed = true;
            report($exception);
            return null;
        }
    }

    /** Sale filtering must not turn corrupt holder renewal billing into a silent null. */
    private function saleRenewalAvailable(): bool
    {
        if ($this->saleReadFailed) { return false; }
        try {
            return $this->price('renew') !== null;
        } catch (Throwable $exception) {
            $this->saleReadFailed = true;
            report($exception);
            return false;
        }
    }
}
