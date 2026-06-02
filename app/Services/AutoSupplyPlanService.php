<?php

namespace App\Services;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\SalesApi;
use App\Models\Integration;
use App\Models\OzonWarehouseCluster;
use App\Models\SupplySettings;
use App\Models\UnitEconomics;
use Carbon\Carbon;

/**
 * Сервис расчёта автопланирования поставок.
 *
 * Вся бизнес-логика EWMA-прогноза, caps, rounding, simulation, risk, data quality
 * вынесена из Job сюда для тестируемости и переиспользования.
 *
 * v2: ABC-приоритет, динамический safety stock, % выкупа, корректировка на тренд,
 *     effective_daily_sales, real_avg_daily_sales, Ozon DeliveryAnalytics.
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
            $transitToday = 0;
            $supplyToday  = 0;

            if (!$transitArrived && $day === self::LEAD_TIME_DEFAULT && $inTransit > 0) {
                $stock += $inTransit;
                $transitToday = $inTransit;
                $transitArrived = true;
            }

            if (!$supplyArrived && $day === self::LEAD_TIME_DEFAULT + 3 && $supplyQty > 0) {
                $stock += $supplyQty;
                $supplyToday = $supplyQty;
                $supplyArrived = true;
            }

            $stock = max(0, $stock - $dailyDemand);

            $simulation[] = [
                'day'             => $day,
                'stock'           => round($stock, 1),
                'sales_forecast'  => round($dailyDemand, 2),
                'transit_arrived' => $transitToday,
                'supply_arrived'  => $supplyToday,
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
    public function detectMissingSources($wh, $product, string $marketplace, $ue = null, bool $hasOzonReport = false, bool $hasOzonPostingDemand = false): array
    {
        $missing = [];
        if (
            ($wh->sales_30_days ?? 0) <= 0
            && ($wh->sales_14_days ?? 0) <= 0
            && ! ($marketplace === 'ozon' && $hasOzonPostingDemand)
        ) {
            $missing[] = 'sales_history';
        }
        if ($marketplace === 'wildberries' && empty($product?->barcode)) $missing[] = 'wb_barcode_map';
        if (($ue?->cost_price ?? $wh->cost_price ?? 0) <= 0) $missing[] = 'cost_price';
        if (
            $marketplace === 'ozon'
            && ! $hasOzonReport
            && ! $hasOzonPostingDemand
            && ($wh->real_avg_daily_sales ?? 0) <= 0
        ) {
            $missing[] = 'ozon_posting_demand';
        }
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

    // ═══════════════════════════════════════════════════════════════════
    // v2: Улучшенный расчёт автопополнения
    // ═══════════════════════════════════════════════════════════════════

    // ─── ABC-приоритет ────────────────────────────────────────────────

    /**
     * Определить ABC-приоритет товара по выручке за 30 дней.
     *
     * A — топ-товары (выручка ≥ 100к), target_days = 21
     * B — средние (выручка ≥ 30к), target_days = 14
     * C — остальные, target_days = 7
     */
    public function calculateAbcPriority(float $revenue30d): string
    {
        if ($revenue30d >= 100000) return 'A';
        if ($revenue30d >= 30000) return 'B';
        return 'C';
    }

    /**
     * Получить target_cover_days по ABC-приоритету из SupplySettings.
     * Если настроек нет — используем дефолты из плана.
     */
    public function getTargetDaysByAbc(string $abcPriority, ?SupplySettings $settings, int $planDefault): int
    {
        if ($settings) {
            return $settings->getTargetDays($abcPriority);
        }

        return match ($abcPriority) {
            'A' => max($planDefault, 21),
            'B' => $planDefault,
            'C' => min($planDefault, 14),
            default => $planDefault,
        };
    }

    // ─── Улучшенный прогноз спроса ───────────────────────────────────

    /**
     * Выбрать лучший источник дневного спроса (приоритет):
     *   1) real_avg_daily_sales (из отчёта заказов Ozon — по конкретному складу)
     *   2) effective_daily_sales (с учётом OOS-дней)
     *   3) EWMA (стандартный расчёт)
     *
     * @return array{daily_demand: float, source: string, needs_manual_review: bool}
     */
    public function calculateDailyDemandV2(
        float  $alpha,
        float  $sales7,
        float  $sales14,
        float  $sales30,
        float  $realAvgDailySales = 0,
        float  $effectiveDailySales = 0,
        int    $daysInStock30 = 30,
        float  $redemptionRate = 100,
        string $salesTrend = 'stable',
        float  $salesTrendPercent = 0,
        float  $avgDailySalesApi = 0,
        string $realAvgSource = 'ozon_order_report'
    ): array {
        $source = 'ewma';
        $needsManualReview = false;

        // Приоритет 1: real_avg_daily_sales из отчёта заказов (по складам)
        if ($realAvgDailySales > 0) {
            $baseDemand = $realAvgDailySales;
            $source = $realAvgSource;
        }
        // Приоритет 2: effective_daily_sales (с учётом OOS-дней)
        elseif ($effectiveDailySales > 0 && $daysInStock30 > 0 && $daysInStock30 < 28) {
            $baseDemand = $effectiveDailySales;
            $source = 'effective_oos_adjusted';
        }
        // Приоритет 3: EWMA (если есть хотя бы 14д или 30д продаж)
        elseif ($sales30 > 0 || $sales14 > 0) {
            $ewma = $this->calculateDailyDemand($alpha, $sales7, $sales14, $sales30);
            $baseDemand = $ewma['daily_demand'];
            $needsManualReview = $ewma['needs_manual_review'];

            if ($sales30 <= 0 && $sales7 > 0) {
                $source = 'fallback_short';
            } elseif ($sales7 <= 0 && $sales30 > 0) {
                $source = 'fallback_long';
            }
        }
        // Приоритет 4: average_daily_sales из API маркетплейса (когда нет 14д/30д продаж)
        elseif ($avgDailySalesApi > 0) {
            $baseDemand = $avgDailySalesApi;
            $source = 'marketplace_api';
            $needsManualReview = true;
        }
        // Приоритет 5: только 7д продажи (нет 14д и 30д)
        elseif ($sales7 > 0) {
            $baseDemand = $sales7 / 7;
            $source = 'fallback_short';
            $needsManualReview = true;
        }
        // Нет данных вообще
        else {
            $baseDemand = 0.0;
            $source = 'no_data';
            $needsManualReview = true;
        }

        // Корректировка на % выкупа (только для Ozon FBO)
        if ($redemptionRate > 0 && $redemptionRate < 100) {
            $baseDemand = $baseDemand * ($redemptionRate / 100);
        }

        // Корректировка на тренд (±20% макс). Для фактического спроса из
        // postings/отчёта не усиливаем положительный тренд: промо-всплеск уже
        // находится внутри факта и не должен получать второй аплифт.
        $trustedObservedSource = in_array($source, ['posting_fbo_v3', 'ozon_order_report'], true);
        if (! $trustedObservedSource || $salesTrend !== 'growing') {
            $baseDemand = $this->adjustDemandByTrend($baseDemand, $salesTrend, $salesTrendPercent);
        }

        return [
            'daily_demand' => max(0, $baseDemand),
            'source' => $source,
            'needs_manual_review' => $needsManualReview,
        ];
    }

    /**
     * Скорректировать спрос по тренду продаж.
     * Рост → увеличиваем прогноз (макс +20%).
     * Падение → уменьшаем (макс -15%).
     */
    public function adjustDemandByTrend(float $demand, string $trend, float $trendPercent): float
    {
        if ($demand <= 0 || $trend === 'stable') {
            return $demand;
        }

        // Ограничиваем корректировку: -15% до +20%
        $adjustment = max(-15, min(20, $trendPercent)) / 100;

        // Применяем 50% от тренда (сглаживание)
        return $demand * (1 + $adjustment * 0.5);
    }

    // ─── Динамический safety stock ───────────────────────────────────

    /**
     * Рассчитать волатильность продаж (коэффициент вариации).
     */
    public function calculateVolatility(float $avg7d, float $avg14d, float $avg30d): float
    {
        $values = array_filter([$avg7d, $avg14d, $avg30d], fn($v) => $v > 0);

        if (count($values) < 2) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        if ($mean <= 0) {
            return 0;
        }

        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values);
        return round(sqrt($variance) / $mean, 4);
    }

    /**
     * Рассчитать динамический страховой запас.
     *
     * Формула: SS = avg_sales × lead_time × (1 + volatility × k)
     * где k = 1.5 (коэффициент запаса)
     *
     * Минимум: safetyStockDays × avg_sales (из настроек плана)
     */
    public function calculateDynamicSafetyStock(
        float $dailyDemand,
        float $volatility,
        int   $leadTimeDays,
        int   $minSafetyDays = 3
    ): float {
        $safetyCoef = 1.5;
        $dynamicSafety = $dailyDemand * $leadTimeDays * (1 + $volatility * $safetyCoef);
        $minSafety = $dailyDemand * $minSafetyDays;

        return max($dynamicSafety, $minSafety);
    }

    /**
     * Превратить Ozon postings в устойчивый спрос, не принимая промо-всплеск
     * за новую норму. Возвращает и объяснение, чтобы UI мог честно показать
     * "почему количество ограничено".
     *
     * @return array{daily_demand:float, suspected_spike:bool, confidence_level:string, confidence_reasons:string[], period_avg:float, recent_7_avg:float, recent_14_avg:float, recent_30_avg:float, winsorized_avg:float, peak_day_units:int, peak_share:float, active_days:int, guardrail_cap_daily_demand:float|null, capped_external_daily_demand:bool}
     */
    public function shapeOzonPostingDemand(array $postingData, float $localAvgDaily = 0.0, ?float $ozonAds = null): array
    {
        $periodAvg = max(0.0, (float) ($postingData['avg_daily_sales'] ?? 0));
        $recent7 = max(0.0, ((float) ($postingData['sales_7_days'] ?? 0)) / 7);
        $recent14 = max(0.0, ((float) ($postingData['sales_14_days'] ?? 0)) / 14);
        $recent30 = max(0.0, ((float) ($postingData['sales_30_days'] ?? 0)) / 30);
        $winsorized = max(0.0, (float) ($postingData['winsorized_avg_daily_sales'] ?? $periodAvg));
        $orderedUnits = (int) ($postingData['ordered_units_total'] ?? 0);
        $peakDayUnits = (int) ($postingData['peak_day_units'] ?? 0);
        $peakShare = (float) ($postingData['peak_share'] ?? 0);
        $activeDays = (int) ($postingData['active_days'] ?? 0);
        $medianNonZero = (float) ($postingData['median_nonzero_daily_units'] ?? 0);
        $ozonAds = $ozonAds !== null ? max(0.0, (float) $ozonAds) : 0.0;

        $reasons = [];
        $baselineCandidates = array_values(array_filter([
            $winsorized,
            $recent30,
            $recent14 > 0 ? $recent14 * 0.85 : 0,
            $localAvgDaily > 0 ? $localAvgDaily : 0,
            $ozonAds,
        ], fn (float $value) => $value > 0));

        $baseline = $baselineCandidates !== []
            ? array_sum($baselineCandidates) / count($baselineCandidates)
            : $periodAvg;

        $suspectedSpike = false;
        if ($orderedUnits >= 10 && $peakShare >= 0.25) {
            $suspectedSpike = true;
            $reasons[] = 'promo_spike_peak_share';
        }
        if ($medianNonZero > 0 && $peakDayUnits >= max(10, $medianNonZero * 4)) {
            $suspectedSpike = true;
            $reasons[] = 'promo_spike_peak_vs_median';
        }
        if ($periodAvg > 0 && $recent7 > 0 && $recent7 > $periodAvg * 2.5) {
            $suspectedSpike = true;
            $reasons[] = 'recent_spike_vs_period';
        }
        if ($periodAvg > 0 && $recent7 < $periodAvg * 0.35 && $peakShare >= 0.15) {
            $suspectedSpike = true;
            $reasons[] = 'post_promo_cooldown';
        }

        $demand = $periodAvg;
        if ($winsorized > 0) {
            $demand = min($demand, max($winsorized, $recent7));
        }

        $guardrailCap = null;
        $cappedExternalDemand = false;
        if ($suspectedSpike) {
            $shapeBaselineCandidates = array_values(array_filter([
                $winsorized,
                $recent30,
                $recent14 > 0 ? $recent14 * 0.85 : 0,
            ], fn (float $value) => $value > 0));
            $shapeBaseline = $shapeBaselineCandidates !== []
                ? array_sum($shapeBaselineCandidates) / count($shapeBaselineCandidates)
                : $baseline;
            $cooldownCap = $recent7 > 0 ? max($recent7 * 1.8, $shapeBaseline * 0.35) : $shapeBaseline * 0.5;

            // Если видим распродажный всплеск, внешние агрегаты (старый local avg / ADS)
            // не могут быть нижней границей спроса: они часто уже содержат тот же пик.
            $externalFloor = max($ozonAds, $localAvgDaily);
            if ($externalFloor > $cooldownCap * 1.25) {
                $cappedExternalDemand = true;
                $reasons[] = 'external_sources_capped_by_spike_guard';
            }

            $guardrailCap = max(0.0, $cooldownCap);
            $demand = min($demand, $guardrailCap);
        }

        $confidenceLevel = 'good';
        if ($orderedUnits > 0 && $orderedUnits < 5) {
            $confidenceLevel = 'low';
            $reasons[] = 'low_posting_volume';
            $demand *= 0.4;
        } elseif ($suspectedSpike || $activeDays > 0 && $activeDays < 5) {
            $confidenceLevel = 'warning';
        }

        if ($activeDays > 0 && $activeDays < 5) {
            $reasons[] = 'few_active_sales_days';
        }

        return [
            'daily_demand' => round(max(0.0, $demand), 4),
            'suspected_spike' => $suspectedSpike,
            'confidence_level' => $confidenceLevel,
            'confidence_reasons' => array_values(array_unique($reasons)),
            'period_avg' => round($periodAvg, 4),
            'recent_7_avg' => round($recent7, 4),
            'recent_14_avg' => round($recent14, 4),
            'recent_30_avg' => round($recent30, 4),
            'winsorized_avg' => round($winsorized, 4),
            'peak_day_units' => $peakDayUnits,
            'peak_share' => round($peakShare, 4),
            'active_days' => $activeDays,
            'guardrail_cap_daily_demand' => $guardrailCap !== null ? round($guardrailCap, 4) : null,
            'capped_external_daily_demand' => $cappedExternalDemand,
        ];
    }

    /**
     * Ограничить агрегированный Ozon-спрос, когда postings API не дал SKU/кластер.
     * Старые inventory-агрегаты часто держат распродажный пик внутри sales_30_days
     * и не имеют дневной формы, поэтому считаем их слабым источником и проверяем
     * охлаждение последних 7/14 дней относительно 30-дневного окна.
     *
     * @return array{daily_demand:float, suspected_spike:bool, confidence_level:string, confidence_reasons:string[], input_daily_demand:float, recent_7_avg:float, recent_14_avg:float, recent_30_avg:float, cap_daily_demand:float}
     */
    public function shapeOzonAggregateDemand(
        float $dailyDemand,
        float $sales7,
        float $sales14,
        float $sales30,
        float $avgDailySalesApi = 0.0
    ): array {
        $inputDemand = max(0.0, $dailyDemand);
        $recent7 = max(0.0, $sales7 / 7);
        $recent14 = max(0.0, $sales14 / 14);
        $recent30 = max(0.0, $sales30 / 30);
        $avgDailySalesApi = max(0.0, $avgDailySalesApi);

        $reasons = ['aggregate_sales_no_postings'];
        $suspectedSpike = false;
        $confidenceLevel = 'warning';

        if ($inputDemand <= self::EPS) {
            return [
                'daily_demand' => 0.0,
                'suspected_spike' => false,
                'confidence_level' => 'low',
                'confidence_reasons' => $reasons,
                'input_daily_demand' => 0.0,
                'recent_7_avg' => round($recent7, 4),
                'recent_14_avg' => round($recent14, 4),
                'recent_30_avg' => round($recent30, 4),
                'cap_daily_demand' => 0.0,
            ];
        }

        if ($recent30 > 0 && $recent7 < $recent30 * 0.35 && ($recent14 <= 0 || $recent14 < $recent30 * 0.75)) {
            $suspectedSpike = true;
            $reasons[] = 'post_promo_cooldown';
        }
        if ($recent30 > 0 && $recent14 > 0 && $recent14 < $recent30 * 0.55) {
            $suspectedSpike = true;
            $reasons[] = 'aggregate_recent_decline';
        }
        if ($recent30 > 0 && $avgDailySalesApi > $recent30 * 1.5) {
            $reasons[] = 'aggregate_api_above_recent';
        }

        if ($recent30 > 0 && $recent7 <= self::EPS && $recent14 <= self::EPS) {
            $confidenceLevel = 'low';
            $reasons[] = 'no_recent_sales_after_30d_spike';
        }

        $capCandidates = [];
        if ($recent7 > 0) {
            $capCandidates[] = $recent7 * 1.4;
        }
        if ($recent14 > 0) {
            $capCandidates[] = $recent14 * 0.85;
        }
        if ($recent30 > 0) {
            $capCandidates[] = $suspectedSpike ? $recent30 * 0.35 : $recent30 * 0.8;
        }
        if ($avgDailySalesApi > 0) {
            $apiCap = $recent30 > 0 ? min($avgDailySalesApi, $recent30) : $avgDailySalesApi;
            $capCandidates[] = $suspectedSpike && $recent30 > 0 ? min($apiCap, $recent30 * 0.5) : $apiCap;
        }

        $cap = $capCandidates !== [] ? max($capCandidates) : $inputDemand;
        if ($suspectedSpike) {
            $cap = min($cap, $recent30 > 0 ? max($recent30 * 0.5, $recent7 * 1.8, $recent14 * 0.75) : $cap);
            $confidenceLevel = $confidenceLevel === 'low' ? 'low' : 'warning';
        }

        $demand = min($inputDemand, max(0.0, $cap));

        return [
            'daily_demand' => round(max(0.0, $demand), 4),
            'suspected_spike' => $suspectedSpike,
            'confidence_level' => $confidenceLevel,
            'confidence_reasons' => array_values(array_unique($reasons)),
            'input_daily_demand' => round($inputDemand, 4),
            'recent_7_avg' => round($recent7, 4),
            'recent_14_avg' => round($recent14, 4),
            'recent_30_avg' => round($recent30, 4),
            'cap_daily_demand' => round(max(0.0, $cap), 4),
        ];
    }

    /**
     * Финальная защита от раздутой поставки после акции/всплеска.
     *
     * Даже если спрос уже был сглажен, поздние источники вроде внешней
     * рекомендации или старого среднего могут снова поднять количество. В таких
     * случаях план должен рекомендовать пробную поставку, а не полный объём.
     *
     * @param list<string> $confidenceReasons
     * @return array{qty:int, applied:bool, cap_qty:int|null, trial_cover_days:int|null, reason:string|null, reasons:list<string>}
     */
    public function applyProtectiveQuantityGuard(
        int $qty,
        float $dailyDemand,
        int $currentStock,
        int $inTransit,
        int $packMultiple,
        array $confidenceReasons,
        bool $lowConfidenceTrial,
        string $promoMode = 'none',
        string $marketplace = 'ozon',
    ): array {
        $qty = max(0, $qty);
        $packMultiple = max(1, $packMultiple);
        $reasons = array_values(array_unique(array_filter($confidenceReasons)));
        $promoReasons = [
            'promo_spike_peak_share',
            'promo_spike_peak_vs_median',
            'recent_spike_vs_period',
            'post_promo_cooldown',
            'no_recent_sales_after_30d_spike',
            'external_sources_capped_by_spike_guard',
            'aggregate_recent_decline',
        ];
        $hasPromoSpike = array_intersect($reasons, $promoReasons) !== [];
        $isCautiousPromoMode = in_array($promoMode, ['cautious', 'post_promo'], true);

        if ($marketplace !== 'ozon' || $qty <= 0 || $dailyDemand <= self::EPS || (! $hasPromoSpike && ! $lowConfidenceTrial && ! $isCautiousPromoMode)) {
            return [
                'qty' => $qty,
                'applied' => false,
                'cap_qty' => null,
                'trial_cover_days' => null,
                'reason' => null,
                'reasons' => $reasons,
            ];
        }

        $trialCoverDays = $hasPromoSpike || $isCautiousPromoMode ? 7 : 14;
        if (in_array('no_recent_sales_after_30d_spike', $reasons, true)) {
            $trialCoverDays = 3;
        }

        $available = max(0, $currentStock + $inTransit);
        $safetyDays = $hasPromoSpike ? 2 : 3;
        $capRaw = max(0.0, $dailyDemand * ($trialCoverDays + $safetyDays) - $available);
        $capQty = $capRaw > 0 ? $this->roundToPackMultiple($capRaw, $packMultiple) : 0;

        if ($capQty >= $qty) {
            return [
                'qty' => $qty,
                'applied' => false,
                'cap_qty' => $capQty,
                'trial_cover_days' => $trialCoverDays,
                'reason' => null,
                'reasons' => $reasons,
            ];
        }

        return [
            'qty' => $capQty,
            'applied' => true,
            'cap_qty' => $capQty,
            'trial_cover_days' => $trialCoverDays,
            'reason' => $hasPromoSpike || $isCautiousPromoMode
                ? 'protective_post_promo_trial_quantity'
                : 'protective_low_confidence_trial_quantity',
            'reasons' => array_values(array_unique(array_merge($reasons, ['protective_trial_quantity_cap']))),
        ];
    }

    // ─── Нужно с учётом safety stock ─────────────────────────────────

    /**
     * Рассчитать needed с учётом safety stock (v2).
     */
    public function calculateNeededWithSafety(
        float $dailyDemand,
        int   $targetCoverDays,
        float $safetyStock,
        int   $currentStock,
        int   $inTransit
    ): array {
        $targetStock = $dailyDemand * $targetCoverDays + $safetyStock;
        $neededBeforeCaps = max(0, $targetStock - ($currentStock + $inTransit));

        return [
            'target_stock' => $targetStock,
            'safety_stock' => $safetyStock,
            'needed_before_caps' => $neededBeforeCaps,
        ];
    }

    // ─── Приоритет поставки (v2) ─────────────────────────────────────

    /**
     * Рассчитать приоритетный скор (v2).
     *
     * Учитывает: ABC, OOS-риск, маржинальность, тренд, упущенную прибыль Ozon.
     */
    public function calculatePriorityScoreV2(
        string $abcPriority,
        ?string $oosDate,
        float  $coverBefore,
        int    $minCoverDays,
        string $salesTrend,
        float  $marginPercent = 0,
        float  $ozonLostProfit = 0,
        float  $lostRevenueDaily = 0
    ): array {
        $score = 0;

        // ABC базовый скор
        $score += match ($abcPriority) {
            'A' => 30,
            'B' => 15,
            'C' => 5,
            default => 0,
        };

        // OOS-риск
        if ($oosDate !== null) {
            $daysUntilOos = max(0, Carbon::parse($oosDate)->diffInDays(Carbon::today()));
            if ($daysUntilOos <= 3) $score += 40;
            elseif ($daysUntilOos <= 7) $score += 25;
            else $score += 10;
        }

        // Низкое покрытие
        if ($coverBefore < $minCoverDays) $score += 20;

        // Растущие продажи
        if ($salesTrend === 'growing') $score += 10;
        elseif ($salesTrend === 'declining') $score -= 5;

        // Высокая маржинальность (бонус)
        if ($marginPercent > 30) $score += 10;
        elseif ($marginPercent > 15) $score += 5;

        // Упущенная прибыль Ozon (каждые 5000₽ = +3 балла, макс +15)
        if ($ozonLostProfit > 0) {
            $score += min(15, (int) floor($ozonLostProfit / 5000) * 3);
        }

        // Упущенная выручка в день (каждые 10000₽ = +5 баллов, макс +15)
        if ($lostRevenueDaily > 0) {
            $score += min(15, (int) floor($lostRevenueDaily / 10000) * 5);
        }

        $score = max(0, min(100, $score));

        $priority = 'low';
        if ($score >= 70) $priority = 'critical';
        elseif ($score >= 50) $priority = 'high';
        elseif ($score >= 30) $priority = 'medium';

        return [
            'score' => $score,
            'priority' => $priority,
        ];
    }

    // ─── In-transit из заявок ─────────────────────────────────────────

    /**
     * Получить товары в пути из активных заявок на поставку.
     *
     * @return array<string, int> [sku => qty]
     */
    public function getInTransitFromSupplies(int $integrationId): array
    {
        return \Illuminate\Support\Facades\DB::table('supply_items')
            ->join('supplies', 'supply_items.supply_id', '=', 'supplies.id')
            ->where('supplies.integration_id', $integrationId)
            ->whereIn('supplies.status', [
                'draft_ozon',
                'slot_booked',
                'preparing',
                'ready_to_ship',
                'shipped',
                'in_transit',
            ])
            ->select('supply_items.sku', \Illuminate\Support\Facades\DB::raw('SUM(supply_items.planned_qty) as qty'))
            ->groupBy('supply_items.sku')
            ->pluck('qty', 'sku')
            ->map(fn($v) => (int) $v)
            ->toArray();
    }

    /**
     * Загрузить автоматический спрос Ozon FBO из актуального postings API.
     *
     * @return array{
     *   by_warehouse: array<string, array<string, array>>,
     *   by_cluster: array<string, array<string, array>>,
     *   by_offer: array<string, array>,
     *   source: string,
     *   days: int
     * }
     */
    public function loadOzonPostingDemand(Integration $integration, $products, array $clusterMapping, int $days = 60): array
    {
        if ($integration->marketplace !== 'ozon') {
            return ['by_warehouse' => [], 'by_cluster' => [], 'by_offer' => [], 'source' => 'posting_fbo_v3', 'days' => $days];
        }

        try {
            $productIdToOfferId = [];
            foreach ($products as $product) {
                $offerId = (string) ($product->sku ?? '');
                $ozonData = is_array($product->ozon_data ?? null) ? $product->ozon_data : [];

                foreach (['sku', 'product_id'] as $key) {
                    if (!empty($ozonData[$key]) && $offerId !== '') {
                        $productIdToOfferId[(string) $ozonData[$key]] = $offerId;
                    }
                }
            }

            $api = new SalesApi(OzonClient::fromIntegration($integration));
            $byWarehouse = $api->getSalesBySkuAndWarehouse($days, $productIdToOfferId);
            $byCluster = [];
            $byOffer = [];

            foreach ($byWarehouse as $offerId => $warehouseRows) {
                foreach ($warehouseRows as $warehouseId => $row) {
                    $units = (int) ($row['ordered_units_total'] ?? 0);
                    $this->accumulateOzonDemand($byOffer[$offerId], $row, $units, $days);

                    $warehouseName = (string) ($row['warehouse_name'] ?? $warehouseId);
                    $normalizedName = OzonWarehouseCluster::normalizeWarehouseName($warehouseName);
                    $cluster = $clusterMapping[$normalizedName] ?? null;

                    if ($cluster === null) {
                        continue;
                    }

                    $clusterId = (string) $cluster['cluster_id'];
                    $this->accumulateOzonDemand($byCluster[$offerId][$clusterId], $row, $units, $days, [
                        'cluster_id' => (int) $cluster['cluster_id'],
                        'cluster_name' => $cluster['cluster_name'] ?? null,
                        'region' => $cluster['region'] ?? null,
                    ]);
                }
            }

            \Illuminate\Support\Facades\Log::info('AutoSupplyPlanService: Ozon posting demand loaded', [
                'integration_id' => $integration->id,
                'days' => $days,
                'offer_ids' => count($byOffer),
                'cluster_offer_ids' => count($byCluster),
            ]);

            return [
                'by_warehouse' => $byWarehouse,
                'by_cluster' => $byCluster,
                'by_offer' => $byOffer,
                'source' => 'posting_fbo_v3',
                'days' => $days,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AutoSupplyPlanService: Ozon posting demand failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return ['by_warehouse' => [], 'by_cluster' => [], 'by_offer' => [], 'source' => 'posting_fbo_v3', 'days' => $days];
        }
    }

    private function accumulateOzonDemand(?array &$target, array $row, int $units, int $days, array $extra = []): void
    {
        $target ??= array_merge([
            'sales_7_days' => 0,
            'sales_14_days' => 0,
            'sales_30_days' => 0,
            'ordered_units_total' => 0,
            'avg_daily_sales' => 0.0,
            'winsorized_units_total' => 0.0,
            'winsorized_avg_daily_sales' => 0.0,
            'active_days' => 0,
            'peak_day_units' => 0,
            'peak_share' => 0.0,
            'median_nonzero_daily_units' => 0.0,
        ], $extra);

        $target['sales_7_days'] += (int) ($row['sales_7_days'] ?? round($units * 7 / max($days, 1)));
        $target['sales_14_days'] += (int) ($row['sales_14_days'] ?? round($units * 14 / max($days, 1)));
        $target['sales_30_days'] += (int) ($row['sales_30_days'] ?? round($units * 30 / max($days, 1)));
        $target['ordered_units_total'] += $units;
        $target['avg_daily_sales'] = round($target['ordered_units_total'] / max($days, 1), 4);
        $target['winsorized_units_total'] += (float) ($row['winsorized_units_total'] ?? $units);
        $target['winsorized_avg_daily_sales'] = round($target['winsorized_units_total'] / max($days, 1), 4);
        $target['active_days'] = max((int) ($target['active_days'] ?? 0), (int) ($row['active_days'] ?? 0));
        $target['peak_day_units'] = max((int) ($target['peak_day_units'] ?? 0), (int) ($row['peak_day_units'] ?? 0));
        $target['peak_share'] = $target['ordered_units_total'] > 0
            ? round(((int) $target['peak_day_units']) / (int) $target['ordered_units_total'], 4)
            : 0.0;
        $target['median_nonzero_daily_units'] = max(
            (float) ($target['median_nonzero_daily_units'] ?? 0),
            (float) ($row['median_nonzero_daily_units'] ?? 0)
        );
    }

    /**
     * Загрузить общий FBO/FBS остаток товара из актуального Ozon stocks API.
     *
     * @return array<string, array{total:int, reserved:int, source:string}>
     */
    public function loadOzonProductStocks(Integration $integration, $products): array
    {
        if ($integration->marketplace !== 'ozon') {
            return [];
        }

        $offerIds = [];
        foreach ($products as $product) {
            if (!empty($product->sku)) {
                $offerIds[] = (string) $product->sku;
            }
        }
        $offerIds = array_values(array_unique($offerIds));

        if ($offerIds === []) {
            return [];
        }

        try {
            $client = OzonClient::fromIntegration($integration);
            $result = [];

            foreach (array_chunk($offerIds, 1000) as $chunk) {
                $cursor = '';
                do {
                    $body = [
                        'filter' => [
                            'visibility' => 'ALL',
                            'offer_id' => $chunk,
                        ],
                        'limit' => 1000,
                    ];
                    if ($cursor !== '') {
                        $body['cursor'] = $cursor;
                    }

                    $response = $client->post('/v4/product/info/stocks', $body);
                    if (!$response || !empty($response['_error'])) {
                        break;
                    }

                    $items = $response['items'] ?? $response['result']['items'] ?? [];
                    $cursor = (string) ($response['cursor'] ?? $response['result']['cursor'] ?? '');

                    foreach ($items as $item) {
                        $offerId = (string) ($item['offer_id'] ?? '');
                        if ($offerId === '') {
                            continue;
                        }

                        $total = 0;
                        $reserved = 0;
                        foreach ($item['stocks'] ?? [] as $stock) {
                            $total += (int) ($stock['present'] ?? 0);
                            $reserved += (int) ($stock['reserved'] ?? 0);
                        }

                        $result[$offerId] = [
                            'total' => $total,
                            'reserved' => $reserved,
                            'source' => 'product_info_stocks',
                        ];
                    }
                } while ($cursor !== '' && count($items) === 1000);
            }

            \Illuminate\Support\Facades\Log::info('AutoSupplyPlanService: Ozon product stocks loaded', [
                'integration_id' => $integration->id,
                'requested_offer_ids' => count($offerIds),
                'received_offer_ids' => count($result),
            ]);

            return $result;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AutoSupplyPlanService: Ozon product stocks failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    // ─── Ozon DeliveryAnalytics ──────────────────────────────────────

    /**
     * Загрузить общий health-сигнал среднего времени доставки Ozon.
     *
     * Старый /v1/analytics/average-delivery-time больше не используем:
     * production Ozon отвечает "obsolete method cannot be used".
     *
     * @return array<string, mixed>
     */
    public function loadOzonDeliveryAnalytics(\App\Models\Integration $integration): array
    {
        if ($integration->marketplace !== 'ozon') {
            return [];
        }

        try {
            $client = OzonClient::fromIntegration($integration);
            $summary = $client->post('/v1/analytics/average-delivery-time/summary', [], true);

            \Illuminate\Support\Facades\Log::info('AutoSupplyPlanService: Ozon delivery analytics loaded', [
                'integration_id' => $integration->id,
                'has_summary' => ! empty($summary) && empty($summary['_error']),
            ]);

            return empty($summary) || ! empty($summary['_error'])
                ? []
                : ['__summary' => $summary];
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AutoSupplyPlanService: Ozon delivery analytics failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Загрузить аналитику остатков и оборачиваемости из Ozon API
     *
     * Использует:
     * - POST /v1/analytics/stocks — ads_cluster, idc_cluster, turnover_grade_cluster по SKU×склад
     * - POST /v1/analytics/turnover/stocks — ads за 60д, turnover, idc_grade по SKU
     *
     * @param \App\Models\Integration $integration
     * @param \Illuminate\Support\Collection $products Коллекция Product моделей (keyBy sku)
     * @return array ['stock_analytics' => [offer_id => [wh_name => data]], 'stock_analytics_cluster' => [offer_id => [cluster_id => data]], 'turnover' => [offer_id => data]]
     */
    public function loadOzonStockAnalytics(\App\Models\Integration $integration, $products): array
    {
        try {
            $client = \App\Domains\Ozon\Api\OzonClient::fromIntegration($integration);
            $api = new \App\Domains\Ozon\Api\StockAnalyticsApi($client);

            // Собираем числовые Ozon SKU из ozon_data
            $ozonSkus = [];
            foreach ($products as $product) {
                $ozonData = $product->ozon_data ?? [];
                $ozonSku = $ozonData['sku'] ?? null;
                if ($ozonSku) {
                    $ozonSkus[] = (int) $ozonSku;
                }
            }

            if (empty($ozonSkus)) {
                \Illuminate\Support\Facades\Log::info('AutoSupplyPlanService: нет Ozon SKU для аналитики');
                return ['stock_analytics' => [], 'stock_analytics_cluster' => [], 'turnover' => []];
            }

            // 1. Аналитика остатков по SKU × склад (ads_cluster, idc_cluster, turnover_grade_cluster)
            $stockAnalytics = $api->getStockAnalyticsByOfferWarehouse($ozonSkus);
            $stockAnalyticsCluster = $api->getStockAnalyticsByOfferCluster($ozonSkus);

            // 2. Оборачиваемость по SKU (ads за 60д, turnover, idc_grade)
            $turnover = $api->getTurnoverByOfferId($ozonSkus);

            \Illuminate\Support\Facades\Log::info('AutoSupplyPlanService: Ozon stock analytics loaded', [
                'integration_id' => $integration->id,
                'ozon_skus_count' => count($ozonSkus),
                'stock_analytics_skus' => count($stockAnalytics),
                'stock_analytics_cluster_skus' => count($stockAnalyticsCluster),
                'turnover_skus' => count($turnover),
            ]);

            return [
                'stock_analytics' => $stockAnalytics,
                'stock_analytics_cluster' => $stockAnalyticsCluster,
                'turnover' => $turnover,
            ];
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AutoSupplyPlanService: Ozon stock analytics failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            return ['stock_analytics' => [], 'stock_analytics_cluster' => [], 'turnover' => []];
        }
    }
}
