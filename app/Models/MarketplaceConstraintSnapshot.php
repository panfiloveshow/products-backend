<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Агрегированный, готовый-к-плану срез ограничений маркетплейса на интеграцию,
 * наполняемый авто-синхронизацией из API (в отличие от ручного AutoSupplyConstraintFile).
 * Одна актуальная строка на (integration_id, marketplace), перезаписывается синком.
 */
class MarketplaceConstraintSnapshot extends Model
{
    use Traits\BelongsToCurrentWorkspaceThroughIntegration;

    protected $fillable = [
        'integration_id',
        'marketplace',
        'cluster_constraints_json',
        'warehouse_constraints_json',
        'summary_json',
        'sources_json',
        'sync_status',
        'sync_error',
        'synced_at',
    ];

    protected $casts = [
        'cluster_constraints_json' => 'array',
        'warehouse_constraints_json' => 'array',
        'summary_json' => 'array',
        'sources_json' => 'array',
        'synced_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * Свежие, успешно синканные снапшоты (для использования при создании плана).
     * Назван usable, а не fresh, чтобы не путать со встроенным Model::fresh().
     */
    public function scopeUsable(Builder $query, int $maxAgeHours = 48): Builder
    {
        return $query
            ->where('sync_status', '!=', 'error')
            ->where('synced_at', '>=', now()->subHours($maxAgeHours));
    }

    public function isUsable(int $maxAgeHours = 48): bool
    {
        return $this->sync_status !== 'error'
            && $this->synced_at !== null
            && $this->synced_at->greaterThanOrEqualTo(now()->subHours($maxAgeHours));
    }

    /**
     * Сырой формат ограничений для $plan->params (как cluster/warehouse_constraints).
     *
     * @return array{cluster_constraints: array, warehouse_constraints: array}
     */
    public function toPlanConstraints(): array
    {
        return [
            'cluster_constraints' => $this->cluster_constraints_json ?? [],
            'warehouse_constraints' => $this->warehouse_constraints_json ?? [],
        ];
    }

    /**
     * Метаданные источника для $plan->params['constraint_metadata'].
     * Совместимо с AutoSupplyConstraintFile::toPlanMetadata (ключ summary),
     * но помечено source_kind = 'api_sync'.
     *
     * @return array<string, mixed>
     */
    public function toPlanMetadata(): array
    {
        return [
            'constraint_snapshot_id' => $this->id,
            'marketplace' => $this->marketplace,
            'source_kind' => 'api_sync',
            'source' => [
                'kind' => 'api_sync',
                'synced_at' => $this->synced_at?->toIso8601String(),
                'sync_status' => $this->sync_status,
            ],
            'summary' => $this->summary_json ?? [],
            'sources' => $this->sources_json ?? [],
        ];
    }
}
