<?php

namespace Paymenter\Extensions\Servers\Enom\Models;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    protected $table = 'enom_operations';
    protected $guarded = [];
    protected $hidden = ['payload', 'policy', 'cost', 'funding_reason', 'authorized_by', 'request_fingerprint'];
    protected $casts = ['payload' => 'encrypted:array', 'policy' => 'encrypted:array', 'cost' => 'encrypted:array', 'amount' => 'decimal:2', 'base_expires_at' => 'immutable_date', 'expected_expires_at' => 'immutable_date', 'confirmed_expires_at' => 'immutable_date', 'lease_expires_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    public function domain() { return $this->belongsTo(Domain::class); }
}
