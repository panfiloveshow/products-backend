<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutoSupplyPlan extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CALCULATING = 'calculating';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'integration_id',
        'marketplace',
        'status',
        'params',
        'data_quality_score',
        'total_lines',
        'total_qty',
        'error_message',
        'export_errors',
    ];

    protected $casts = [
        'params' => 'array',
        'export_errors' => 'array',
        'data_quality_score' => 'decimal:2',
        'total_lines' => 'integer',
        'total_qty' => 'integer',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AutoSupplyPlanLine::class);
    }

    public function scopeForIntegration($query, int $integrationId)
    {
        return $query->where('integration_id', $integrationId);
    }

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function markCalculating(): void
    {
        $this->update(['status' => self::STATUS_CALCULATING]);
    }

    public function markReady(float $qualityScore, int $totalLines, int $totalQty): void
    {
        $this->update([
            'status' => self::STATUS_READY,
            'data_quality_score' => $qualityScore,
            'total_lines' => $totalLines,
            'total_qty' => $totalQty,
        ]);
    }

    public function markError(string $message): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'error_message' => $message,
        ]);
    }
}
