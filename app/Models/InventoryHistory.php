<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryHistory extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'inventory_history';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'sku',
        'warehouse_id',
        'date',
        'quantity',
        'sales',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity' => 'integer',
        'sales' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id', 'warehouse_id');
    }

    public function scopeForPeriod($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeLastDays($query, int $days = 30)
    {
        return $query->where('date', '>=', now()->subDays($days)->toDateString());
    }
}
