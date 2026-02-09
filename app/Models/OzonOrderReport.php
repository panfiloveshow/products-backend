<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OzonOrderReport extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'integration_id',
        'filename',
        'period_label',
        'date_from',
        'date_to',
        'total_orders',
        'total_items',
        'unique_skus',
        'unique_warehouses',
        'status',
        'error_message',
    ];

    protected $casts = [
        'integration_id' => 'integer',
        'date_from' => 'date',
        'date_to' => 'date',
        'total_orders' => 'integer',
        'total_items' => 'integer',
        'unique_skus' => 'integer',
        'unique_warehouses' => 'integer',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(OzonWarehouseSale::class, 'report_id');
    }
}
