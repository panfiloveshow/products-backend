<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAlert extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'sku',
        'warehouse_id',
        'warehouse_name',
        'type',
        'message',
        'action',
        'priority',
        'is_resolved',
        'resolved_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function scopeActive($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', '>=', 7);
    }

    public function resolve(): void
    {
        $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
        ]);
    }
}
