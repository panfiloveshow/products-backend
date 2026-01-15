<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'marketplace',
        'integration_id',
        'sync_type',
        'status',
        'items_synced',
        'items_failed',
        'error_message',
        'metadata',
        'credentials',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'items_synced' => 'integer',
        'items_failed' => 'integer',
        'metadata' => 'array',
        'credentials' => 'encrypted:array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scopeMarketplace($query, string $marketplace)
    {
        return $query->where('marketplace', $marketplace);
    }

    public function scopeSyncType($query, string $syncType)
    {
        return $query->where('sync_type', $syncType);
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeRunning($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function start(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function complete(int $synced = 0, int $failed = 0): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'items_synced' => $synced,
            'items_failed' => $failed,
            'completed_at' => now(),
        ]);
    }

    public function fail(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function getDurationAttribute(): ?int
    {
        if (!$this->started_at) {
            return null;
        }
        $end = $this->completed_at ?? now();
        return $this->started_at->diffInSeconds($end);
    }
}
