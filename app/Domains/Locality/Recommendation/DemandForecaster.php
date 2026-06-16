<?php

namespace App\Domains\Locality\Recommendation;

use Illuminate\Support\Facades\DB;

/**
 * EWMA (exponentially-weighted moving average) дневного спроса per (sku, destination_cluster).
 * α из config('locality.forecast.ewma_alpha', 0.3).
 *
 * Выход per SKU: массив keyed by cluster_name:
 * {
 *   cluster_name: string,
 *   daily_demand: float,
 *   sales_28d: int,
 *   sales_7d: int,
 *   volatility: float,
 *   days_with_sales: int,
 *   source: 'ewma'|'cold_start'
 * }
 */
class DemandForecaster
{
    public function forIntegration(int $integrationId, int $windowDays = 28): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT
                    pi.offer_id AS sku,
                    p.financial_data->>'cluster_to' AS cluster_name,
                    DATE(p.created_at) AS day,
                    COUNT(*) AS cnt
                FROM postings p
                JOIN posting_items pi ON pi.posting_id = p.id
                WHERE p.integration_id = ?::text
                    AND p.created_at > now() - make_interval(days => ?)
                    AND p.financial_data->>'cluster_to' IS NOT NULL
                GROUP BY pi.offer_id, cluster_name, day
                ORDER BY pi.offer_id, cluster_name, day
            SQL,
            [$integrationId, $windowDays]
        );

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string) $row->sku][(string) $row->cluster_name][(string) $row->day] = (int) $row->cnt;
        }

        $alpha = (float) config('locality.forecast.ewma_alpha', 0.3);
        $coldMin14 = (int) config('locality.forecast.cold_start_min_sales_14d', 3);
        $coldMin28 = (int) config('locality.forecast.cold_start_min_sales_28d', 5);

        // Якорь окна — конец периода (сегодня): по нему раскладываем продажи по
        // реальным дням, чтобы EWMA учитывал дни без продаж, включая хвостовые нули.
        $windowEndDate = now()->toDateString();

        $result = [];
        foreach ($byKey as $sku => $byCluster) {
            foreach ($byCluster as $clusterName => $daily) {
                $sales28d = array_sum($daily);
                $sales7d = 0;
                foreach ($daily as $day => $count) {
                    if (strtotime($day) >= strtotime('-7 days')) {
                        $sales7d += $count;
                    }
                }
                $daysWithSales = count($daily);

                [$dailyDemand, $volatility, $source] = $this->projectDailyDemand(
                    $daily,
                    $windowDays,
                    $alpha,
                    $sales28d,
                    $sales7d,
                    $windowEndDate,
                    $coldMin14,
                    $coldMin28
                );

                $result[$sku][$clusterName] = [
                    'cluster_name' => $clusterName,
                    'daily_demand' => round($dailyDemand, 3),
                    'sales_28d' => $sales28d,
                    'sales_7d' => $sales7d,
                    'volatility' => round($volatility, 4),
                    'days_with_sales' => $daysWithSales,
                    'source' => $source,
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string,int> $dailyCounts day => count (только дни С ПРОДАЖАМИ)
     * @return array{0:float, 1:float, 2:string}
     */
    private function projectDailyDemand(
        array $dailyCounts,
        int $windowDays,
        float $alpha,
        int $sales28d,
        int $sales7d,
        string $windowEndDate,
        int $coldMin14,
        int $coldMin28
    ): array {
        if ($sales28d < $coldMin28) {
            $daily = $sales7d > 0 ? $sales7d / 7 : $sales28d / max(1, $windowDays);
            return [$daily, 0.0, 'cold_start'];
        }

        // Нормализуем: заполняем все дни окна (с нулями для дней без продаж),
        // чтобы daily_demand отражал СРЕДНЕЕ, а не активность последнего "всплеска" продаж.
        // ВАЖНО: раскладываем по РЕАЛЬНЫМ смещениям дат относительно конца окна,
        // а не подряд от индекса 0 — иначе EWMA схлопывает дни без продаж и завышает
        // вес старых продаж. Самый свежий день → конец массива (макс. вес EWMA).
        $filled = array_fill(0, $windowDays, 0);
        $anchorTs = strtotime($windowEndDate);
        foreach ($dailyCounts as $day => $count) {
            $daysAgo = (int) round(($anchorTs - strtotime((string) $day)) / 86400);
            $offset = $windowDays - 1 - $daysAgo;
            $offset = max(0, min($windowDays - 1, $offset));
            $filled[$offset] += (int) $count;
        }

        // Для малых объёмов (<50 заказов/период) EWMA шумит → используем простое среднее.
        if ($sales28d < 50) {
            $mean = $sales28d / max(1, $windowDays);
            $variance = 0.0;
            foreach ($filled as $v) {
                $variance += ($v - $mean) ** 2;
            }
            $variance /= max(1, count($filled));
            $cv = $mean > 0 ? sqrt($variance) / $mean : 0.0;
            return [$mean, $cv, 'simple_avg'];
        }

        // Полный EWMA для объёмов, где это имеет смысл.
        $ewma = null;
        foreach ($filled as $count) {
            $ewma = $ewma === null ? (float) $count : $alpha * $count + (1 - $alpha) * $ewma;
        }

        $mean = array_sum($filled) / max(1, count($filled));
        $variance = 0.0;
        foreach ($filled as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance /= max(1, count($filled));
        $cv = $mean > 0 ? sqrt($variance) / $mean : 0.0;

        return [(float) $ewma, $cv, 'ewma'];
    }
}
