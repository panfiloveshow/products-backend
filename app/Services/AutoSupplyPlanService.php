<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Сервис расчёта автопланирования поставок.
 *
 * Вся бизнес-логика EWMA-прогноза, caps, rounding, simulation, risk, data quality
 * вынесена из Job сюда для тестируемости и переиспользования.
 */
class AutoSupplyPlanService
{
    public const EPS = 0.1;
    public const MIN_SALES_DAYS = 14;
    public const LEAD_TIME_DEFAULT = 7;

    // ─── 3.1 EWMA Forecast ───────────────────────────────────────────

    /**
     * Рассчитать дневной спрос (EWMA).
     *
     * @return array{daily_demand: float, needs_manual_review: bool}
     */
    public function calculateDailyDemand(
        float $alpha,
        float $sales7,
        float $sales14,
        float $sales30
    ): array {
        $shortAvg = $sales7 > 0 ? $sales7 / 7 : 0;
        $longAvg  = $sales30 > 0 ? $sales30 / 30 : 0;

        if ($sales30 <= 0 && $sales14 <= 0) {
            return ['daily_demand' => 0.0, 'needs_manual_review' => true];
        }

        if ($shortAvg > 0 && $longAvg > 0) {
            $dailyDemand = $alpha * $shortAvg + (1 - $alpha) * $longAvg;
        } elseif ($shortAvg > 0) {
            $dailyDemand = $shortAvg;
        } elseif ($longAvg > 0) {
            $dailyDemand = $longAvg;
        } else {
            $dailyDemand = 0.0;
        }

        return ['daily_demand' => $dailyDemand, 'needs_manual_review' => false];
    }

    // ─── 3.2 Базовые формулы ─────────────────────────────────────────

    /**
     * Рассчитать needed до применения caps.
     */
    public function calculateNeededBeforeCaps(
        float $dailyDemand,
        int   $targetCoverDays,
        int   $currentStock,
        int   $inTransit
    ): array {
        $targetStock     = $dailyDemand * $targetCoverDays;
        $safetyStock     = 0.0; // вычисляется отдельно при необходимости
        $neededBeforeCaps = max(0, $targetStock - ($currentStock + $inTransit));

        return [
            'target_stock'      => $targetStock,
            'needed_before_caps' => $neededBeforeCaps,
        ];
    }

    // ─── 3.3 Max cover cap ───────────────────────────────────────────

    /**
     * Применить max_cover_days cap.
     *
     * @return array{needed: float, caps_applied: string[]}
     */
    public function applyMaxCoverCap(
        float $neededBeforeCaps,
        float $dailyDemand,
        int   $maxCoverDays,
        int   $currentStock,
        int   $inTransit
    ): array {
        $capStock  = $dailyDemand * $maxCoverDays;
        $capNeeded = max(0, $capStock - ($currentStock + $inTransit));
        $needed    = min($neededBeforeCaps, $capNeeded);

        $capsApplied = [];
        if ($needed !== $neededBeforeCaps) {
            $capsApplied[] = 'max_cover_days';
        }

        return [
            'needed'       => $needed,
            'cap_stock'    => $capStock,
            'cap_needed'   => $capNeeded,
            'caps_applied' => $capsApplied,
        ];
    }

    // ─── 3.4 Turnover limit ──────────────────────────────────────────

    /**
     * Применить turnover_limit_days cap.
     *
     * @return array{needed: float, caps_applied: string[]}
     */
    public function applyTurnoverLimit(
        float  $needed,
        float  $dailyDemand,
        ?int   $turnoverLimitDays,
        int    $currentStock,
        int    $inTransit,
        array  $capsApplied = []
    ): array {
        if ($turnoverLimitDays !== null && $dailyDemand > self::EPS) {
            $turnoverAfter = ($currentStock + $inTransit + $needed) / max($dailyDemand, self::EPS);
            if ($turnoverAfter > $turnoverLimitDays) {
                $maxByTurnover = max(0, $dailyDemand * $turnoverLimitDays - ($currentStock + $inTransit));
                $needed        = min($needed, $maxByTurnover);
                $capsApplied[] = 'turnover_limit';
            }
        }

        return [
            'needed'       => $needed,
            'caps_applied' => $capsApplied,
        ];
    }

    // ─── 3.6 Округление qty ──────────────────────────────────────────

    /**
     * Округлить до pack_multiple (вверх).
     */
    public function roundToPackMultiple(float $needed, int $packMultiple = 1): int
    {
        $packMultiple = max(1, $packMultiple);
        $qty = (int) (ceil($needed / $packMultiple) * $packMultiple);
        return max(0, $qty);
    }

    // ─── 3.7 Симуляция остатков ──────────────────────────────────────

    /**
     * Построить симуляцию остатков по дням.
     * in_transit приходит через LEAD_TIME_DEFAULT,
     * рекомендованная поставка — через LEAD_TIME_DEFAULT + 3.
     */
    public function buildSimulation(
        int   $currentStock,
        int   $inTransit,
        float $dailyDemand,
        int   $supplyQty,
        int   $horizonDays
    ): array {
        $simulation    = [];
        $stock         = (float) $currentStock;
        $transitArrived = false;
        $supplyArrived  = false;

        for ($day = 1; $day <= $horizonDays; $day++) {
            if (!$transitArrived && $day === self::LEAD_TIME_DEFAULT && $inTransit > 0) {
                $stock += $inTransit;
                $transitArrived = true;
            }

            if (!$supplyArrived && $day === self::LEAD_TIME_DEFAULT + 3 && $supplyQty > 0) {
                $stock += $supplyQty;
                $supplyArrived = true;
            }

            $stock = max(0, $stock - $dailyDemand);

            $simulation[] = [
                'day'             => $day,
                'stock'           => round($stock, 1),
                'sales_forecast'  => round($dailyDemand, 2),
                'transit_arrived' => (!$transitArrived && $day === self::LEAD_TIME_DEFAULT) ? $inTransit : 0,
                'supply_arrived'  => (!$supplyArrived && $day === self::LEAD_TIME_DEFAULT + 3) ? $supplyQty : 0,
            ];
        }

        return $simulation;
    }

    /**
     * Найти дату OOS (первый день с нулевым остатком).
     */
    public function findOosDate(array $simulation): ?string
    {
        $today = Carbon::today();
        foreach ($simulation as $point) {
            if ($point['stock'] <= 0) {
                return $today->copy()->addDays($point['day'])->toDateString();
            }
        }
        return null;
    }

    /**
     * Найти минимальный остаток в симуляции.
     */
    public function findMinStock(array $simulation): float
    {
        $min = PHP_FLOAT_MAX;
        foreach ($simulation as $point) {
            if ($point['stock'] < $min) {
                $min = $point['stock'];
            }
        }
        return $min === PHP_FLOAT_MAX ? 0 : $min;
    }

    // ─── 3.7 Risk level ──────────────────────────────────────────────

    /**
     * Определить уровень риска.
     */
    public function determineRiskLevel(
        ?string $oosDate,
        float   $coverBefore,
        int     $minCoverDays
    ): string {
        $today = Carbon::today();

        if ($oosDate !== null && Carbon::parse($oosDate)->lte($today->copy()->addDays(7))) {
            return 'high';
        }

        if ($coverBefore < $minCoverDays) {
            return 'med';
        }

        return 'low';
    }

    // ─── 3.8 Missing sources ─────────────────────────────────────────

    /**
     * Определить недостающие источники данных.
     */
    public function detectMissingSources($wh, $product, string $marketplace): array
    {
        $missing = [];
        if (($wh->sales_30_days ?? 0) <= 0) $missing[] = 'sales_daily';
        if (($wh->in_transit ?? 0) <= 0 && ($wh->quantity ?? 0) <= 0) $missing[] = 'stocks';
        if ($marketplace === 'wildberries' && empty($product?->barcode)) $missing[] = 'wb_barcode_map';
        return $missing;
    }

    // ─── 4. Data quality ─────────────────────────────────────────────

    /**
     * Рассчитать data quality score (0..100).
     */
    public function calculateDataQuality(
        int    $total,
        int    $stocks,
        int    $sales,
        int    $transit,
        int    $destination,
        int    $barcode,
        string $marketplace
    ): array {
        if ($total === 0) return $this->emptyQualityJson();

        $stocksScore  = round(($stocks / $total) * 30, 1);
        $salesScore   = round(($sales / $total) * 25, 1);
        $transitScore = round(($transit / $total) * 20, 1);
        $destScore    = round(($destination / $total) * 15, 1);
        $barcodeScore = $marketplace === 'wildberries'
            ? round(($barcode / $total) * 10, 1)
            : 10.0;

        $totalScore = round($stocksScore + $salesScore + $transitScore + $destScore + $barcodeScore, 2);

        return [
            'total'     => min(100, $totalScore),
            'breakdown' => [
                'stocks_coverage'         => $stocksScore,
                'sales_history'           => $salesScore,
                'in_transit_availability' => $transitScore,
                'destination_granularity' => $destScore,
                'wb_barcode_availability' => $barcodeScore,
            ],
            'skus_analyzed' => $total,
        ];
    }

    /**
     * Пустой JSON для data quality.
     */
    public function emptyQualityJson(): array
    {
        return [
            'total'     => 0,
            'breakdown' => [
                'stocks_coverage'         => 0,
                'sales_history'           => 0,
                'in_transit_availability' => 0,
                'destination_granularity' => 0,
                'wb_barcode_availability' => 0,
            ],
            'skus_analyzed' => 0,
        ];
    }
}
