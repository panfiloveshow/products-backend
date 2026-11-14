<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagerFinancialSettingChange extends Model
{
    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
    protected $hidden = ['credential_binding', 'product_binding', 'baseline'];
    protected $casts = ['values' => 'array', 'baseline' => 'array', 'events' => 'array', 'expires_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];
}
