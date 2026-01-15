<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseSlot extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'marketplace',
        'warehouse_id',
        'warehouse_name',
        'external_slot_id',
        'date',
        'time_from',
        'time_to',
        'from_datetime',
        'to_datetime',
        'coefficient',
        'is_available',
        'allow_unload',
        'capacity',
        'capacity_used',
        'boxes_limit',
        'pallets_limit',
        'box_type_id',
        'is_sorting_center',
        'storage_coefficient',
        'delivery_coefficient',
        'booked_by_shipment_id',
        'booked_at',
        'synced_at',
    ];

    protected $casts = [
        'date' => 'date',
        'from_datetime' => 'datetime',
        'to_datetime' => 'datetime',
        'coefficient' => 'decimal:3',
        'is_available' => 'boolean',
        'allow_unload' => 'boolean',
        'capacity' => 'integer',
        'capacity_used' => 'integer',
        'boxes_limit' => 'integer',
        'pallets_limit' => 'integer',
        'is_sorting_center' => 'boolean',
        'storage_coefficient' => 'decimal:3',
        'delivery_coefficient' => 'decimal:3',
        'booked_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'booked_by_shipment_id');
    }

    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    public function scopeWarehouse($query, string $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true)
            ->whereNull('booked_by_shipment_id');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('date', '>=', now()->toDateString());
    }

    public function scopeDateRange($query, ?string $from, ?string $to)
    {
        if ($from) {
            $query->where('date', '>=', $from);
        }
        if ($to) {
            $query->where('date', '<=', $to);
        }
        return $query;
    }

    public function book(string $shipmentId): bool
    {
        if (!$this->is_available || $this->booked_by_shipment_id) {
            return false;
        }

        $this->update([
            'booked_by_shipment_id' => $shipmentId,
            'booked_at' => now(),
            'is_available' => false,
        ]);

        return true;
    }

    public function release(): void
    {
        $this->update([
            'booked_by_shipment_id' => null,
            'booked_at' => null,
            'is_available' => true,
        ]);
    }

    public function getCapacityRemainingAttribute(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }
        return max(0, $this->capacity - $this->capacity_used);
    }

    public function getFormattedTimeAttribute(): string
    {
        return substr($this->time_from, 0, 5) . ' - ' . substr($this->time_to, 0, 5);
    }
}
