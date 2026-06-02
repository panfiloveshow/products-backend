<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
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
        $snapshot = PlanningFactSnapshot::create([
            'auto_supply_plan_id' => $plan->id,
            'integration_id' => $plan->integration_id,
            'marketplace' => $plan->marketplace,
            'status' => 'building',
            'captured_at' => now(),
            'params_json' => [
                'mode' => $plan->mode,
                'horizon_days' => $plan->horizon_days,
                'min_cover_days' => $plan->min_cover_days,
                'target_cover_days' => $plan->target_cover_days,
                'max_cover_days' => $plan->max_cover_days,
                'safety_stock_days' => $plan->safety_stock_days,
                'turnover_limit_days' => $plan->turnover_limit_days,
                'budget_limit' => $plan->budget_limit,
                'params' => $plan->params,
            ],
            'constraints_facts_json' => $payload['constraints'] ?? [],
            'summary_json' => ['stage' => 'started'],
        ]);

        $plan->forceFill(['snapshot_id' => $snapshot->id])->save();

        return $snapshot;
    }

    public function complete(AutoSupplyPlan $plan, array $payload): ?PlanningFactSnapshot
    {
        $snapshot = $plan->snapshot_id
            ? PlanningFactSnapshot::query()->find($plan->snapshot_id)
            : null;

        if (! $snapshot) {
            $snapshot = $this->start($plan);
        }

        $snapshot->update([
            'status' => 'ready',
            'facts_freshness_json' => $payload['facts_freshness'] ?? [],
            'planning_sources_json' => $payload['planning_sources'] ?? [],
            'demand_facts_json' => $payload['demand_facts'] ?? [],
            'stock_facts_json' => $payload['stock_facts'] ?? [],
            'supply_facts_json' => $payload['supply_facts'] ?? [],
            'economics_facts_json' => $payload['economics_facts'] ?? [],
            'constraints_facts_json' => array_merge(
                $snapshot->constraints_facts_json ?? [],
                $payload['constraints_facts'] ?? []
            ),
            'summary_json' => $payload['summary'] ?? [],
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
}
