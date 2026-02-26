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

    public const MODE_ANTI_OOS = 'anti_oos';
    public const MODE_BALANCED = 'balanced';
    public const MODE_CASH_SAFE = 'cash_safe';

    protected $fillable = [
        'tenant_id',
        'integration_id',
        'mp_account_id',
        'marketplace',
        'status',
        'mode',
        'horizon_days',
        'min_cover_days',
        'target_cover_days',
        'max_cover_days',
        'safety_stock_days',
        'turnover_limit_days',
        'budget_limit',
        'forecast_model',
        'algorithm_version',
        'params',
        'data_quality_score',
        'data_quality_json',
        'result_json',
        'total_lines',
        'total_qty',
        'error_message',
        'export_errors',
    ];

    protected $casts = [
        'params' => 'array',
        'data_quality_json' => 'array',
        'result_json' => 'array',
        'export_errors' => 'array',
        'data_quality_score' => 'decimal:2',
        'budget_limit' => 'decimal:2',
        'total_lines' => 'integer',
        'total_qty' => 'integer',
        'horizon_days' => 'integer',
        'min_cover_days' => 'integer',
        'target_cover_days' => 'integer',
        'max_cover_days' => 'integer',
        'safety_stock_days' => 'integer',
        'turnover_limit_days' => 'integer',
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

    public function markReady(float $qualityScore, int $totalLines, int $totalQty, ?array $qualityJson = null): void
    {
        $data = [
            'status' => self::STATUS_READY,
            'data_quality_score' => $qualityScore,
            'data_quality_json' => $qualityJson,
            'total_lines' => $totalLines,
            'total_qty' => $totalQty,
        ];
        if ($this->result_json !== null) {
            $data['result_json'] = $this->result_json;
        }
        $this->update($data);
    }

    public function markError(string $message): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'error_message' => $message,
        ]);
    }
}
