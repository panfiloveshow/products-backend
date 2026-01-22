<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель позиции поставки
 */
class SupplyItem extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PICKING = 'picking';
    public const STATUS_PACKED = 'packed';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PARTIAL = 'partial';

    protected $fillable = [
        'supply_id',
        'product_id',
        'sku',
        'ozon_product_id',
        'barcode',
        'product_name',
        'planned_qty',
        'packed_qty',
        'shipped_qty',
        'accepted_qty',
        'rejected_qty',
        'pack_multiple',
        'boxes_count',
        'weight',
        'length',
        'width',
        'height',
        'status',
        'rejection_reason',
        'recommendation_id',
        'meta',
    ];

    protected $casts = [
        'planned_qty' => 'integer',
        'packed_qty' => 'integer',
        'shipped_qty' => 'integer',
        'accepted_qty' => 'integer',
        'rejected_qty' => 'integer',
        'pack_multiple' => 'integer',
        'boxes_count' => 'integer',
        'weight' => 'decimal:3',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'meta' => 'array',
    ];

    // === Relationships ===

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(SupplyRecommendation::class, 'recommendation_id');
    }

    // === Scopes ===

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePacked($query)
    {
        return $query->where('status', self::STATUS_PACKED);
    }

    // === Helpers ===

    public function getTotalWeightAttribute(): float
    {
        return ($this->weight ?? 0) * $this->planned_qty;
    }

    public function getVolumeAttribute(): float
    {
        if (!$this->length || !$this->width || !$this->height) {
            return 0;
        }
        return ($this->length * $this->width * $this->height) / 1000000; // см³ → м³
    }

    public function getTotalVolumeAttribute(): float
    {
        return $this->volume * $this->planned_qty;
    }

    public function getAcceptanceRateAttribute(): ?float
    {
        if ($this->accepted_qty === null) {
            return null;
        }
        return $this->planned_qty > 0 
            ? round(($this->accepted_qty / $this->planned_qty) * 100, 2) 
            : null;
    }
}
