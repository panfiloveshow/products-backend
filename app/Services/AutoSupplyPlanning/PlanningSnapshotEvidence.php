<?php

declare(strict_types=1);

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Models\PlanningFactSnapshot;

/** Binds all stored fact matrices to the input version observed by the calculator. */
final class PlanningSnapshotEvidence
{
    public const VERSION = 'planning-snapshot-evidence-1';

    public static function seal(PlanningFactSnapshot $snapshot): array
    {
        $summary = $snapshot->summary_json ?? [];
        unset($summary['evidence_hash']);
        $summary['evidence_version'] = self::VERSION;
        $summary['evidence_hash'] = self::hash($snapshot, $summary);

        return $summary;
    }

    public static function hash(PlanningFactSnapshot $snapshot, ?array $summary = null): string
    {
        $summary ??= $snapshot->summary_json ?? [];
        unset($summary['evidence_hash']);

        return hash('sha256', self::canonical([self::VERSION, (string) $snapshot->id,
            (string) $snapshot->auto_supply_plan_id, (string) $snapshot->integration_id, $snapshot->marketplace,
            $snapshot->captured_at?->utc()->toIso8601String(), $snapshot->params_json, $snapshot->facts_freshness_json,
            $snapshot->planning_sources_json, $snapshot->demand_facts_json, $snapshot->stock_facts_json,
            $snapshot->supply_facts_json, $snapshot->economics_facts_json, $snapshot->constraints_facts_json, $summary]));
    }

    public static function errors(AutoSupplyPlan $plan, ?array $health = null): array
    {
        $snapshot = $plan->snapshot_id ? PlanningFactSnapshot::whereKey($plan->snapshot_id)->first() : null;
        if (! $snapshot || $snapshot->status !== 'ready') {
            return [self::error('facts_snapshot_required', 'У расчёта нет завершённого снимка исходных фактов.')];
        }
        $summary = $snapshot->summary_json ?? [];
        if ((string) $snapshot->auto_supply_plan_id !== (string) $plan->id || (string) $snapshot->integration_id !== (string) $plan->integration_id
            || $snapshot->marketplace !== $plan->marketplace) {
            return [self::error('facts_snapshot_scope_mismatch', 'Снимок фактов принадлежит другому плану или магазину.')];
        }
        if (($summary['evidence_version'] ?? null) !== self::VERSION || ! self::digest($summary['evidence_hash'] ?? null)
            || ! self::digest($summary['facts_hash'] ?? null) || ! self::digest($summary['input_version_started'] ?? null)) {
            return [self::error('facts_snapshot_evidence_missing', 'У расчёта нет подтверждённой версии исходных данных. Выполните расчёт заново.')];
        }
        if (! hash_equals($summary['evidence_hash'], self::hash($snapshot))) {
            return [self::error('facts_snapshot_changed', 'Матрица фактов изменилась после расчёта. Выполните расчёт заново.')];
        }
        if (($summary['inputs_stable_during_calculation'] ?? null) !== true || ! hash_equals($summary['input_version_started'], $summary['facts_hash'])) {
            return [self::error('facts_changed_during_calculation', 'Исходные данные изменились во время расчёта. Нужен новый расчёт.')];
        }
        $required = [];
        foreach ($plan->lines()->where('qty_rounded', '>', 0)->where('is_excluded', false)->get() as $line) {
            $key = self::lineKey($line->getAttributes());
            $required[$key] = ($required[$key] ?? 0) + 1;
        }
        foreach (['demand_facts_json', 'stock_facts_json', 'supply_facts_json', 'economics_facts_json'] as $field) {
            $rows = $snapshot->{$field}['lines'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                return [self::error('facts_snapshot_lines_missing', 'Нет полной матрицы фактов выбранных товаров. Выполните расчёт заново.')];
            }
            $seen = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    return [self::error('facts_snapshot_lines_missing', 'Матрица исходных фактов повреждена.')];
                }
                $key = self::lineKey($row);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
            foreach ($required as $key => $count) {
                if (($seen[$key] ?? 0) < $count) {
                    return [self::error('facts_snapshot_lines_missing', 'Не у всех выбранных строк есть исходные факты. Выполните расчёт заново.')];
                }
            }
        }
        if ($health !== null && (! self::digest($health['hash'] ?? null) || ! hash_equals($summary['facts_hash'], $health['hash']))) {
            return [self::error('facts_changed_since_calculation', 'Исходные данные изменились после расчёта. Проверка не пересчитывает количества; выполните новый расчёт.')];
        }

        return [];
    }

    private static function lineKey(array $row): string
    {
        return self::canonical(array_map(fn ($key) => isset($row[$key]) ? (string) $row[$key] : null,
            ['sku', 'offer_id', 'cluster_id', 'warehouse_id']));
    }

    public static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function error(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    private static function canonical(mixed $value): string
    {
        $normalize = function ($v) use (&$normalize) {
            if (! is_array($v)) {
                return $v;
            } if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($normalize, $v);
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
