<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ManagerOzonPackingItem extends Model
{
    protected $table = 'manager_ozon_packing_items';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['plan', 'receipt'];
    protected function casts(): array
    {
        return ['position' => 'integer', 'plan' => 'array', 'receipt' => 'array', 'result' => 'array', 'native_started_at' => 'immutable_datetime'];
    }
}
