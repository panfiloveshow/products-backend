<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Domains\Wildberries\WildberriesMarketplace;
use Illuminate\Support\Facades\Log;

/**
 * Сервис расчёта индекса локализации (ИЛ) для Wildberries
 * 
 * С 22 сентября 2025 новая логика расчёта:
 * 1. КТР рассчитывается для каждого артикула на основе доли локализации
 * 2. ИЛ = средневзвешенное значение КТР по всем заказам
 * 
 * Формула:
 * - Доля локализации артикула = (локальные заказы / все заказы) × 100%
 * - КТР артикула = значение из таблицы соответствия
 * - ИЛ = Σ(заказы × КТР) / Σ(заказы)
 */
class LocalizationIndexService
{
    /**
     * Таблица соответствия доли локализации и КТР (актуальная с WB)
     * Диапазон локализации (%) => КТР
     * 
     * Источник: ЛК WB → Поставки и заказы → Тарифы → Индекс локализации
     */
    private const LOCALIZATION_TO_KTR = [
        // [min, max] => KTR
        [95.00, 100.00, 0.50],
        [90.00, 94.99, 0.65],
        [85.00, 89.99, 0.75],
        [80.00, 84.99, 0.85],
        [75.00, 79.99, 0.95],
        [70.00, 74.99, 1.00],
        [65.00, 69.99, 1.00],
        [60.00, 64.99, 1.00],
        [55.00, 59.99, 1.05],
        [50.00, 54.99, 1.15],
        [45.00, 49.99, 1.25],
        [40.00, 44.99, 1.35],
        [35.00, 39.99, 1.45],
        [30.00, 34.99, 1.55],
        [25.00, 29.99, 1.65],
        [20.00, 24.99, 1.75],
        [15.00, 19.99, 1.85],
        [10.00, 14.99, 1.90],
        [5.00, 9.99, 1.95],
        [0.00, 4.99, 2.00],
    ];

    /**
     * Таблица соответствия доли локализации и КРП (коэффициент распределения продаж, ИРП).
     * Источник: ЛК WB → Тарифы → Индекс распределения продаж (с 23.03.2026).
     * Значения в процентах от цены до СПП. При локализации ≥ 60% КРП = 0.
     * Диапазоны с одинаковым КРП объединены (35–44.99 → 2.10; 45–54.99 → 2.05).
     *
     * Диапазон локализации (%) => КРП (%)
     */
    private const LOCALIZATION_TO_KRP = [
        // [min, max] => КРП %
        [60.00, 100.00, 0.00],
        [55.00, 59.99, 2.00],
        [45.00, 54.99, 2.05],
        [35.00, 44.99, 2.10],
        [30.00, 34.99, 2.15],
        [25.00, 29.99, 2.20],
        [20.00, 24.99, 2.25],
        [15.00, 19.99, 2.30],
        [10.00, 14.99, 2.35],
        [5.00, 9.99, 2.45],
        [0.00, 4.99, 2.50],
    ];

    /**
     * Кластеры федеральных округов (объединённые для расчёта локализации)
     * Заказы внутри кластера считаются локальными
     */
    private const FO_CLUSTERS = [
        // Кластер 1: Северо-Кавказский + Южный
        'Северо-Кавказский федеральный округ' => 'south_cluster',
        'Южный федеральный округ' => 'south_cluster',
        // Кластер 2: Сибирский + Дальневосточный
        'Сибирский федеральный округ' => 'siberia_cluster',
        'Дальневосточный федеральный округ' => 'siberia_cluster',
        // Остальные — отдельные кластеры
        'Центральный федеральный округ' => 'central',
        'Северо-Западный федеральный округ' => 'northwest',
        'Приволжский федеральный округ' => 'volga',
        'Уральский федеральный округ' => 'ural',
    ];

    /**
     * Маппинг складов WB на федеральные округа
     */
    private const WAREHOUSE_TO_FO = [
        // Центральный ФО
        'Коледино' => 'central',
        'Подольск' => 'central',
        'Электросталь' => 'central',
        'Тула' => 'central',
        'Белые Столбы' => 'central',
        'Чехов' => 'central',
        'Чехов 2' => 'central',
        'Домодедово' => 'central',
        'Внуково' => 'central',
        'Рязань' => 'central',
        'Рязань (Тюшевское)' => 'central',
        'Брянск' => 'central',
        // Северо-Западный ФО
        'Санкт-Петербург' => 'northwest',
        'СПб Шушары' => 'northwest',
        'Невинномысск' => 'northwest',
        // Южный + Северо-Кавказский (кластер)
        'Краснодар' => 'south_cluster',
        'Ростов-на-Дону' => 'south_cluster',
        // Приволжский ФО
        'Казань' => 'volga',
        'Нижний Новгород' => 'volga',
        'Самара' => 'volga',
        'Самара (Новосемейкино)' => 'volga',
        // Уральский ФО
        'Екатеринбург' => 'ural',
        'Екатеринбург 2' => 'ural',
        // Сибирский + Дальневосточный (кластер)
        'Новосибирск' => 'siberia_cluster',
        'Красноярск' => 'siberia_cluster',
        'Хабаровск' => 'siberia_cluster',
    ];

    /**
     * Рассчитать индекс локализации для интеграции
     * 
     * @param Integration $integration
     * @return array ['localization_index' => float, 'ktr_by_article' => array, 'total_orders' => int]
     */
    public function calculateLocalizationIndex(Integration $integration): array
    {
        try {
            // Резолв с Sellico-фолбэком: fromIntegration() берёт только локальный
            // api_key, а у Sellico-интеграций он пуст → getSalesByRegion возвращал
            // пусто → ИЛ оставался 1.0. Берём резолвнутые креды.
            $marketplace = new WildberriesMarketplace($integration->resolveCredentials(), $integration);

            // Получаем продажи по регионам (с данными о складе отгрузки)
            // WB считает ИЛ/ИРП за 13 недель (91 день). /api/v1/supplier/sales
            // принимает любой dateFrom (не ограничен 7 днями — это было заблуждение),
            // поэтому берём 91 день, чтобы значения совпадали с ЛК WB.
            $salesByRegion = $marketplace->getSalesByRegion(91);
            
            if (empty($salesByRegion)) {
                return [
                    'localization_index' => 1.0,
                    'sales_distribution_index' => 0.0,
                    'ktr_by_article' => [],
                    'total_orders' => 0,
                    'error' => 'No regional sales data',
                ];
            }
            
            $ktrByArticle = [];
            $totalOrders = 0;
            $weightedKtrSum = 0;
            $weightedKrpSum = 0;
            
            foreach ($salesByRegion as $nmId => $data) {
                $articleOrders = $data['total'];
                $localOrders = $data['local'];
                
                // Доля локализации = локальные заказы / все заказы × 100%
                $localizationRate = $articleOrders > 0 ? ($localOrders / $articleOrders) * 100 : 0;
                
                // КТР (для ИЛ) и КРП (для ИРП) по таблицам — оба от той же доли локализации
                $ktr = $this->getKtrByLocalization($localizationRate);
                $krp = $this->getKrpByLocalization($localizationRate);

                $ktrByArticle[$nmId] = [
                    'total_orders' => $articleOrders,
                    'local_orders' => $localOrders,
                    'localization_rate' => round($localizationRate, 2),
                    'ktr' => $ktr,
                    'krp' => $krp,
                    'by_delivery_fo' => $data['by_delivery_fo'] ?? [],
                    'by_warehouse' => $data['by_warehouse'] ?? [],
                ];

                $totalOrders += $articleOrders;
                $weightedKtrSum += $articleOrders * $ktr;
                $weightedKrpSum += $articleOrders * $krp;
            }

            // Средневзвешенный ИЛ и ИРП (по заказам)
            $localizationIndex = $totalOrders > 0 ? $weightedKtrSum / $totalOrders : 1.0;
            $salesDistributionIndex = $totalOrders > 0 ? $weightedKrpSum / $totalOrders : 0.0;
            
            Log::info('LocalizationIndex calculated', [
                'integration_id' => $integration->id,
                'localization_index' => round($localizationIndex, 2),
                'total_orders' => $totalOrders,
                'articles_count' => count($ktrByArticle),
            ]);
            
            return [
                'localization_index' => round($localizationIndex, 2),
                'sales_distribution_index' => round($salesDistributionIndex, 2),
                'ktr_by_article' => $ktrByArticle,
                'total_orders' => $totalOrders,
            ];
            
        } catch (\Exception $e) {
            Log::error('LocalizationIndex calculation error', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'localization_index' => 1.0,
                'sales_distribution_index' => 0.0,
                'ktr_by_article' => [],
                'total_orders' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Получить КТР по доле локализации
     */
    public function getKtrByLocalization(float $localizationRate): float
    {
        foreach (self::LOCALIZATION_TO_KTR as $range) {
            [$min, $max, $ktr] = $range;
            if ($localizationRate >= $min && $localizationRate <= $max) {
                return $ktr;
            }
        }
        
        // По умолчанию — максимальный КТР для 0% локализации
        return 2.00;
    }

    /**
     * Получить КРП (ИРП, % от цены до СПП) по доле локализации.
     * При локализации ≥ 60% КРП = 0.
     */
    public function getKrpByLocalization(float $localizationRate): float
    {
        foreach (self::LOCALIZATION_TO_KRP as $range) {
            [$min, $max, $krp] = $range;
            if ($localizationRate >= $min && $localizationRate <= $max) {
                return $krp;
            }
        }

        // По умолчанию — максимальный КРП для 0% локализации
        return 2.50;
    }

    /**
     * Получить распределение остатков по складам для интеграции
     */
    private function getWarehouseDistribution(int $integrationId): array
    {
        $warehouses = InventoryWarehouse::where('integration_id', $integrationId)
            ->where('marketplace', 'wildberries')
            ->where('quantity', '>', 0)
            ->get(['sku', 'warehouse_name', 'quantity']);
        
        $distribution = [];
        foreach ($warehouses as $wh) {
            $sku = $wh->sku;
            $warehouseName = $wh->warehouse_name;
            $cluster = $this->getClusterByWarehouse($warehouseName);
            
            if (!isset($distribution[$sku])) {
                $distribution[$sku] = [];
            }
            
            $distribution[$sku][$cluster] = ($distribution[$sku][$cluster] ?? 0) + $wh->quantity;
        }
        
        return $distribution;
    }

    /**
     * Определить кластер по названию склада
     */
    private function getClusterByWarehouse(string $warehouseName): string
    {
        // Точное совпадение
        if (isset(self::WAREHOUSE_TO_FO[$warehouseName])) {
            return self::WAREHOUSE_TO_FO[$warehouseName];
        }
        
        // Частичное совпадение
        foreach (self::WAREHOUSE_TO_FO as $warehouse => $cluster) {
            if (stripos($warehouseName, $warehouse) !== false) {
                return $cluster;
            }
        }
        
        // По умолчанию — Центральный
        return 'central';
    }

    /**
     * Определить "домашний" кластер для артикула (где больше всего остатков)
     */
    private function getHomeCluster(int $nmId, array $warehouseDistribution): string
    {
        // Ищем по nmId (может быть в SKU)
        foreach ($warehouseDistribution as $sku => $clusters) {
            if (strpos($sku, (string) $nmId) !== false) {
                // Находим кластер с максимальным количеством
                $maxCluster = 'central';
                $maxQty = 0;
                foreach ($clusters as $cluster => $qty) {
                    if ($qty > $maxQty) {
                        $maxQty = $qty;
                        $maxCluster = $cluster;
                    }
                }
                return $maxCluster;
            }
        }
        
        return 'central'; // По умолчанию
    }

    /**
     * Получить таблицу соответствия локализации и КТР
     */
    public function getLocalizationTable(): array
    {
        return self::LOCALIZATION_TO_KTR;
    }
}
