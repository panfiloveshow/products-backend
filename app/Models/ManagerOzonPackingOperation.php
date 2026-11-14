<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagerOzonPackingOperation extends Model
{
    protected $table = 'manager_ozon_packing_operations';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['plan', 'store_binding', 'confirmations'];
    protected function casts(): array
    {
        return ['plan' => 'array', 'confirmations' => 'array', 'expires_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
