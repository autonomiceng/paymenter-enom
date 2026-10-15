<?php

namespace Paymenter\Extensions\Servers\Enom\Models;

use Illuminate\Database\Eloquent\Model;

class OrderIntent extends Model
{
    protected $table = 'enom_order_intents';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['payload'];
    protected $casts = ['payload' => 'encrypted:array', 'amount' => 'decimal:2', 'renewal_amount' => 'decimal:2', 'expires_at' => 'immutable_datetime', 'claimed_at' => 'immutable_datetime'];
}
