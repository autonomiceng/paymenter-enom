<?php

namespace Paymenter\Extensions\Servers\Enom\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    protected $table = 'enom_domains';
    protected $guarded = [];
    protected $hidden = ['snapshot', 'renewal_policy', 'sponsorship', 'observation', 'provider_domain_id'];
    protected $casts = ['provider_expires_at' => 'immutable_date', 'snapshot' => 'encrypted:array', 'capabilities' => 'array', 'last_synced_at' => 'immutable_datetime', 'drift_detected_at' => 'immutable_datetime',
        'billing_managed' => 'boolean', 'renewal_policy' => 'encrypted:array', 'sponsorship' => 'encrypted:array', 'observation' => 'encrypted:array',
        'authority_generation' => 'integer', 'confirmed_revision' => 'integer', 'reminders' => 'array'];

    public function service() { return $this->belongsTo(Service::class); }
    public function operations() { return $this->hasMany(Operation::class); }
}
