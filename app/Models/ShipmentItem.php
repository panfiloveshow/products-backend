<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentItem extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'shipment_id',
        'sku',
        'product_name',
        'image_url',
        'current_stock',
        'days_of_stock',
        'priority',
        'quantity',
        'cost_price',
        'total_cost',
        'ml_recommended',
        'ml_quantity',
        'ml_reason',
        'ml_confidence',
        'volume_per_unit',
        'weight_per_unit',
        'total_volume',
        'total_weight',
        'marketplaces',
    ];

    protected $casts = [
        'current_stock' => 'integer',
        'days_of_stock' => 'integer',
        'quantity' => 'integer',
        'cost_price' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'ml_recommended' => 'boolean',
        'ml_quantity' => 'integer',
        'ml_confidence' => 'decimal:2',
        'volume_per_unit' => 'decimal:6',
        'weight_per_unit' => 'decimal:3',
        'total_volume' => 'decimal:3',
        'total_weight' => 'decimal:3',
        'marketplaces' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (ShipmentItem $item) {
            $item->total_cost = $item->quantity * ($item->cost_price ?? 0);
            $item->total_volume = $item->quantity * ($item->volume_per_unit ?? 0);
            $item->total_weight = $item->quantity * ($item->weight_per_unit ?? 0);
        });

        static::saved(function (ShipmentItem $item) {
            $item->shipment->recalculateTotals();
        });

        static::deleted(function (ShipmentItem $item) {
            $item->shipment->recalculateTotals();
        });
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function scopePriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeMlRecommended($query)
    {
        return $query->where('ml_recommended', true);
    }
}
