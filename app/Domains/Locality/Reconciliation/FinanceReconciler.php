<?php

namespace App\Domains\Locality\Reconciliation;

use App\Domains\Locality\Ingestion\FinanceTransactionSyncer;
use App\Models\Integration;
use App\Models\LocalityReconciliationLog;
use App\Models\OzonFinanceTransaction;
use App\Models\OzonOrderUnitEconomics;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Сравнивает expected (из ozon_order_unit_economics) vs actual (ozon_finance_transactions).
 * Пишет строку в locality_reconciliation_log.
 */
class FinanceReconciler
{
    public function __construct(
        private readonly FinanceTransactionSyncer $syncer,
    ) {
    }

    public function run(Integration $integration, Carbon $from, Carbon $to): LocalityReconciliationLog
    {
        // Ingest actuals на период (idempotent).
        $this->syncer->syncForIntegration($integration, $from, $to);

        $expected = $this->expectedTotals((int) $integration->id, $from, $to);
        $actual = $this->actualTotals((int) $integration->id, $from, $to);
        $matching = $this->matchPostings((int) $integration->id, $from, $to);

        $baseDiff = round($actual['base_logistics'] - $expected['base_logistics'], 2);
        $markupDiff = round($actual['non_local_markup'] - $expected['non_local_markup'], 2);

        $basePct = $expected['base_logistics'] != 0.0
            ? round(abs($baseDiff) / $expected['base_logistics'] * 100, 2)
            : null;
        $markupPct = $expected['non_local_markup'] != 0.0
            ? round(abs($markupDiff) / $expected['non_local_markup'] * 100, 2)
            : null;

        $matchTol = (float) config('locality.reconciliation.match_tolerance_percent', 2.0);
        $driftTol = (float) config('locality.reconciliation.drift_tolerance_percent', 10.0);
        $worstPct = max((float) ($basePct ?? 0), (float) ($markupPct ?? 0));

        $verdict = match (true) {
            $worstPct <= $matchTol => LocalityReconciliationLog::VERDICT_MATCH,
            $worstPct <= $driftTol => LocalityReconciliationLog::VERDICT_DRIFT,
            default => LocalityReconciliationLog::VERDICT_MISMATCH,
        };

        $log = LocalityReconciliationLog::query()->create([
            'integration_id' => $integration->id,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'run_at' => now(),
            'source' => 'finance_transaction_list',
            'expected_base_logistics' => round($expected['base_logistics'], 2),
            'expected_non_local_markup' => round($expected['non_local_markup'], 2),
            'actual_base_logistics' => round($actual['base_logistics'], 2),
            'actual_non_local_markup' => round($actual['non_local_markup'], 2),
            'base_logistics_diff' => $baseDiff,
            'markup_diff' => $markupDiff,
            'base_logistics_diff_percent' => $basePct,
            'markup_diff_percent' => $markupPct,
            'verdict' => $verdict,
            'operations_count' => $actual['operations_count'],
            'postings_matched' => $matching['matched'],
            'postings_missing' => $matching['missing'],
            'details' => [
                'actual_by_op_type' => $actual['by_op_type'],
                'expected_orders' => $expected['orders_count'],
                'tolerance_used' => [
                    'match_percent' => $matchTol,
                    'drift_percent' => $driftTol,
                ],
            ],
        ]);

        if ($verdict === LocalityReconciliationLog::VERDICT_MISMATCH) {
            Log::channel('locality')->warning('FinanceReconciler mismatch', [
                'integration_id' => $integration->id,
                'base_diff_percent' => $basePct,
                'markup_diff_percent' => $markupPct,
            ]);
        }

        return $log;
    }

    /** @return array{base_logistics:float, non_local_markup:float, orders_count:int} */
    private function expectedTotals(int $integrationId, Carbon $from, Carbon $to): array
    {
        $row = OzonOrderUnitEconomics::query()
            ->where('integration_id', $integrationId)
            ->whereBetween('order_date', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->selectRaw('SUM(base_logistics_tariff) AS base, SUM(CASE WHEN markup_applied THEN non_local_markup_amount ELSE 0 END) AS markup, COUNT(*) AS c')
            ->first();

        return [
            'base_logistics' => (float) ($row->base ?? 0),
            'non_local_markup' => (float) ($row->markup ?? 0),
            'orders_count' => (int) ($row->c ?? 0),
        ];
    }

    /** @return array{base_logistics:float, non_local_markup:float, operations_count:int, by_op_type:array<string,float>} */
    private function actualTotals(int $integrationId, Carbon $from, Carbon $to): array
    {
        $rows = OzonFinanceTransaction::query()
            ->where('integration_id', $integrationId)
            ->whereBetween('operation_date', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->whereIn('operation_type', OzonFinanceTransaction::LOGISTICS_OPERATION_TYPES)
            ->get(['operation_type', 'amount']);

        $byOpType = [];
        $totalBase = 0.0;
        foreach ($rows as $row) {
            $type = (string) $row->operation_type;
            $amount = abs((float) $row->amount);
            $byOpType[$type] = round(($byOpType[$type] ?? 0) + $amount, 2);
            $totalBase += $amount;
        }

        return [
            'base_logistics' => $totalBase,
            'non_local_markup' => 0.0, // Ozon не выделяет markup отдельно; косвенно валидируется через base
            'operations_count' => $rows->count(),
            'by_op_type' => $byOpType,
        ];
    }

    /** @return array{matched:int, missing:int} */
    private function matchPostings(int $integrationId, Carbon $from, Carbon $to): array
    {
        $expectedPostings = OzonOrderUnitEconomics::query()
            ->where('integration_id', $integrationId)
            ->whereBetween('order_date', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->whereNotNull('posting_number')
            ->distinct()
            ->pluck('posting_number')
            ->all();

        if (empty($expectedPostings)) {
            return ['matched' => 0, 'missing' => 0];
        }

        $matched = OzonFinanceTransaction::query()
            ->where('integration_id', $integrationId)
            ->whereIn('posting_number', $expectedPostings)
            ->distinct()
            ->count('posting_number');

        return [
            'matched' => (int) $matched,
            'missing' => max(0, count($expectedPostings) - (int) $matched),
        ];
    }
}
