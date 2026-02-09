<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzonWarehouseSale extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ozon_warehouse_sales';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'report_id',
        'integration_id',
        'sku',
        'article',
        'product_name',
        'warehouse_name',
        'shipment_cluster',
        'delivery_cluster',
        'orders_count',
        'items_sold',
        'revenue',
        'avg_daily_sales',
        'period_days',
        'date_from',
        'date_to',
    ];

    protected $casts = [
        'integration_id' => 'integer',
        'orders_count' => 'integer',
        'items_sold' => 'integer',
        'revenue' => 'decimal:2',
        'avg_daily_sales' => 'decimal:2',
        'period_days' => 'integer',
        'date_from' => 'date',
        'date_to' => 'date',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(OzonOrderReport::class, 'report_id');
    }
}
