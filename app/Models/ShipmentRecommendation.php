<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShipmentRecommendation extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'priority',
        'title',
        'description',
        'critical_items',
        'recommended_items',
        'total_cost',
        'total_volume',
        'estimated_delivery_cost',
        'reason',
        'deadline',
        'seasonal_factors',
        'is_used',
    ];

    protected $casts = [
        'critical_items' => 'array',
        'recommended_items' => 'array',
        'total_cost' => 'decimal:2',
        'total_volume' => 'decimal:3',
        'estimated_delivery_cost' => 'decimal:2',
        'deadline' => 'date',
        'seasonal_factors' => 'array',
        'is_used' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_used', false);
    }

    public function scopePriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }

    public function markAsUsed(): void
    {
        $this->update(['is_used' => true]);
    }
}
