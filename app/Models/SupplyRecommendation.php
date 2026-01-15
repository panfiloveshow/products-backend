<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyRecommendation extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const PRIORITY_URGENT = 'urgent';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_LOW = 'low';

    protected $fillable = [
        'integration_id',
        'marketplace',
        'warehouse_id',
        'warehouse_name',
        'priority',
        'title',
        'description',
        'reason',
        'critical_items',
        'recommended_items',
        'total_items',
        'total_quantity',
        'total_cost',
        'total_volume',
        'total_weight',
        'estimated_delivery_cost',
        'estimated_storage_cost',
        'estimated_profit',
        'deadline',
        'seasonal_factors',
        'is_used',
        'used_in_shipment_id',
        'used_at',
        'is_dismissed',
        'dismissed_at',
        'dismissed_reason',
    ];

    protected $casts = [
        'critical_items' => 'array',
        'recommended_items' => 'array',
        'seasonal_factors' => 'array',
        'total_items' => 'integer',
        'total_quantity' => 'integer',
        'total_cost' => 'decimal:2',
        'total_volume' => 'decimal:3',
        'total_weight' => 'decimal:3',
        'estimated_delivery_cost' => 'decimal:2',
        'estimated_storage_cost' => 'decimal:2',
        'estimated_profit' => 'decimal:2',
        'deadline' => 'date',
        'is_used' => 'boolean',
        'used_at' => 'datetime',
        'is_dismissed' => 'boolean',
        'dismissed_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function usedInShipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'used_in_shipment_id');
    }

    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    public function scopePriority($query, $priority)
    {
        if (is_array($priority)) {
            return $query->whereIn('priority', $priority);
        }
        return $query->where('priority', $priority);
    }

    public function scopeActive($query)
    {
        return $query->where('is_used', false)
            ->where('is_dismissed', false);
    }

    public function scopeUrgent($query)
    {
        return $query->where('priority', self::PRIORITY_URGENT);
    }

    public function scopeWithDeadline($query)
    {
        return $query->whereNotNull('deadline')
            ->where('deadline', '>=', now()->toDateString());
    }

    public function markAsUsed(string $shipmentId): void
    {
        $this->update([
            'is_used' => true,
            'used_in_shipment_id' => $shipmentId,
            'used_at' => now(),
        ]);
    }

    public function dismiss(?string $reason = null): void
    {
        $this->update([
            'is_dismissed' => true,
            'dismissed_at' => now(),
            'dismissed_reason' => $reason,
        ]);
    }

    public function getCriticalItemsCountAttribute(): int
    {
        return count($this->critical_items ?? []);
    }

    public function getIsOverdueAttribute(): bool
    {
        if (!$this->deadline) {
            return false;
        }
        return $this->deadline->isPast();
    }

    public function getPriorityLevelAttribute(): int
    {
        return match ($this->priority) {
            self::PRIORITY_URGENT => 4,
            self::PRIORITY_HIGH => 3,
            self::PRIORITY_MEDIUM => 2,
            self::PRIORITY_LOW => 1,
            default => 0,
        };
    }
}
