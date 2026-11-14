<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagerWbSupplyOperation extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['plan', 'store_binding', 'confirmations'];
    protected function casts(): array
    {
        return ['plan' => 'array', 'result' => 'array', 'confirmations' => 'array',
            'expires_at' => 'immutable_datetime', 'execution_started_at' => 'immutable_datetime', 'native_started_at' => 'immutable_datetime'];
    }
}
