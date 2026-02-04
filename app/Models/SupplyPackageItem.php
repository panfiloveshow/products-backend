<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель позиции в грузоместе
 * 
 * Связывает товар (SupplyItem) с грузоместом (SupplyPackage)
 */
class SupplyPackageItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'supply_item_id',
        'product_id',
        'sku',
        'barcode',
        'product_name',
        'quantity',
        'weight',           // Вес единицы товара
        'expiry_date',      // Срок годности (Годен до)
        'scanned_at',       // Когда отсканировали при упаковке
        'scanned_by',
        'meta',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'weight' => 'decimal:3',
        'expiry_date' => 'date',
        'scanned_at' => 'datetime',
        'meta' => 'array',
    ];

    // === Relationships ===

    public function package(): BelongsTo
    {
        return $this->belongsTo(SupplyPackage::class, 'package_id');
    }

    public function supplyItem(): BelongsTo
    {
        return $this->belongsTo(SupplyItem::class, 'supply_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scannedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    // === Helpers ===

    public function getTotalWeightAttribute(): float
    {
        return ($this->weight ?? 0) * $this->quantity;
    }
}
