<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyPlan extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'name',
        'description',
        'period_start',
        'period_end',
        'integration_id',
        'marketplace',
        'target_days_of_stock',
        'safety_stock_days',
        'total_items',
        'total_quantity',
        'total_cost',
        'total_volume',
        'total_weight',
        'estimated_storage_cost',
        'estimated_profit',
        'estimated_roi',
        'status',
        'created_by',
        'created_by_name',
        'approved_by',
        'approved_by_name',
        'approved_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'target_days_of_stock' => 'integer',
        'safety_stock_days' => 'integer',
        'total_items' => 'integer',
        'total_quantity' => 'integer',
        'total_cost' => 'decimal:2',
        'total_volume' => 'decimal:3',
        'total_weight' => 'decimal:3',
        'estimated_storage_cost' => 'decimal:2',
        'estimated_profit' => 'decimal:2',
        'estimated_roi' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'supply_plan_id');
    }

    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    public function scopeStatus($query, $status)
    {
        if (is_array($status)) {
            return $query->whereIn('status', $status);
        }
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_DRAFT,
            self::STATUS_APPROVED,
            self::STATUS_IN_PROGRESS,
        ]);
    }

    public function approve(?string $userId = null, ?string $userName = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $userId ?? auth()->id(),
            'approved_by_name' => $userName ?? auth()->user()?->name,
            'approved_at' => now(),
        ]);
    }

    public function markInProgress(): void
    {
        $this->update(['status' => self::STATUS_IN_PROGRESS]);
    }

    public function complete(): void
    {
        $this->update(['status' => self::STATUS_COMPLETED]);
    }

    public function cancel(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    public function recalculateTotals(): void
    {
        $shipments = $this->shipments;
        
        $this->update([
            'total_items' => $shipments->sum('total_items'),
            'total_quantity' => $shipments->sum('total_quantity'),
            'total_cost' => $shipments->sum('total_cost'),
            'total_volume' => $shipments->sum('total_volume'),
            'total_weight' => $shipments->sum('total_weight'),
        ]);
    }
}
