<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AutoSupplyPlan extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CALCULATING = 'calculating';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';

    public const BUSINESS_STATUS_DRAFT = 'draft';
    public const BUSINESS_STATUS_DATA_BLOCKED = 'data_blocked';
    public const BUSINESS_STATUS_REVIEW_REQUIRED = 'review_required';
    public const BUSINESS_STATUS_READY_TO_APPROVE = 'ready_to_approve';
    public const BUSINESS_STATUS_VALIDATION_BLOCKED = 'validation_blocked';
    public const BUSINESS_STATUS_APPROVED = 'approved';
    public const BUSINESS_STATUS_EXECUTING = 'executing';
    public const BUSINESS_STATUS_EXECUTION_FAILED = 'execution_failed';
    public const BUSINESS_STATUS_IN_TRANSIT = 'in_transit';
    public const BUSINESS_STATUS_RECEIVED = 'received';
    public const BUSINESS_STATUS_RECONCILED = 'reconciled';
    public const BUSINESS_STATUS_CANCELLED = 'cancelled';

    public const MODE_ANTI_OOS = 'anti_oos';
    public const MODE_BALANCED = 'balanced';
    public const MODE_CASH_SAFE = 'cash_safe';
    public const MODE_PROTECT_OOS = 'protect_oos';
    public const MODE_IMPROVE_LOCALITY = 'improve_locality';
    public const MODE_MAX_PROFIT = 'max_profit';
    public const MODE_POST_PROMO_CAREFUL = 'post_promo_careful';

    protected $appends = [
        'facts_freshness',
        'planning_sources',
        'demand_granularity',
        'quality_gate_status',
        'quality_gate_reasons',
        'deficit_summary',
        'surplus_summary',
        'deficit_surplus_summary',
        'economics_summary',
        'selection_summary',
        'constraints_summary',
        'territorial_summary',
        'marketplace_capabilities',
    ];

    protected $fillable = [
        'tenant_id',
        'snapshot_id',
        'integration_id',
        'mp_account_id',
        'marketplace',
        'status',
        'business_status',
        'approved_at',
        'approved_by',
        'approval_fingerprint',
        'materialized_at',
        'execution_started_at',
        'execution_completed_at',
        'last_execution_error',
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
        'calculation_engine',
        'forecast_version',
        'allocation_version',
        'adapter_version',
        'code_commit',
        'params',
        'requested_params_json',
        'effective_params_json',
        'data_quality_score',
        'data_quality_json',
        'validation_json',
        'validated_at',
        'validation_fingerprint',
        'result_json',
        'accuracy_json',
        'total_lines',
        'total_qty',
        'error_message',
        'export_errors',
    ];

    protected $casts = [
        'params' => 'array',
        'requested_params_json' => 'array',
        'effective_params_json' => 'array',
        'data_quality_json' => 'array',
        'validation_json' => 'array',
        'result_json' => 'array',
        'accuracy_json' => 'array',
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
        'approved_at' => 'datetime',
        'approved_by' => 'integer',
        'materialized_at' => 'datetime',
        'validated_at' => 'datetime',
        'execution_started_at' => 'datetime',
        'execution_completed_at' => 'datetime',
    ];

    public function getFactsFreshnessAttribute(): array
    {
        return $this->result_json['facts_freshness'] ?? [];
    }

    public function getPlanningSourcesAttribute(): array
    {
        return $this->data_quality_json['meta']['planning_fact_sources']
            ?? $this->result_json['planning_sources']
            ?? [];
    }

    public function getDemandGranularityAttribute(): string
    {
        return $this->result_json['demand_granularity']
            ?? match ($this->marketplace) {
                'ozon' => 'cluster',
                'yandex', 'yandex_market' => 'sku',
                default => 'warehouse',
            };
    }

    public function getQualityGateStatusAttribute(): ?string
    {
        return $this->data_quality_json['meta']['quality_gate_status'] ?? null;
    }

    public function getQualityGateReasonsAttribute(): array
    {
        return $this->data_quality_json['meta']['quality_gate_reasons'] ?? [];
    }

    public function getDeficitSummaryAttribute(): array
    {
        return $this->result_json['deficit_summary'] ?? [];
    }

    public function getSurplusSummaryAttribute(): array
    {
        return $this->result_json['surplus_summary'] ?? [];
    }

    public function getDeficitSurplusSummaryAttribute(): array
    {
        return $this->result_json['deficit_surplus_summary'] ?? [];
    }

    public function getEconomicsSummaryAttribute(): array
    {
        return $this->result_json['economics_summary'] ?? [];
    }

    public function getSelectionSummaryAttribute(): array
    {
        return $this->result_json['selection_summary'] ?? [];
    }

    public function getConstraintsSummaryAttribute(): array
    {
        return $this->result_json['constraints_summary'] ?? [];
    }

    public function getTerritorialSummaryAttribute(): array
    {
        return $this->result_json['territorial_summary'] ?? [];
    }

    public function getPlanQualityAuditAttribute(): array
    {
        return $this->result_json['plan_quality_audit'] ?? [];
    }

    public function getMarketplaceCapabilitiesAttribute(): array
    {
        return $this->result_json['marketplace_capabilities'] ?? [];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AutoSupplyPlanLine::class);
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(PlanningFactSnapshot::class, 'auto_supply_plan_id');
    }

    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class, 'auto_supply_plan_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(AutoSupplyPlanAdjustment::class, 'auto_supply_plan_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutoSupplyPlanExecution::class, 'auto_supply_plan_id');
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
        $this->update([
            'status' => self::STATUS_CALCULATING,
            'business_status' => self::BUSINESS_STATUS_DRAFT,
            'approved_at' => null,
            'approved_by' => null,
            'approval_fingerprint' => null,
            'materialized_at' => null,
            'validation_json' => null,
            'validated_at' => null,
            'validation_fingerprint' => null,
            'execution_started_at' => null,
            'execution_completed_at' => null,
            'last_execution_error' => null,
        ]);
    }

    public function markReady(float $qualityScore, int $totalLines, int $totalQty, ?array $qualityJson = null): void
    {
        $data = [
            'status' => self::STATUS_READY,
            'business_status' => self::BUSINESS_STATUS_REVIEW_REQUIRED,
            'approved_at' => null,
            'approved_by' => null,
            'approval_fingerprint' => null,
            'materialized_at' => null,
            'validation_json' => null,
            'validated_at' => null,
            'validation_fingerprint' => null,
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
            'business_status' => self::BUSINESS_STATUS_DATA_BLOCKED,
            'approved_at' => null,
            'approved_by' => null,
            'approval_fingerprint' => null,
            'materialized_at' => null,
            'validation_json' => null,
            'validated_at' => null,
            'validation_fingerprint' => null,
            'error_message' => $message,
        ]);
    }

    public function invalidateApproval(): void
    {
        $this->update([
            'business_status' => $this->status === self::STATUS_READY
                ? self::BUSINESS_STATUS_REVIEW_REQUIRED
                : self::BUSINESS_STATUS_DRAFT,
            'approved_at' => null,
            'approved_by' => null,
            'approval_fingerprint' => null,
            'materialized_at' => null,
            'validation_json' => null,
            'validated_at' => null,
            'validation_fingerprint' => null,
        ]);
    }
}
