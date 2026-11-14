<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagerPriceProposal extends Model
{
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    protected $hidden = ['binding', 'approval'];
    protected $casts = ['seller_price' => 'decimal:2', 'constraints' => 'array', 'snapshot' => 'array',
        'binding' => 'array', 'events' => 'array', 'approval' => 'array', 'executor_receipt' => 'array', 'dispatch_started_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
}
