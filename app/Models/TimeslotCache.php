<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Модель кэша таймслотов
 */
class TimeslotCache extends Model
{
    use HasFactory;

    protected $table = 'timeslots_cache';

    protected $fillable = [
        'integration_id',
        'cluster_id',
        'cluster_name',
        'warehouse_id',
        'warehouse_name',
        'draft_id',
        'timeslot_id',
        'slot_date',
        'time_from',
        'time_to',
        'datetime_from',
        'datetime_to',
        'is_available',
        'capacity',
        'booked_count',
        'remaining_capacity',
        'restrictions',
        'fetched_at',
        'expires_at',
    ];

    protected $casts = [
        'slot_date' => 'date',
        'datetime_from' => 'datetime',
        'datetime_to' => 'datetime',
        'is_available' => 'boolean',
        'capacity' => 'integer',
        'booked_count' => 'integer',
        'remaining_capacity' => 'integer',
        'restrictions' => 'array',
        'fetched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    // === Scopes ===

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeNotExpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeForWarehouse($query, string $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeForCluster($query, string $clusterId)
    {
        return $query->where('cluster_id', $clusterId);
    }

    public function scopeForDraft($query, string $draftId)
    {
        return $query->where('draft_id', $draftId);
    }

    public function scopeForDateRange($query, $from, $to)
    {
        return $query->whereBetween('slot_date', [$from, $to]);
    }

    public function scopeUpcoming($query, int $days = 14)
    {
        return $query->whereBetween('slot_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    // === Helpers ===

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function getFormattedTimeAttribute(): string
    {
        return substr($this->time_from, 0, 5) . ' - ' . substr($this->time_to, 0, 5);
    }

    /**
     * Очистить устаревший кэш
     */
    public static function clearExpired(): int
    {
        return self::where('expires_at', '<', now())->delete();
    }

    /**
     * Обновить кэш слотов для склада
     */
    public static function updateCache(int $integrationId, string $warehouseId, array $slots, int $ttlMinutes = 30): void
    {
        // Удаляем старые записи
        self::where('integration_id', $integrationId)
            ->where('warehouse_id', $warehouseId)
            ->delete();

        $expiresAt = now()->addMinutes($ttlMinutes);
        $fetchedAt = now();

        foreach ($slots as $slot) {
            self::create([
                'integration_id' => $integrationId,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $slot['warehouse_name'] ?? null,
                'cluster_id' => $slot['cluster_id'] ?? null,
                'cluster_name' => $slot['cluster_name'] ?? null,
                'draft_id' => $slot['draft_id'] ?? null,
                'timeslot_id' => $slot['id'] ?? $slot['timeslot_id'],
                'slot_date' => $slot['date'] ?? substr($slot['from_datetime'] ?? '', 0, 10),
                'time_from' => $slot['time_from'] ?? substr($slot['from_datetime'] ?? '', 11, 5),
                'time_to' => $slot['time_to'] ?? substr($slot['to_datetime'] ?? '', 11, 5),
                'datetime_from' => $slot['from_datetime'] ?? null,
                'datetime_to' => $slot['to_datetime'] ?? null,
                'is_available' => $slot['is_available'] ?? true,
                'capacity' => $slot['capacity'] ?? null,
                'booked_count' => $slot['booked_count'] ?? 0,
                'remaining_capacity' => $slot['remaining_capacity'] ?? $slot['capacity'] ?? null,
                'restrictions' => $slot['restrictions'] ?? null,
                'fetched_at' => $fetchedAt,
                'expires_at' => $expiresAt,
            ]);
        }
    }
}
