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

    protected $casts = [
        'enabled' => 'boolean', 'markup' => 'decimal:2',
        'register_price' => 'decimal:2', 'renew_price' => 'decimal:2', 'transfer_price' => 'decimal:2',
        'cost_register' => 'decimal:2', 'cost_renew' => 'decimal:2', 'cost_transfer' => 'decimal:2',
        'costs_synced_at' => 'immutable_datetime', 'sort_order' => 'integer',
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
}
