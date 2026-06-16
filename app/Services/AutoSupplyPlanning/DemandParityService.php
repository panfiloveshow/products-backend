<?php

namespace App\Services\AutoSupplyPlanning;

use App\Domains\Locality\Recommendation\DemandForecaster;
use Illuminate\Support\Facades\DB;

/**
 * Парити-харнесс единого движка спроса (этап 3, read-only).
 * Сравнивает дневной спрос по SKU между каноническим движком (DemandForecaster,
 * postings/EWMA) и legacy-источником (inventory_warehouses, простое среднее).
 * Ничего не меняет — только измеряет расхождение для решения о допуске (см.
 * docs/TZ_UNIFIED_DEMAND_ENGINE.md §5).
 */
class DemandParityService
{
    public function __construct(private DemandForecaster $forecaster) {}

    /**
     * @return array{summary: array<string,mixed>, rows: array<int,array<string,mixed>>}
     */
    public function compare(int $integrationId, int $window = 28): array
    {
        $canonical = $this->canonicalDailyDemand($integrationId, $window);
        $legacy = $this->legacyDailyDemand($integrationId);

        $skus = array_values(array_unique(array_merge(array_keys($canonical), array_keys($legacy))));

        $rows = [];
        $apes = [];
        $both = 0;
        $onlyCanonical = 0;
        $onlyLegacy = 0;

        foreach ($skus as $sku) {
            $c = $canonical[$sku] ?? null;
            $l = $legacy[$sku] ?? null;
            $cv = (float) ($c ?? 0.0);
            $lv = (float) ($l ?? 0.0);

            $ape = $lv > 0 ? round(abs($cv - $lv) / $lv * 100, 2) : null;

            if ($c !== null && $l !== null) {
                $both++;
            } elseif ($c !== null) {
                $onlyCanonical++;
            } else {
                $onlyLegacy++;
            }
            if ($ape !== null) {
                $apes[] = $ape;
            }

            $rows[] = [
                'sku' => $sku,
                'canonical_daily' => round($cv, 3),
                'legacy_daily' => round($lv, 3),
                'abs_pct_error' => $ape,
            ];
        }

        $over25 = count(array_filter($apes, static fn ($a) => $a > 25));

        return [
            'summary' => [
                'window_days' => $window,
                'skus_total' => count($skus),
                'skus_both' => $both,
                'skus_only_canonical' => $onlyCanonical,
                'skus_only_legacy' => $onlyLegacy,
                'comparable' => count($apes),
                'median_ape' => $this->median($apes),
                'pct_over_25' => count($apes) > 0 ? round($over25 / count($apes) * 100, 1) : null,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Канонический дневной спрос per SKU (сумма по кластерам daily_demand).
     *
     * @return array<string,float>
     */
    private function canonicalDailyDemand(int $integrationId, int $window): array
    {
        $out = [];
        foreach ($this->forecaster->forIntegration($integrationId, $window) as $sku => $byCluster) {
            $sum = 0.0;
            foreach ((array) $byCluster as $cluster) {
                $sum += (float) ($cluster['daily_demand'] ?? 0);
            }
            $out[(string) $sku] = round($sum, 3);
        }

        return $out;
    }

    /**
     * Legacy дневной спрос per SKU из inventory_warehouses: sales_28_days/28
     * (фолбэк 30d, затем average_daily_sales) — как LegacySupplyRecommendationService.
     *
     * @return array<string,float>
     */
    private function legacyDailyDemand(int $integrationId): array
    {
        $rows = DB::table('inventory_warehouses')
            ->select(
                'sku',
                DB::raw('MAX(COALESCE(sales_28_days, 0)) as q28'),
                DB::raw('MAX(COALESCE(sales_30_days, 0)) as q30'),
                DB::raw('MAX(COALESCE(average_daily_sales, 0)) as avg_daily'),
            )
            ->where('integration_id', $integrationId)
            ->whereNotNull('sku')
            ->groupBy('sku')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $daily = $row->q28 > 0
                ? $row->q28 / 28
                : ($row->q30 > 0 ? $row->q30 / 30 : (float) $row->avg_daily);
            $out[(string) $row->sku] = round((float) $daily, 3);
        }

        return $out;
    }

    /**
     * @param array<int,float> $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 0
            ? round(($values[$mid - 1] + $values[$mid]) / 2, 2)
            : round($values[$mid], 2);
    }
}
