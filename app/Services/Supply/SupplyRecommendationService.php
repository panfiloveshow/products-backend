<?php

namespace App\Services\Supply;

use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\Supply;
use App\Models\SupplyRecommendation;
use App\Models\SupplySettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис расчёта рекомендаций на поставку
 * 
 * Формула потребности (MVP):
 * demand = avg_sales_per_day(window) * target_days
 * need = max(0, demand - (stock_fbo + in_transit - safety_buffer))
 * need_rounded = округление по кратности (короб/минималка)
 */
class SupplyRecommendationService
{
    /**
     * Рассчитать рекомендации для интеграции
     */
    public function calculateRecommendations(Integration $integration, ?string $clusterId = null): Collection
    {
        $settings = SupplySettings::getOrCreate($integration->id);
        
        if (!$settings->is_active) {
            Log::info("Supply recommendations disabled for integration {$integration->id}");
            return collect();
        }

        // Получаем данные о продажах и остатках
        $salesData = $this->getSalesData($integration->id);
        $stockData = $this->getStockData($integration->id, $clusterId);
        $transitData = $this->getTransitData($integration->id);
        
        // Получаем данные Ozon аналитики (рекомендации по кластерам)
        $ozonAnalytics = $this->getOzonDeliveryAnalytics($integration);

        $recommendations = collect();

        foreach ($stockData as $sku => $stocks) {
            // Пропускаем исключённые SKU
            if ($settings->isSkuExcluded($sku)) {
                continue;
            }

            $sales = $salesData[$sku] ?? null;
            if (!$sales) {
                continue; // Нет данных о продажах
            }

            // Рассчитываем общий остаток по всем складам для пропорционального распределения
            $totalStock = array_sum(array_column($stocks, 'quantity'));
            $warehouseCount = count($stocks);
            
            foreach ($stocks as $warehouseId => $stockInfo) {
                // Рассчитываем долю продаж для этого склада
                // Используем равномерное распределение — каждый склад получает равную долю
                // Это более справедливо, т.к. Ozon не даёт данные о продажах по регионам
                $stockShare = 1 / $warehouseCount;
                
                $recommendation = $this->calculateForSku(
                    $integration,
                    $settings,
                    $sku,
                    $stockInfo,
                    $sales,
                    $transitData[$sku] ?? 0,
                    $warehouseCount,
                    $stockShare,  // Доля продаж для этого склада
                    $totalStock,  // Общий остаток по всем складам
                    $ozonAnalytics[$sku] ?? null  // Данные Ozon аналитики
                );

                if ($recommendation) {
                    $recommendations->push($recommendation);
                }
            }
        }

        return $recommendations->sortByDesc('priority_score');
    }

    /**
     * Рассчитать рекомендацию для одного SKU
     * 
     * Расширенная версия с аналитикой:
     * - Тренд продаж (рост/падение)
     * - Прогноз даты OOS
     * - Упущенная выручка
     * - Динамический страховой запас
     * - ROI поставки
     */
    protected function calculateForSku(
        Integration $integration,
        SupplySettings $settings,
        string $sku,
        array $stockInfo,
        array $sales,
        int $inTransit,
        int $warehouseCount = 1,
        float $stockShare = 1.0,  // Доля продаж для этого склада (пропорционально остатку)
        int $totalStockAllWarehouses = 0,  // Общий остаток по всем складам
        ?array $ozonAnalytics = null  // Данные Ozon аналитики по SKU
    ): ?array {
        // Выбираем окно продаж (общие продажи по SKU)
        $totalAvgSales = $this->selectSalesWindow($sales, $settings->default_sales_window);
        
        if ($totalAvgSales <= 0) {
            return null; // Нет продаж
        }
        
        // Распределяем продажи по складам пропорционально остаткам
        // Склад с большим остатком получает большую долю продаж
        $avgSales = $totalAvgSales * $stockShare;

        // === БАЗОВЫЕ РАСЧЁТЫ ===
        $priority = $this->calculatePriority($sales);
        $targetDays = $settings->getTargetDays($priority);
        $currentStock = $stockInfo['quantity'] ?? 0;
        
        // === ТРЕНД ПРОДАЖ ===
        // Сравниваем продажи за 7 дней с продажами за 14 дней
        // Если 7d > среднего за 14d — продажи растут
        $salesTrend = $this->calculateSalesTrend($sales);
        
        // === ВОЛАТИЛЬНОСТЬ И ДИНАМИЧЕСКИЙ СТРАХОВОЙ ЗАПАС ===
        // Чем выше волатильность — тем больше страховой запас
        $volatility = $this->calculateVolatility($sales);
        $safetyStockDynamic = $this->calculateDynamicSafetyStock($avgSales, $volatility, $settings);
        $safetyStock = max($settings->calculateSafetyStock($avgSales), $safetyStockDynamic);
        
        // === ПРОГНОЗ OOS ===
        // Учитываем тренд: если продажи растут — OOS наступит раньше
        $adjustedSales = $this->adjustSalesForTrend($avgSales, $salesTrend);
        $totalAvailable = $currentStock + $inTransit;
        $daysUntilOos = $adjustedSales > 0 ? (int) floor($totalAvailable / $adjustedSales) : 999;
        $oosDate = $daysUntilOos < 365 ? now()->addDays($daysUntilOos)->toDateString() : null;
        
        // Дни запаса (без учёта товаров в пути — только текущий остаток)
        $daysOfStock = $avgSales > 0 ? (int) floor($currentStock / $avgSales) : 999;
        
        // === ФОРМУЛА ПОТРЕБНОСТИ ===
        $demand = (int) ceil($adjustedSales * $targetDays);
        $needRaw = max(0, $demand - ($currentStock + $inTransit - $safetyStock));
        
        // Округление по кратности
        $packMultiple = $stockInfo['pack_multiple'] ?? $settings->default_pack_multiple;
        $minOrderQty = $stockInfo['min_order_qty'] ?? $settings->min_order_qty;
        
        // Создаём рекомендацию даже если needRaw = 0 (для отображения всех складов)
        // Но recommended_qty будет 0 для складов где достаточно товара
        $recommendedQty = $needRaw > 0 
            ? $this->roundToMultiple($needRaw, $packMultiple, $minOrderQty) 
            : 0;

        // === РИСКИ ===
        $oosRisk = $daysUntilOos <= $settings->oos_risk_days;
        $overstockRisk = $daysOfStock > $settings->overstock_days;

        // === ФИНАНСОВЫЕ МЕТРИКИ (из unit_economics) ===
        $price = $sales['price'] ?? $stockInfo['price'] ?? 0;
        $costPrice = $sales['cost_price'] ?? $stockInfo['cost_price'] ?? 0;
        // Используем маржу из unit_economics если есть, иначе рассчитываем
        $marginPercent = $sales['margin_percent'] > 0 
            ? $sales['margin_percent'] 
            : ($price > 0 ? round(($price - $costPrice) / $price * 100, 2) : 0);
        
        // Дополнительные данные из unit_economics
        $redemptionRate = $sales['redemption_rate'] ?? 100;
        $turnoverDaysUe = $sales['turnover_days'] ?? 0;
        $localizationIndex = $sales['localization_index'] ?? 0;
        $storageCost = $sales['storage_cost'] ?? 0;
        $deliveryCost = $sales['delivery_cost'] ?? 0;
        $drrPercent = $sales['drr_percent'] ?? 0;
        
        // Упущенная выручка в день при OOS
        $lostRevenueDaily = $avgSales * $price;
        
        // Потенциальная упущенная выручка до момента поставки
        $leadTimeDays = $settings->default_lead_time_days;
        $daysWithoutStock = max(0, $leadTimeDays - $daysUntilOos);
        $lostRevenuePotential = $daysWithoutStock * $lostRevenueDaily;
        
        // === ROI ПОСТАВКИ ===
        $supplyCostEstimate = $recommendedQty * $costPrice;
        $expectedRevenue = $recommendedQty * $price;
        $expectedProfit = $expectedRevenue - $supplyCostEstimate;
        $roiPercent = $supplyCostEstimate > 0 ? round($expectedProfit / $supplyCostEstimate * 100, 2) : 0;

        // === ПРИОРИТЕТНЫЙ СКОР ===
        // Учитываем упущенную выручку в приоритете
        $priorityScore = $this->calculatePriorityScoreAdvanced(
            $priority, $oosRisk, $sales, $daysOfStock, $lostRevenuePotential, $salesTrend
        );

        // === ПРИЧИНЫ И ОПИСАНИЯ ===
        $reasons = $this->buildReasons($avgSales, $targetDays, $currentStock, $inTransit, $safetyStock, $demand, $needRaw);
        $explanations = $this->buildExplanations($salesTrend, $volatility, $daysUntilOos, $lostRevenuePotential, $roiPercent);
        $warnings = $this->buildWarnings($settings, $sku, $packMultiple, $recommendedQty, $needRaw);
        $restrictions = $settings->isSkuRestricted($sku) ? ['restricted' => true] : null;

        // Рекомендуемые даты
        $recommendedCreateDate = now()->addDays(max(0, $daysUntilOos - $leadTimeDays - 1))->toDateString();
        $recommendedDeliveryDate = now()->addDays(max(0, $daysUntilOos - 1))->toDateString();

        return [
            'integration_id' => $integration->id,
            'marketplace' => $integration->marketplace,
            'sku' => $sku,
            'ozon_product_id' => $stockInfo['ozon_product_id'] ?? null,
            'product_name' => $stockInfo['product_name'] ?? null,
            'product_id' => $stockInfo['product_id'] ?? null,
            'price' => $price,
            'cost_price' => $costPrice,
            'margin_percent' => $marginPercent,
            'cluster_id' => $stockInfo['cluster_id'] ?? null,
            'cluster_name' => $stockInfo['cluster_name'] ?? null,
            'warehouse_id' => $stockInfo['warehouse_id'] ?? null,
            'warehouse_name' => $stockInfo['warehouse_name'] ?? null,
            'avg_sales_7d' => $sales['avg_7d'] ?? 0,
            'avg_sales_14d' => $sales['avg_14d'] ?? 0,
            'avg_sales_28d' => $sales['avg_28d'] ?? 0,
            'avg_sales_used' => $avgSales,
            'sales_window' => $settings->default_sales_window,
            'sales_trend' => $salesTrend['trend'],
            'sales_trend_percent' => $salesTrend['percent'],
            'current_stock' => $currentStock,
            'in_transit' => $inTransit,
            'safety_stock' => $safetyStock,
            'sales_volatility' => $volatility,
            'safety_stock_dynamic' => $safetyStockDynamic,
            'target_days' => $targetDays,
            'demand' => $demand,
            'need_raw' => $needRaw,
            'recommended_qty' => $recommendedQty,
            'pack_multiple' => $packMultiple,
            'min_order_qty' => $minOrderQty,
            'priority' => $priority,
            'priority_score' => $priorityScore,
            'days_of_stock' => $daysOfStock,
            'days_until_oos' => $daysUntilOos,
            'oos_risk' => $oosRisk,
            'oos_date' => $oosDate,
            'overstock_risk' => $overstockRisk,
            'lost_revenue_daily' => round($lostRevenueDaily, 2),
            'lost_revenue_potential' => round($lostRevenuePotential, 2),
            'supply_cost_estimate' => round($supplyCostEstimate, 2),
            'expected_revenue' => round($expectedRevenue, 2),
            'expected_profit' => round($expectedProfit, 2),
            'roi_percent' => $roiPercent,
            // Данные из unit_economics
            'redemption_rate' => $redemptionRate,
            'turnover_days_ue' => $turnoverDaysUe,
            'localization_index' => $localizationIndex,
            'storage_cost' => $storageCost,
            'delivery_cost' => $deliveryCost,
            'drr_percent' => $drrPercent,
            // Данные Ozon аналитики
            // Используем маппинг склада к кластеру для получения данных по конкретному кластеру
            ...($this->getOzonClusterDataForWarehouse($stockInfo['warehouse_name'] ?? '', $ozonAnalytics)),
            'reasons' => $reasons,
            'explanations' => $explanations,
            'warnings' => $warnings,
            'restrictions' => $restrictions,
            'recommended_create_date' => $recommendedCreateDate,
            'recommended_delivery_date' => $recommendedDeliveryDate,
            'lead_time_days' => $leadTimeDays,
            'title' => $stockInfo['product_name']
                ? "Рекомендация поставки: {$stockInfo['product_name']}"
                : "Рекомендация поставки: {$sku}",
            'state' => SupplyRecommendation::STATE_NEW,
        ];
    }
    
    /**
     * Рассчитать тренд продаж
     * 
     * Сравнивает продажи за 7 дней со средними за 14 дней.
     * Если 7d > avg_14d — продажи растут.
     * 
     * @return array ['trend' => 'growing'|'stable'|'declining', 'percent' => float]
     */
    protected function calculateSalesTrend(array $sales): array
    {
        $avg7d = $sales['avg_7d'] ?? 0;
        $avg14d = $sales['avg_14d'] ?? 0;
        
        if ($avg14d <= 0) {
            return ['trend' => 'stable', 'percent' => 0];
        }
        
        $changePercent = round(($avg7d - $avg14d) / $avg14d * 100, 2);
        
        $trend = match (true) {
            $changePercent > 15 => 'growing',      // Рост > 15%
            $changePercent < -15 => 'declining',   // Падение > 15%
            default => 'stable',
        };
        
        return ['trend' => $trend, 'percent' => $changePercent];
    }
    
    /**
     * Рассчитать волатильность продаж
     * 
     * Коэффициент вариации = стандартное отклонение / среднее.
     * Высокая волатильность = нестабильные продажи = нужен больший страховой запас.
     */
    protected function calculateVolatility(array $sales): float
    {
        $avg7d = $sales['avg_7d'] ?? 0;
        $avg14d = $sales['avg_14d'] ?? 0;
        $avg28d = $sales['avg_28d'] ?? 0;
        
        $values = array_filter([$avg7d, $avg14d, $avg28d], fn($v) => $v > 0);
        
        if (count($values) < 2) {
            return 0;
        }
        
        $mean = array_sum($values) / count($values);
        if ($mean <= 0) {
            return 0;
        }
        
        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values);
        $stdDev = sqrt($variance);
        
        return round($stdDev / $mean, 4);
    }
    
    /**
     * Рассчитать динамический страховой запас
     * 
     * Формула: safety = avg_sales × lead_time × (1 + volatility × k)
     * где k — коэффициент запаса (обычно 1.5-2)
     */
    protected function calculateDynamicSafetyStock(float $avgSales, float $volatility, SupplySettings $settings): int
    {
        $leadTime = $settings->default_lead_time_days;
        $safetyCoef = 1.5; // Коэффициент запаса
        
        $dynamicSafety = $avgSales * $leadTime * (1 + $volatility * $safetyCoef);
        
        return (int) ceil($dynamicSafety);
    }
    
    /**
     * Скорректировать продажи с учётом тренда
     * 
     * Если продажи растут — увеличиваем прогноз.
     * Если падают — уменьшаем (но не более чем на 20%).
     */
    protected function adjustSalesForTrend(float $avgSales, array $salesTrend): float
    {
        $trendPercent = $salesTrend['percent'] ?? 0;
        
        // Ограничиваем корректировку: -20% до +30%
        $adjustment = max(-20, min(30, $trendPercent)) / 100;
        
        return $avgSales * (1 + $adjustment * 0.5); // Применяем 50% от тренда
    }
    
    /**
     * Расширенный расчёт приоритетного скора
     * 
     * Учитывает:
     * - ABC приоритет
     * - Риск OOS
     * - Упущенную выручку
     * - Тренд продаж
     */
    protected function calculatePriorityScoreAdvanced(
        string $priority,
        bool $oosRisk,
        array $sales,
        int $daysOfStock,
        float $lostRevenuePotential,
        array $salesTrend
    ): float {
        $score = 0;

        // Базовый скор по ABC
        $score += match ($priority) {
            SupplyRecommendation::PRIORITY_A => 100,
            SupplyRecommendation::PRIORITY_B => 50,
            SupplyRecommendation::PRIORITY_C => 25,
            default => 0,
        };

        // Бонус за OOS риск
        if ($oosRisk) {
            $score += 50;
        }

        // Бонус за критически низкий запас
        if ($daysOfStock <= 1) {
            $score += 30;
        } elseif ($daysOfStock <= 3) {
            $score += 15;
        }

        // Бонус за упущенную выручку (каждые 10000 руб = +5 баллов, макс +25)
        $score += min(25, floor($lostRevenuePotential / 10000) * 5);
        
        // Бонус за растущие продажи
        if ($salesTrend['trend'] === 'growing') {
            $score += 15;
        }

        return round($score, 2);
    }
    
    /**
     * Построить описания метрик для пользователя
     * 
     * Человекочитаемые объяснения всех расчётов.
     * Передаются на frontend для отображения в UI.
     */
    protected function buildExplanations(
        array $salesTrend,
        float $volatility,
        int $daysUntilOos,
        float $lostRevenuePotential,
        float $roiPercent
    ): array {
        $explanations = [];
        
        // Тренд продаж
        $trendText = match ($salesTrend['trend']) {
            'growing' => "📈 Продажи растут на {$salesTrend['percent']}% — рекомендуем увеличить объём поставки",
            'declining' => "📉 Продажи снижаются на " . abs($salesTrend['percent']) . "% — будьте осторожны с объёмом",
            default => "➡️ Продажи стабильны — можно ориентироваться на средние показатели",
        };
        $explanations['sales_trend'] = [
            'title' => 'Тренд продаж',
            'value' => $salesTrend['trend'],
            'percent' => $salesTrend['percent'],
            'description' => $trendText,
            'help' => 'Сравнение продаж за последние 7 дней со средними за 14 дней. Показывает динамику спроса.',
        ];
        
        // Волатильность
        $volatilityLevel = match (true) {
            $volatility > 0.5 => 'high',
            $volatility > 0.2 => 'medium',
            default => 'low',
        };
        $volatilityText = match ($volatilityLevel) {
            'high' => "⚠️ Высокая волатильность ({$volatility}) — продажи нестабильны, увеличен страховой запас",
            'medium' => "📊 Средняя волатильность ({$volatility}) — умеренные колебания продаж",
            default => "✅ Низкая волатильность ({$volatility}) — стабильные продажи",
        };
        $explanations['volatility'] = [
            'title' => 'Волатильность продаж',
            'value' => $volatilityLevel,
            'coefficient' => $volatility,
            'description' => $volatilityText,
            'help' => 'Показывает насколько стабильны продажи. Высокая волатильность = непредсказуемый спрос.',
        ];
        
        // Прогноз OOS
        $oosText = $daysUntilOos <= 3
            ? "🔴 Критично! Товар закончится через {$daysUntilOos} дн."
            : ($daysUntilOos <= 7
                ? "🟡 Внимание! Товар закончится через {$daysUntilOos} дн."
                : "🟢 Запас на {$daysUntilOos} дн.");
        $explanations['oos_forecast'] = [
            'title' => 'Прогноз OOS',
            'days' => $daysUntilOos,
            'description' => $oosText,
            'help' => 'Через сколько дней закончится товар при текущих продажах (с учётом товаров в пути).',
        ];
        
        // Упущенная выручка
        $lostRevenueText = $lostRevenuePotential > 0
            ? "💸 Потенциальные потери: " . number_format($lostRevenuePotential, 0, ',', ' ') . " ₽"
            : "✅ Нет риска упущенной выручки";
        $explanations['lost_revenue'] = [
            'title' => 'Упущенная выручка',
            'value' => $lostRevenuePotential,
            'description' => $lostRevenueText,
            'help' => 'Сколько денег вы потеряете, если товар закончится до момента поставки.',
        ];
        
        // ROI поставки
        $roiText = match (true) {
            $roiPercent > 50 => "🚀 Отличный ROI: {$roiPercent}% — высокоприбыльная поставка",
            $roiPercent > 20 => "👍 Хороший ROI: {$roiPercent}% — прибыльная поставка",
            $roiPercent > 0 => "📊 Умеренный ROI: {$roiPercent}% — поставка окупится",
            default => "⚠️ Низкий ROI: {$roiPercent}% — проверьте маржинальность",
        };
        $explanations['roi'] = [
            'title' => 'ROI поставки',
            'value' => $roiPercent,
            'description' => $roiText,
            'help' => 'Возврат на инвестиции. Показывает сколько прибыли принесёт каждый вложенный рубль.',
        ];
        
        return $explanations;
    }

    /**
     * Выбрать окно продаж
     * 
     * Примечание: В БД есть sales_30_days, но нет sales_28_days.
     * Для окна 28d используем sales_30_days / 30 как приближение.
     */
    protected function selectSalesWindow(array $sales, string $window): float
    {
        return match ($window) {
            '7d' => $sales['avg_7d'] ?? 0,
            '14d' => $sales['avg_14d'] ?? 0,
            '28d' => $sales['avg_28d'] ?? $sales['avg_30d'] ?? 0, // fallback на 30d
            '30d' => $sales['avg_30d'] ?? 0,
            default => $sales['avg_28d'] ?? $sales['avg_30d'] ?? $sales['avg_14d'] ?? 0,
        };
    }

    /**
     * Определить приоритет ABC на основе продаж
     */
    protected function calculatePriority(array $sales): string
    {
        $revenue30d = $sales['revenue_30d'] ?? 0;
        
        // Простая логика: топ по выручке = A, средние = B, остальные = C
        if ($revenue30d >= 100000) {
            return SupplyRecommendation::PRIORITY_A;
        } elseif ($revenue30d >= 30000) {
            return SupplyRecommendation::PRIORITY_B;
        }
        return SupplyRecommendation::PRIORITY_C;
    }

    /**
     * Рассчитать числовой скор приоритета
     */
    protected function calculatePriorityScore(string $priority, bool $oosRisk, array $sales, int $daysOfStock): float
    {
        $score = 0;

        // Базовый скор по ABC
        $score += match ($priority) {
            SupplyRecommendation::PRIORITY_A => 100,
            SupplyRecommendation::PRIORITY_B => 50,
            SupplyRecommendation::PRIORITY_C => 25,
            default => 0,
        };

        // Бонус за OOS риск
        if ($oosRisk) {
            $score += 50;
        }

        // Бонус за критически низкий запас
        if ($daysOfStock <= 1) {
            $score += 30;
        } elseif ($daysOfStock <= 3) {
            $score += 15;
        }

        // Бонус за высокую выручку
        $revenue30d = $sales['revenue_30d'] ?? 0;
        if ($revenue30d >= 500000) {
            $score += 20;
        } elseif ($revenue30d >= 200000) {
            $score += 10;
        }

        return round($score, 2);
    }

    /**
     * Округление по кратности
     */
    protected function roundToMultiple(int $value, int $multiple, int $minQty): int
    {
        if ($multiple <= 1) {
            return max($value, $minQty);
        }
        
        $rounded = (int) ceil($value / $multiple) * $multiple;
        return max($rounded, $minQty);
    }

    /**
     * Построить причины рекомендации
     */
    protected function buildReasons(
        float $avgSales,
        int $targetDays,
        int $currentStock,
        int $inTransit,
        int $safetyStock,
        int $demand,
        int $needRaw
    ): array {
        return [
            'formula' => "demand({$demand}) = avg_sales({$avgSales}) × target_days({$targetDays})",
            'calculation' => "need({$needRaw}) = demand({$demand}) - (stock({$currentStock}) + transit({$inTransit}) - safety({$safetyStock}))",
            'avg_daily_sales' => round($avgSales, 2),
            'target_coverage_days' => $targetDays,
            'current_stock' => $currentStock,
            'in_transit' => $inTransit,
            'safety_stock' => $safetyStock,
        ];
    }

    /**
     * Построить предупреждения
     */
    protected function buildWarnings(
        SupplySettings $settings,
        string $sku,
        int $packMultiple,
        int $recommendedQty,
        int $needRaw
    ): ?array {
        $warnings = [];

        // Предупреждение о кратности
        if ($packMultiple > 1 && $recommendedQty != $needRaw) {
            $warnings[] = [
                'type' => 'pack_multiple',
                'message' => "Количество округлено до кратности {$packMultiple}",
            ];
        }

        // Предупреждение об ограничениях
        if ($settings->isSkuRestricted($sku)) {
            $warnings[] = [
                'type' => 'restricted',
                'message' => 'SKU имеет ограничения на поставку',
            ];
        }

        return empty($warnings) ? null : $warnings;
    }

    /**
     * Получить данные о продажах из inventory_warehouses
     * 
     * ВАЖНО: Используем MAX вместо SUM, т.к. продажи в inventory_warehouses
     * уже агрегированы по SKU и одинаковы для всех складов одного товара.
     * SUM приводил к многократному завышению продаж (× количество складов).
     * 
     * Также получаем цену и себестоимость из unit_economics для расчёта ROI.
     */
    protected function getSalesData(int $integrationId): array
    {
        // Используем MAX для получения продаж (данные одинаковы для всех складов SKU)
        $sales = DB::table('inventory_warehouses')
            ->select([
                'sku',
                DB::raw('MAX(COALESCE(sales_7_days, 0)) as qty_7d'),
                DB::raw('MAX(COALESCE(sales_14_days, 0)) as qty_14d'),
                DB::raw('MAX(COALESCE(sales_28_days, 0)) as qty_28d'),
                DB::raw('MAX(COALESCE(sales_30_days, 0)) as qty_30d'),
                DB::raw('MAX(COALESCE(average_daily_sales, 0)) as avg_daily'),
            ])
            ->where('integration_id', $integrationId)
            ->whereNotNull('sku')
            ->groupBy('sku')
            ->get();

        // Получаем расширенные данные из unit_economics
        // Включаем: цену, себестоимость, маржу, выкуп, оборачиваемость, индекс локализации
        $unitEconomics = DB::table('unit_economics')
            ->select([
                'sku', 
                'price', 
                'cost_price',
                'margin_percent',
                'sales_count',
                'revenue',
                'redemption_rate',
                'turnover_days',
                'localization_index',
                'storage_cost',
                'delivery_cost',
                'drr_percent',
            ])
            ->where('integration_id', $integrationId)
            ->whereNotNull('sku')
            ->get()
            ->keyBy('sku');

        $result = [];
        foreach ($sales as $row) {
            // Используем average_daily_sales из БД если есть, иначе рассчитываем
            $avgDaily = (float) $row->avg_daily;
            $ueData = $unitEconomics->get($row->sku);
            
            // Рассчитываем средние продажи за разные периоды
            $avg7d = $row->qty_7d > 0 ? $row->qty_7d / 7 : 0;
            $avg14d = $row->qty_14d > 0 ? $row->qty_14d / 14 : 0;
            $avg28d = $row->qty_28d > 0 ? $row->qty_28d / 28 : 0;
            $avg30d = $row->qty_30d > 0 ? $row->qty_30d / 30 : 0;
            
            // Приоритет: average_daily_sales из БД > расчётное значение
            $result[$row->sku] = [
                'avg_7d' => $avg7d > 0 ? $avg7d : $avgDaily,
                'avg_14d' => $avg14d > 0 ? $avg14d : $avgDaily,
                'avg_28d' => $avg28d > 0 ? $avg28d : $avg30d, // fallback на 30d
                'avg_30d' => $avg30d > 0 ? $avg30d : $avgDaily,
                'revenue_30d' => (float) ($ueData->revenue ?? 0),
                // Данные из unit_economics
                'price' => (float) ($ueData->price ?? 0),
                'cost_price' => (float) ($ueData->cost_price ?? 0),
                'margin_percent' => (float) ($ueData->margin_percent ?? 0),
                'redemption_rate' => (float) ($ueData->redemption_rate ?? 100),
                'turnover_days' => (float) ($ueData->turnover_days ?? 0),
                'localization_index' => (float) ($ueData->localization_index ?? 0),
                'storage_cost' => (float) ($ueData->storage_cost ?? 0),
                'delivery_cost' => (float) ($ueData->delivery_cost ?? 0),
                'drr_percent' => (float) ($ueData->drr_percent ?? 0),
                'sales_count_ue' => (int) ($ueData->sales_count ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Получить данные об остатках
     */
    protected function getStockData(int $integrationId, ?string $clusterId = null): array
    {
        $query = InventoryWarehouse::query()
            ->where('integration_id', $integrationId)
            ->where('fulfillment_type', 'FBO');

        if ($clusterId) {
            $query->where('macrolocal_cluster_id', $clusterId);
        }

        $stocks = $query->get();

        $result = [];
        foreach ($stocks as $stock) {
            $sku = $stock->sku;
            $warehouseId = $stock->warehouse_id ?? $stock->warehouse_name;

            if (!isset($result[$sku])) {
                $result[$sku] = [];
            }

            $result[$sku][$warehouseId] = [
                'product_id' => $stock->product_id,
                'ozon_product_id' => $stock->ozon_product_id,
                'product_name' => $stock->product_name,
                'quantity' => $stock->quantity ?? 0,
                'reserved' => $stock->reserved ?? 0,
                'warehouse_id' => $stock->warehouse_id,
                'warehouse_name' => $stock->warehouse_name,
                'cluster_id' => $stock->macrolocal_cluster_id,
                'cluster_name' => $stock->cluster_name,
                'pack_multiple' => $stock->pack_multiple ?? 1,
                'min_order_qty' => $stock->min_order_qty ?? 1,
            ];
        }

        return $result;
    }

    /**
     * Получить данные о товарах в пути (созданные поставки)
     */
    protected function getTransitData(int $integrationId): array
    {
        $transit = DB::table('supply_items')
            ->join('supplies', 'supply_items.supply_id', '=', 'supplies.id')
            ->where('supplies.integration_id', $integrationId)
            ->whereIn('supplies.status', [
                Supply::STATUS_DRAFT_OZON,
                Supply::STATUS_SLOT_BOOKED,
                Supply::STATUS_PREPARING,
                Supply::STATUS_READY_TO_SHIP,
                Supply::STATUS_SHIPPED,
                Supply::STATUS_IN_TRANSIT,
            ])
            ->select('supply_items.sku', DB::raw('SUM(supply_items.planned_qty) as qty'))
            ->groupBy('supply_items.sku')
            ->get();

        $result = [];
        foreach ($transit as $row) {
            $result[$row->sku] = (int) $row->qty;
        }

        return $result;
    }

    /**
     * Сохранить рекомендации в БД
     */
    public function saveRecommendations(Collection $recommendations): int
    {
        $saved = 0;

        foreach ($recommendations as $data) {
            // Проверяем, нет ли уже активной рекомендации для этого SKU/склада
            $existing = SupplyRecommendation::where('integration_id', $data['integration_id'])
                ->where('sku', $data['sku'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->whereIn('state', [
                    SupplyRecommendation::STATE_NEW,
                    SupplyRecommendation::STATE_ACCEPTED,
                    SupplyRecommendation::STATE_POSTPONED,
                ])
                ->first();

            if ($existing) {
                // Обновляем существующую
                $existing->update($data);
            } else {
                // Создаём новую
                SupplyRecommendation::create($data);
            }

            $saved++;
        }

        return $saved;
    }

    /**
     * Пометить устаревшие рекомендации
     */
    public function expireOldRecommendations(int $integrationId, int $daysOld = 7): int
    {
        return SupplyRecommendation::where('integration_id', $integrationId)
            ->where('state', SupplyRecommendation::STATE_NEW)
            ->where('created_at', '<', now()->subDays($daysOld))
            ->update(['state' => SupplyRecommendation::STATE_EXPIRED]);
    }

    /**
     * Получить данные Ozon аналитики по доставке
     * 
     * Возвращает рекомендации Ozon по поставкам с разбивкой по кластерам:
     * - recommended_supply: рекомендуемое количество от Ozon
     * - lost_profit: упущенная прибыль из-за долгой доставки
     * - average_delivery_time_hours: среднее время доставки
     * - attention_level: уровень внимания (LOW, ATTENTION_MEDIUM, ATTENTION_HI)
     * - delivery_cluster_id/name: кластер доставки
     * 
     * @return array<string, array> Данные по SKU
     */
    protected function getOzonDeliveryAnalytics(Integration $integration): array
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

            $overallData = $overallResponse['data'] ?? [];
            $deliveryClusterIds = [];
            foreach ($overallData as $item) {
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
                    $ordersCount = $metrics['orders_count'] ?? [];
                    
                    $sku = $itemData['offer_id'] ?? null;
                    if (!$sku) continue;

                    // Сохраняем данные по каждому кластеру доставки отдельно
                    // Ключ: SKU + cluster_id (для уникальности)
                    $avgTime = $metrics['average_delivery_time'] ?? 0;
                    $attentionLevel = $metrics['attention_level'] ?? 'LOW';
                    $recSupply = $metrics['recommended_supply'] ?? 0;
                    $lostProfit = $metrics['lost_profit'] ?? 0;
                    
                    if (!isset($result[$sku])) {
                        $result[$sku] = [
                            'clusters' => [],
                            // Агрегированные данные для общего отображения
                            'total_recommended_supply' => 0,
                            'total_lost_profit' => 0,
                            'max_delivery_time' => 0,
                            'max_attention_level' => 'LOW',
                        ];
                    }
                    
                    // Агрегируем для общего отображения
                    $result[$sku]['total_recommended_supply'] += $recSupply;
                    $result[$sku]['total_lost_profit'] += $lostProfit;
                    if ($avgTime > $result[$sku]['max_delivery_time']) {
                        $result[$sku]['max_delivery_time'] = $avgTime;
                    }
                    $attentionOrder = ['LOW' => 0, 'ATTENTION_MEDIUM' => 1, 'ATTENTION_HI' => 2];
                    if (($attentionOrder[$attentionLevel] ?? 0) > ($attentionOrder[$result[$sku]['max_attention_level']] ?? 0)) {
                        $result[$sku]['max_attention_level'] = $attentionLevel;
                    }

                    // Сохраняем данные по каждому кластеру
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

            Log::info("Ozon delivery analytics loaded", [
                'integration_id' => $integration->id,
                'skus_count' => count($result),
                'clusters_count' => count($deliveryClusterIds),
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::warning("Failed to load Ozon delivery analytics", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Получить данные Ozon аналитики для конкретного склада
     * 
     * Использует маппинг склада к кластеру доставки для получения
     * рекомендаций Ozon по конкретному кластеру, а не агрегированных данных.
     * 
     * @param string $warehouseName Название склада
     * @param array|null $ozonAnalytics Данные Ozon аналитики по SKU
     * @return array Данные для сохранения в рекомендации
     */
    protected function getOzonClusterDataForWarehouse(string $warehouseName, ?array $ozonAnalytics): array
    {
        // Если нет данных Ozon аналитики, возвращаем пустые значения
        if (empty($ozonAnalytics) || empty($ozonAnalytics['clusters'])) {
            return [
                'ozon_recommended_supply' => null,
                'ozon_lost_profit' => null,
                'ozon_avg_delivery_time' => null,
                'ozon_attention_level' => null,
                'ozon_impact_share' => null,
                'delivery_cluster_id' => null,
                'delivery_cluster_name' => null,
                'ozon_clusters_data' => null,
            ];
        }

        // Получаем кластер для склада из маппинга
        $warehouseCluster = \App\Models\OzonWarehouseCluster::findByWarehouseName($warehouseName);
        
        // Данные по конкретному кластеру склада
        $clusterData = null;
        if ($warehouseCluster) {
            $clusterId = $warehouseCluster->cluster_id;
            // Ищем данные по этому кластеру в ozonAnalytics
            $clusterData = $ozonAnalytics['clusters'][$clusterId] ?? null;
        }

        // Если нашли данные по кластеру склада — используем их
        if ($clusterData) {
            return [
                'ozon_recommended_supply' => $clusterData['recommended_supply'] ?? 0,
                'ozon_lost_profit' => $clusterData['lost_profit'] ?? 0,
                'ozon_avg_delivery_time' => $clusterData['average_delivery_time'] ?? 0,
                'ozon_attention_level' => $clusterData['attention_level'] ?? 'LOW',
                'ozon_impact_share' => null,
                'delivery_cluster_id' => $warehouseCluster->cluster_id,
                'delivery_cluster_name' => $warehouseCluster->cluster_name,
                // Сохраняем все кластеры для справки
                'ozon_clusters_data' => array_values($ozonAnalytics['clusters']),
            ];
        }

        // Если не нашли данные по кластеру — возвращаем агрегированные данные
        // Но если маппинг найден — показываем название кластера из маппинга
        return [
            'ozon_recommended_supply' => $ozonAnalytics['total_recommended_supply'] ?? null,
            'ozon_lost_profit' => $ozonAnalytics['total_lost_profit'] ?? null,
            'ozon_avg_delivery_time' => $ozonAnalytics['max_delivery_time'] ?? null,
            'ozon_attention_level' => $ozonAnalytics['max_attention_level'] ?? null,
            'ozon_impact_share' => null,
            // Если маппинг найден — показываем кластер из маппинга, даже если нет данных аналитики
            'delivery_cluster_id' => $warehouseCluster?->cluster_id,
            'delivery_cluster_name' => $warehouseCluster?->cluster_name,
            'ozon_clusters_data' => array_values($ozonAnalytics['clusters']),
        ];
    }
}
