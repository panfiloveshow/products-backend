<?php

namespace App\Services;

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
    public function detectMissingSources($wh, $product, string $marketplace, $ue = null): array
    {
        $missing = [];
        if (($wh->sales_30_days ?? 0) <= 0 && ($wh->sales_14_days ?? 0) <= 0) $missing[] = 'sales_history';
        if (($wh->in_transit ?? 0) <= 0 && ($wh->quantity ?? 0) <= 0) $missing[] = 'stocks';
        if ($marketplace === 'wildberries' && empty($product?->barcode)) $missing[] = 'wb_barcode_map';
        if (($ue?->cost_price ?? $wh->cost_price ?? 0) <= 0) $missing[] = 'cost_price';
        if (($wh->real_avg_daily_sales ?? 0) <= 0) $missing[] = 'ozon_order_report';
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
        float  $avgDailySalesApi = 0
    ): array {
        $source = 'ewma';
        $needsManualReview = false;

        // Приоритет 1: real_avg_daily_sales из отчёта заказов (по складам)
        if ($realAvgDailySales > 0) {
            $baseDemand = $realAvgDailySales;
            $source = 'ozon_order_report';
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

        // Корректировка на тренд (±20% макс)
        $baseDemand = $this->adjustDemandByTrend($baseDemand, $salesTrend, $salesTrendPercent);

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

    // ─── Ozon DeliveryAnalytics ──────────────────────────────────────

    /**
     * Загрузить рекомендации Ozon по поставкам через Ozon API напрямую.
     * Подход скопирован из SupplyRecommendationService.getOzonDeliveryAnalytics().
     *
     * @return array<string, array{total_recommended_supply: int, total_lost_profit: float, max_delivery_time: int, max_attention_level: string, clusters: array}>
     */
    public function loadOzonDeliveryAnalytics(\App\Models\Integration $integration): array
    {
        if ($integration->marketplace !== 'ozon') {
            return [];
        }

        try {
            $marketplace = \App\Domains\Marketplace\MarketplaceFactory::create(
                $integration->marketplace,
                $integration->getDecryptedCredentials(),
                $integration
            );
            $client = $marketplace->api();

            // Получаем список кластеров
            $clusterList = $client->post('/v1/cluster/list', ['cluster_type' => 'CLUSTER_TYPE_OZON']);
            $clusterNames = [];
            foreach ($clusterList['clusters'] ?? [] as $c) {
                $clusterNames[$c['id']] = $c['name'] ?? "Кластер {$c['id']}";
            }

            // Получаем общую аналитику для списка кластеров
            $overallResponse = $client->post('/v1/analytics/average-delivery-time', [
                'filters' => [
                    'delivery_schema' => 'FBO',
                    'supply_period' => 'EIGHT_WEEKS',
                ]
            ]);

            $deliveryClusterIds = [];
            foreach ($overallResponse['data'] ?? [] as $item) {
                $clusterId = $item['delivery_cluster_id'] ?? null;
                if ($clusterId) {
                    $deliveryClusterIds[] = $clusterId;
                }
            }
            $deliveryClusterIds = array_unique($deliveryClusterIds);

            // Собираем данные по товарам из всех кластеров
            $result = [];

            foreach ($deliveryClusterIds as $clusterId) {
                $detailsResponse = $client->post('/v1/analytics/average-delivery-time/details', [
                    'cluster_id' => $clusterId,
                    'limit' => 1000,
                    'offset' => 0,
                    'filters' => [
                        'delivery_schema' => 'FBO',
                        'supply_period' => 'EIGHT_WEEKS',
                    ],
                ]);

                foreach ($detailsResponse['data'] ?? [] as $item) {
                    $itemData = $item['item'] ?? [];
                    $metrics = $item['metrics'] ?? [];

                    $sku = $itemData['offer_id'] ?? null;
                    if (!$sku) continue;

                    $avgTime = $metrics['average_delivery_time'] ?? 0;
                    $attentionLevel = $metrics['attention_level'] ?? 'LOW';
                    $recSupply = $metrics['recommended_supply'] ?? 0;
                    $lostProfit = $metrics['lost_profit'] ?? 0;

                    if (!isset($result[$sku])) {
                        $result[$sku] = [
                            'clusters' => [],
                            'total_recommended_supply' => 0,
                            'total_lost_profit' => 0,
                            'max_delivery_time' => 0,
                            'max_attention_level' => 'LOW',
                        ];
                    }

                    $result[$sku]['total_recommended_supply'] += $recSupply;
                    $result[$sku]['total_lost_profit'] += $lostProfit;
                    if ($avgTime > $result[$sku]['max_delivery_time']) {
                        $result[$sku]['max_delivery_time'] = $avgTime;
                    }
                    $attentionOrder = ['LOW' => 0, 'ATTENTION_MEDIUM' => 1, 'ATTENTION_HI' => 2];
                    if (($attentionOrder[$attentionLevel] ?? 0) > ($attentionOrder[$result[$sku]['max_attention_level']] ?? 0)) {
                        $result[$sku]['max_attention_level'] = $attentionLevel;
                    }

                    $result[$sku]['clusters'][$clusterId] = [
                        'cluster_id' => $clusterId,
                        'cluster_name' => $clusterNames[$clusterId] ?? "Кластер {$clusterId}",
                        'recommended_supply' => $recSupply,
                        'lost_profit' => $lostProfit,
                        'average_delivery_time' => $avgTime,
                        'attention_level' => $attentionLevel,
                    ];
                }
            }

            \Illuminate\Support\Facades\Log::info('AutoSupplyPlanService: Ozon delivery analytics loaded', [
                'integration_id' => $integration->id,
                'skus' => count($result),
                'clusters' => count($deliveryClusterIds),
            ]);

            return $result;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AutoSupplyPlanService: Ozon delivery analytics failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
