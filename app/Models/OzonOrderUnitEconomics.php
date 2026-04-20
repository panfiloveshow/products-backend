<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzonOrderUnitEconomics extends Model
{
    use HasFactory;

    protected $table = 'ozon_order_unit_economics';

    protected $fillable = [
        'integration_id',
        'posting_id',
        'posting_item_id',
        'posting_number',
        'sku',
        'offer_id',
        'order_date',
        'sale_price',
        'volume_liters',
        'price_bucket',
        'shipping_cluster_id',
        'shipping_cluster_name',
        'destination_cluster_id',
        'destination_cluster_name',
        'fixation_applied',
        'fixation_id',
        'fixation_base_date',
        'fixed_until',
        'tariff_version_used',
        'markup_version_used',
        'base_logistics_tariff',
        'non_local_markup_percent',
        'non_local_markup_amount',
        'markup_applied',
        'markup_reason_code',
        'markup_reason_label',
        'markup_exception_code',
        'markup_exception_label',
        'markup_exception_status',
        'calculation_mode',
        'calculation_confidence',
        'meta',
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'sale_price' => 'decimal:2',
        'volume_liters' => 'decimal:4',
        'fixation_applied' => 'boolean',
        'fixation_base_date' => 'date',
        'fixed_until' => 'date',
        'base_logistics_tariff' => 'decimal:2',
        'non_local_markup_percent' => 'decimal:2',
        'non_local_markup_amount' => 'decimal:2',
        'markup_applied' => 'boolean',
        'meta' => 'array',
    ];

    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class, 'posting_id');
    }

    public function postingItem(): BelongsTo
    {
        return $this->belongsTo(PostingItem::class, 'posting_item_id');
    }
}
