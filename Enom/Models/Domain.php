<?php

namespace Paymenter\Extensions\Servers\Enom\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    protected $table = 'enom_domains';
    protected $guarded = [];
    protected $hidden = ['snapshot', 'renewal_policy', 'observation', 'provider_domain_id', 'reservation_name'];
    protected $attributes = ['billing_managed' => true, 'price_mode' => 'standard'];

    protected $casts = ['provider_expires_at' => 'immutable_date', 'snapshot' => 'encrypted:array', 'capabilities' => 'array', 'last_synced_at' => 'immutable_datetime',
        'billing_managed' => 'boolean', 'renewal_policy' => 'encrypted:array', 'observation' => 'encrypted:array',
        'authority_generation' => 'integer', 'confirmed_revision' => 'integer', 'reminders' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $domain) { $domain->reservation_name = $domain->domain; });
    }

    public function service() { return $this->belongsTo(Service::class); }
    public function operations() { return $this->hasMany(Operation::class); }
}
