<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Models\PlanningFactSnapshot;

class PlanningFactSnapshotService
{
    /**
     * @param array<string, mixed> $baseSources
     * @param array<string, mixed> $constraintsSummary
     * @return array<string, mixed>
     */
    public function withConstraintSources(array $baseSources, array $constraintsSummary): array
    {
        $planningSource = is_array($constraintsSummary['planning_source'] ?? null)
            ? $constraintsSummary['planning_source']
            : [];
        $sourceKind = $constraintsSummary['source_kind'] ?? null;
        $sourceStatus = $constraintsSummary['source_status'] ?? null;
        $sourceFile = $constraintsSummary['source_file'] ?? null;
        $parserVersion = $constraintsSummary['parser_version'] ?? null;
        $fileNeedQty = (int) ($constraintsSummary['total_file_marketplace_need_qty'] ?? 0);

        return array_filter(array_merge($baseSources, [
            'constraints' => ! empty($planningSource['used_as_constraints'])
                ? ($sourceKind ?: 'constraint_rules')
                : null,
            'constraint_coefficients' => ! empty($planningSource['used_as_coefficients'])
                ? ($sourceKind ?: 'constraint_file')
                : null,
            'constraints_status' => $sourceStatus,
            'constraint_source_file' => $sourceFile,
            'constraint_parser_version' => $parserVersion,
            'marketplace_needs' => $fileNeedQty > 0
                ? ($sourceKind ?: 'marketplace_need_rules')
                : null,
            'marketplace_needs_status' => $fileNeedQty > 0 ? $sourceStatus : null,
            'marketplace_need_qty' => $fileNeedQty > 0 ? $fileNeedQty : null,
        ], $this->constraintSourceFlags($planningSource)), static fn ($value): bool => $value !== null);
    }

    public function start(AutoSupplyPlan $plan, array $payload = []): PlanningFactSnapshot
    {
        $integration = Integration::withoutGlobalScopes()->find($plan->integration_id);
        $registry = $integration
            ? app(DataFreshnessRegistry::class)->inspect($integration)
            : null;
        $requestedParams = $plan->requested_params_json ?: $this->planParams($plan);
        $effectiveParams = $plan->effective_params_json ?: $this->planParams($plan);

        $snapshot = PlanningFactSnapshot::create([
            'auto_supply_plan_id' => $plan->id,
            'integration_id' => $plan->integration_id,
            'marketplace' => $plan->marketplace,
            'status' => 'building',
            'captured_at' => now(),
            'params_json' => [
                'requested' => $requestedParams,
                'effective' => $effectiveParams,
                'versions' => $this->versions($plan),
            ],
            'facts_freshness_json' => $registry ?? [],
            'constraints_facts_json' => $payload['constraints'] ?? [],
            'summary_json' => [
                'stage' => 'started',
                'facts_hash' => $registry['hash'] ?? null,
                'params_hash' => $this->hash([
                    'requested' => $requestedParams,
                    'effective' => $effectiveParams,
                ]),
            ],
        ]);

        $plan->forceFill([
            'snapshot_id' => $snapshot->id,
            'requested_params_json' => $requestedParams,
            'effective_params_json' => $effectiveParams,
            'calculation_engine' => config('autoplanning.versions.engine', 'ozon-v2'),
            'forecast_version' => config('autoplanning.versions.forecast'),
            'allocation_version' => config('autoplanning.versions.allocation'),
            'adapter_version' => config('autoplanning.versions.adapter'),
            'code_commit' => config('app.commit', env('APP_COMMIT')),
        ])->save();

        return $snapshot;
    }

    /**
     * Зафиксировать параметры, которые расчёт применил фактически.
     *
     * @param array<string, mixed> $effectiveParams
     */
    public function recordEffectiveParams(AutoSupplyPlan $plan, array $effectiveParams): void
    {
        $plan->forceFill(['effective_params_json' => $effectiveParams])->save();

        if (! $plan->snapshot_id) {
            return;
        }

        $snapshot = PlanningFactSnapshot::query()->find($plan->snapshot_id);
        if ($snapshot === null) {
            return;
        }

        $params = is_array($snapshot->params_json) ? $snapshot->params_json : [];
        $params['effective'] = $effectiveParams;

        $snapshot->update([
            'params_json' => $params,
            'summary_json' => array_merge(
                is_array($snapshot->summary_json) ? $snapshot->summary_json : [],
                [
                    'params_hash' => $this->hash([
                        'requested' => $params['requested'] ?? [],
                        'effective' => $effectiveParams,
                    ]),
                ]
            ),
        ]);
    }

    public function complete(AutoSupplyPlan $plan, array $payload): ?PlanningFactSnapshot
    {
        $snapshot = $plan->snapshot_id
            ? PlanningFactSnapshot::query()->find($plan->snapshot_id)
            : null;

        if (! $snapshot) {
            $snapshot = $this->start($plan);
        }

        $integration = Integration::withoutGlobalScopes()->find($plan->integration_id);
        $registry = $integration
            ? app(DataFreshnessRegistry::class)->inspect($integration)
            : [];
        $freshness = array_merge(
            $payload['facts_freshness'] ?? [],
            ['registry' => $registry]
        );
        $previousSummary = is_array($snapshot->summary_json) ? $snapshot->summary_json : [];
        $summary = array_merge($payload['summary'] ?? [], [
            'stage' => 'completed',
            // params_hash пишут start() и recordEffectiveParams(); без переноса
            // он терялся здесь, и снимок нельзя было сверить по параметрам.
            'params_hash' => $previousSummary['params_hash'] ?? null,
            'facts_hash' => $registry['hash'] ?? null,
            'demand_hash' => $this->hash($payload['demand_facts'] ?? []),
            'stock_hash' => $this->hash($payload['stock_facts'] ?? []),
            'supply_hash' => $this->hash($payload['supply_facts'] ?? []),
            'economics_hash' => $this->hash($payload['economics_facts'] ?? []),
            'constraints_hash' => $this->hash($payload['constraints_facts'] ?? []),
        ]);

        $snapshot->update([
            'status' => 'ready',
            'facts_freshness_json' => $freshness,
            'planning_sources_json' => $payload['planning_sources'] ?? [],
            'demand_facts_json' => $payload['demand_facts'] ?? [],
            'stock_facts_json' => $payload['stock_facts'] ?? [],
            'supply_facts_json' => $payload['supply_facts'] ?? [],
            'economics_facts_json' => $payload['economics_facts'] ?? [],
            'constraints_facts_json' => array_merge(
                $snapshot->constraints_facts_json ?? [],
                $payload['constraints_facts'] ?? []
            ),
            'summary_json' => $summary,
        ]);

        return $snapshot;
    }

    public function fail(AutoSupplyPlan $plan, string $message): void
    {
        if (! $plan->snapshot_id) {
            return;
        }

        PlanningFactSnapshot::query()
            ->whereKey($plan->snapshot_id)
            ->update([
                'status' => 'error',
                'summary_json' => ['error' => $message],
            ]);
    }

    /**
     * @param array<string, mixed> $planningSource
     * @return array<string, bool>
     */
    private function constraintSourceFlags(array $planningSource): array
    {
        $flags = [];
        foreach ([
            'used_as_constraints',
            'used_as_marketplace_needs',
            'used_as_coefficients',
            'used_for_quantity_caps',
            'has_unmatched_marketplace_needs',
            'requires_review',
        ] as $key) {
            if (array_key_exists($key, $planningSource)) {
                $flags['constraints_' . $key] = (bool) $planningSource[$key];
            }
        }

        return $flags;
    }

    /**
     * @return array<string, mixed>
     */
    private function planParams(AutoSupplyPlan $plan): array
    {
        return [
            'mode' => $plan->mode,
            'horizon_days' => $plan->horizon_days,
            'min_cover_days' => $plan->min_cover_days,
            'target_cover_days' => $plan->target_cover_days,
            'max_cover_days' => $plan->max_cover_days,
            'safety_stock_days' => $plan->safety_stock_days,
            'turnover_limit_days' => $plan->turnover_limit_days,
            'budget_limit' => $plan->budget_limit,
            'params' => $plan->params,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versions(AutoSupplyPlan $plan): array
    {
        return [
            'engine' => $plan->calculation_engine ?: config('autoplanning.versions.engine'),
            'forecast' => $plan->forecast_version ?: config('autoplanning.versions.forecast'),
            'allocation' => $plan->allocation_version ?: config('autoplanning.versions.allocation'),
            'adapter' => $plan->adapter_version ?: config('autoplanning.versions.adapter'),
            'algorithm' => $plan->algorithm_version,
            'code_commit' => $plan->code_commit ?: config('app.commit', env('APP_COMMIT')),
        ];
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }
}
