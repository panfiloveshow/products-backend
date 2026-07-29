<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use Illuminate\Support\Facades\DB;

/**
 * Pre-flight проверка Ozon-плана перед юридически значимым подтверждением.
 */
class OzonPlanValidationService
{
    public function __construct(
        private OzonPlanMaterializationService $materialization,
        private DataFreshnessRegistry $freshness
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function validate(AutoSupplyPlan $plan): array
    {
        return DB::transaction(function () use ($plan): array {
            /** @var AutoSupplyPlan $locked */
            $locked = AutoSupplyPlan::query()
                ->with(['snapshot', 'integration'])
                ->lockForUpdate()
                ->findOrFail($plan->id);

            $check = $this->materialization->preflightCheck($locked);
            $errors = $check['errors'];
            $warnings = $check['warnings'];

            if ($locked->snapshot === null || $locked->snapshot->status !== 'ready') {
                $errors[] = [
                    'code' => 'facts_snapshot_required',
                    'message' => 'У расчёта нет завершённого снимка исходных фактов.',
                ];
            }

            $destinationMapping = is_array($locked->result_json['ozon_destination_mapping'] ?? null)
                ? $locked->result_json['ozon_destination_mapping']
                : [];
            if ((int) ($destinationMapping['rows_unmatched'] ?? 0) > 0) {
                $errors[] = [
                    'code' => 'destination_mapping_incomplete',
                    'message' => sprintf(
                        'Не сопоставлено направлений Ozon: %d (%.2f%%). Обновите карту складов/кластеров.',
                        (int) ($destinationMapping['rows_unmatched'] ?? 0),
                        (float) ($destinationMapping['unmatched_rate_percent'] ?? 0)
                    ),
                    'context' => [
                        'examples' => $destinationMapping['unmatched_examples'] ?? [],
                    ],
                ];
            }

            $activeClusterIds = $locked->lines()
                ->whereNotNull('cluster_id')
                ->where('qty_rounded', '>', 0)
                ->where('is_excluded', false)
                ->pluck('cluster_id')
                ->map(fn ($clusterId): string => (string) $clusterId)
                ->unique()
                ->values();
            $clusterConstraints = collect((array) data_get($locked->params, 'cluster_constraints', []))
                ->filter(fn ($row): bool => is_array($row) && isset($row['cluster_id']))
                ->keyBy(fn (array $row): string => (string) $row['cluster_id']);
            if ($clusterConstraints->isEmpty()) {
                $warnings[] = [
                    'code' => 'cluster_capability_not_verified',
                    'message' => 'Нет актуальной матрицы доступности кластеров Ozon. Перед исполнением будут дополнительно проверены склад и таймслот.',
                ];
            } else {
                foreach ($activeClusterIds as $clusterId) {
                    $constraint = $clusterConstraints->get($clusterId);
                    if (is_array($constraint) && array_key_exists('is_available', $constraint) && ! $constraint['is_available']) {
                        $errors[] = [
                            'code' => 'cluster_unavailable',
                            'message' => sprintf(
                                'Кластер %s сейчас недоступен для приёмки Ozon: %s',
                                $clusterId,
                                (string) ($constraint['reason'] ?? 'нет доступных складов')
                            ),
                            'context' => [
                                'cluster_id' => $clusterId,
                                'cluster_name' => $constraint['cluster_name'] ?? null,
                            ],
                        ];
                    }
                }
            }

            /** @var Integration|null $integration */
            $integration = $locked->integration;
            if ($integration === null) {
                $errors[] = [
                    'code' => 'integration_missing',
                    'message' => 'Интеграция Ozon не найдена.',
                ];
                $health = null;
            } else {
                $health = $this->freshness->inspect($integration);
                foreach ($health['blocking_errors'] as $error) {
                    $errors[] = [
                        'code' => 'facts_' . $error['source'] . '_' . $error['status'],
                        'message' => $error['message'],
                    ];
                }
                foreach ($health['warnings'] as $warning) {
                    $warnings[] = [
                        'code' => 'facts_' . $warning['source'] . '_' . $warning['status'],
                        'message' => $warning['message'],
                    ];
                }
            }

            $planFingerprint = $check['fingerprint'];
            $snapshotHash = $this->snapshotHash($locked);
            $validationFingerprint = $errors === [] ? hash(
                'sha256',
                implode('|', [
                    (string) $planFingerprint,
                    (string) $snapshotHash,
                    (string) ($health['hash'] ?? ''),
                    (string) config('autoplanning.versions.adapter'),
                ])
            ) : null;

            $blockedClusterIds = collect($errors)
                ->where('code', 'cluster_unavailable')
                ->pluck('context.cluster_id')
                ->map(fn ($clusterId): string => (string) $clusterId)
                ->all();
            $groups = $locked->lines()
                ->whereNotNull('cluster_id')
                ->where('qty_rounded', '>', 0)
                ->where('is_excluded', false)
                ->get()
                ->groupBy(fn ($line): string => (string) $line->cluster_id)
                ->map(function ($lines, string $clusterId) use ($blockedClusterIds): array {
                    $first = $lines->first();

                    return [
                        'cluster_id' => $clusterId,
                        'cluster_name' => $first?->cluster_name,
                        'lines_count' => $lines->count(),
                        'total_qty' => (int) $lines->sum('qty_rounded'),
                        'status' => in_array($clusterId, $blockedClusterIds, true)
                            ? 'blocked'
                            : 'ready',
                    ];
                })
                ->values()
                ->all();

            $result = [
                'allowed' => $errors === [],
                'validated_at' => now()->toIso8601String(),
                'errors' => array_values($errors),
                'warnings' => array_values($warnings),
                'plan_fingerprint' => $planFingerprint,
                'snapshot_hash' => $snapshotHash,
                'facts_hash' => $health['hash'] ?? null,
                'validation_fingerprint' => $validationFingerprint,
                'lines_count' => $check['lines_count'],
                'clusters_count' => $check['clusters_count'],
                'total_qty' => $check['total_qty'],
                'groups' => $groups,
                'facts' => $health,
                'adapter_version' => config('autoplanning.versions.adapter'),
            ];

            $locked->update([
                'business_status' => $errors === []
                    ? AutoSupplyPlan::BUSINESS_STATUS_READY_TO_APPROVE
                    : AutoSupplyPlan::BUSINESS_STATUS_VALIDATION_BLOCKED,
                'validation_json' => $result,
                'validated_at' => now(),
                'validation_fingerprint' => $validationFingerprint,
                'approved_at' => null,
                'approved_by' => null,
                'approval_fingerprint' => null,
            ]);

            return $result;
        });
    }

    private function snapshotHash(AutoSupplyPlan $plan): ?string
    {
        if ($plan->snapshot === null) {
            return null;
        }

        return hash('sha256', json_encode([
            'id' => $plan->snapshot->id,
            'captured_at' => $plan->snapshot->captured_at?->toIso8601String(),
            'params' => $plan->snapshot->params_json,
            'freshness' => $plan->snapshot->facts_freshness_json,
            'sources' => $plan->snapshot->planning_sources_json,
            'summary' => $plan->snapshot->summary_json,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
