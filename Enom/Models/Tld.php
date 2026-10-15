<?php

namespace Paymenter\Extensions\Servers\Enom\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;

/** One domain ending offered through an Enom server, with retail prices and the last known Enom cost. */
class Tld extends Model
{
    public const MODES = ['fixed', 'markup_amount', 'markup_percent'];

    public const ACTIONS = ['register', 'renew', 'transfer'];

    protected $table = 'enom_tlds';

    protected $guarded = [];

    /** Costs never enter arrays, JSON or Livewire state; read them through cost(). */
    protected $hidden = ['cost_register', 'cost_renew', 'cost_transfer'];

    protected $casts = [
        'enabled' => 'boolean', 'markup' => 'decimal:2',
        'register_price' => 'decimal:2', 'renew_price' => 'decimal:2', 'transfer_price' => 'decimal:2',
        'cost_register' => 'decimal:2', 'cost_renew' => 'decimal:2', 'cost_transfer' => 'decimal:2',
        'costs_synced_at' => 'immutable_datetime', 'sort_order' => 'integer',
        'min_years' => 'integer', 'max_years' => 'integer', 'registry_fields' => 'array',
    ];

    public function server() { return $this->belongsTo(Server::class); }

    /** Orderable only when enabled with all three positive retail prices. */
    public function orderable(): bool
    {
        return $this->enabled && $this->price('register') !== null && $this->price('renew') !== null && $this->price('transfer') !== null;
    }

    public function price(string $action): ?string
    {
        $value = $this->{$action.'_price'} ?? null;
        return is_numeric($value) && (float) $value > 0 ? number_format((float) $value, 2, '.', '') : null;
    }

    public function cost(string $action): ?string
    {
        $value = $this->{'cost_'.$action} ?? null;
        return is_numeric($value) && (float) $value >= 0 ? number_format((float) $value, 2, '.', '') : null;
    }

    public function getDisplayNameAttribute(): string { return '.'.$this->tld; }

    /** @return list<int> registration terms on sale for this ending */
    public function terms(): array
    {
        $min = max(1, min(10, (int) ($this->min_years ?: 1)));
        $max = max($min, min(10, (int) ($this->max_years ?: 10)));
        return range($min, $max);
    }

    /** Registration price for $years: first year at the registration price, later years at the renewal price. */
    public function registrationTotal(int $years): ?string
    {
        $register = $this->price('register');
        $renew = $this->price('renew');
        if ($register === null || $renew === null || !in_array($years, $this->terms(), true)) { return null; }
        $minor = (int) round(((float) $register) * 100) + (int) round(((float) $renew) * 100) * ($years - 1);
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
