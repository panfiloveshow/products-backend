<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SellerWarehouseStock extends Model
{
    use HasFactory;

    protected $fillable = [
        'integration_id',
        'sku',
        'barcode',
        'product_name',
        'quantity',
        'reserved',
        'cost_price',
        'location',
        'note',
        'last_counted_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved' => 'integer',
        'cost_price' => 'decimal:2',
        'last_counted_at' => 'datetime',
    ];

    /**
     * Доступный остаток (quantity - reserved)
     */
    public function getAvailableAttribute(): int
    {
        return max(0, $this->quantity - $this->reserved);
    }

    /**
     * Получить маппинг SKU => available для интеграции
     * Возвращает только SKU с остатком > 0
     */
    public static function getStockMap(int $integrationId): array
    {
        return static::where('integration_id', $integrationId)
            ->where('quantity', '>', 0)
            ->get()
            ->mapWithKeys(fn($s) => [
                $s->sku => [
                    'quantity' => $s->quantity,
                    'reserved' => $s->reserved,
                    'available' => $s->available,
                    'cost_price' => $s->cost_price,
                ],
            ])
            ->toArray();
    }

    public function scopeForIntegration($query, int $integrationId)
    {
        return $query->where('integration_id', $integrationId);
    }

    public function scopeInStock($query)
    {
        return $query->where('quantity', '>', 0);
    }
}
