<?php

namespace App\Domains\Wildberries\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с продажами Wildberries
 */
class SalesApi
{
    public function __construct(
        private WildberriesClient $client
    ) {}

    /**
     * Получить статистику продаж за период
     */
    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        try {
            $response = $this->client->statistics("/api/v1/supplier/sales", [
                'dateFrom' => $dateFrom,
            ]);

            return $response ?? [];
        } catch (\Exception $e) {
            Log::error('WB getSalesStats error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить продажи по SKU за последние N дней
     * 
     * Индексирует по barcode (основной ключ в InventoryApi) и supplierArticle (fallback)
     */
    public function getSalesBySku(int $days = 30): array
    {
        try {
            $dateFrom = now()->subDays($days)->format('Y-m-d');
            
            $response = $this->client->statistics("/api/v1/supplier/sales", [
                'dateFrom' => $dateFrom,
            ]);

            $salesBySku = [];
            
            foreach ($response ?? [] as $sale) {
                // Используем barcode как основной ключ (совпадает с InventoryApi)
                $barcode = $sale['barcode'] ?? null;
                $supplierArticle = $sale['supplierArticle'] ?? null;
                
                // Пропускаем если нет ни barcode, ни supplierArticle
                if (!$barcode && !$supplierArticle) continue;

                $saleDate = isset($sale['date']) ? strtotime($sale['date']) : time();
                $daysAgo = (time() - $saleDate) / 86400;

                $quantity = (int)($sale['quantity'] ?? 1);
                $price = (float)($sale['priceWithDisc'] ?? 0);

                // Функция для добавления продаж к ключу
                $addSales = function($key) use (&$salesBySku, $quantity, $price, $daysAgo) {
                    if (!$key) return;
                    
                    if (!isset($salesBySku[$key])) {
                        $salesBySku[$key] = [
                            'sales_30_days' => 0,
                            'sales_14_days' => 0,
                            'sales_7_days' => 0,
                            'revenue' => 0,
                        ];
                    }

                    $salesBySku[$key]['sales_30_days'] += $quantity;
                    $salesBySku[$key]['revenue'] += $price * $quantity;

                    if ($daysAgo <= 14) {
                        $salesBySku[$key]['sales_14_days'] += $quantity;
                    }
                    if ($daysAgo <= 7) {
                        $salesBySku[$key]['sales_7_days'] += $quantity;
                    }
                };

                // Индексируем по barcode (основной ключ)
                $addSales($barcode);
                
                // Также индексируем по supplierArticle для совместимости
                if ($supplierArticle && $supplierArticle !== $barcode) {
                    $addSales($supplierArticle);
                }
            }

            // Добавляем avg_daily_sales
            foreach ($salesBySku as $sku => &$data) {
                $data['avg_daily_sales'] = round($data['sales_30_days'] / 30, 2);
            }
            
            Log::info('WB getSalesBySku: loaded sales data', [
                'count' => count($salesBySku),
                'sample_keys' => array_slice(array_keys($salesBySku), 0, 5),
            ]);

            return $salesBySku;
        } catch (\Exception $e) {
            Log::error('WB getSalesBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить продажи по регионам (федеральным округам) для расчёта индекса локализации
     * 
     * Использует API /api/v1/supplier/sales который содержит:
     * - warehouseName — склад отгрузки
     * - oblastOkrugName — федеральный округ доставки
     * 
     * Это позволяет точно определить локальные заказы (склад и доставка в одном ФО)
     * 
     * @param int $days Количество дней
     * @return array [nmId => ['total' => int, 'local' => int, 'by_warehouse_fo' => array]]
     */
    public function getSalesByRegion(int $days = 31): array
    {
        try {
            $dateFrom = now()->subDays($days)->format('Y-m-d');
            
            $response = $this->client->statistics("/api/v1/supplier/sales", [
                'dateFrom' => $dateFrom,
            ]);

            if (empty($response)) {
                return [];
            }
            
            // Маппинг складов WB на федеральные округа
            $warehouseToFo = $this->getWarehouseToFoMapping();
            
            // Кластеры ФО (объединённые округа)
            $foClusters = [
                'Северо-Кавказский федеральный округ' => 'south',
                'Южный федеральный округ' => 'south',
                'Сибирский федеральный округ' => 'siberia',
                'Дальневосточный федеральный округ' => 'siberia',
                'Центральный федеральный округ' => 'central',
                'Северо-Западный федеральный округ' => 'northwest',
                'Приволжский федеральный округ' => 'volga',
                'Уральский федеральный округ' => 'ural',
            ];
            
            $salesByNmId = [];
            
            foreach ($response as $sale) {
                $nmId = $sale['nmId'] ?? null;
                $warehouseName = $sale['warehouseName'] ?? '';
                $deliveryFo = $sale['oblastOkrugName'] ?? '';
                $qty = 1; // Каждая запись = 1 продажа
                
                if (!$nmId) continue;
                
                // Определяем ФО склада отгрузки
                $warehouseFo = $warehouseToFo[$warehouseName] ?? $this->guessWarehouseFo($warehouseName);
                
                // Определяем кластеры
                $warehouseCluster = $foClusters[$warehouseFo] ?? $warehouseFo;
                $deliveryCluster = $foClusters[$deliveryFo] ?? $deliveryFo;
                
                // Заказ локальный, если склад и доставка в одном кластере
                $isLocal = ($warehouseCluster === $deliveryCluster) && !empty($warehouseCluster) && !empty($deliveryCluster);
                
                if (!isset($salesByNmId[$nmId])) {
                    $salesByNmId[$nmId] = [
                        'total' => 0,
                        'local' => 0,
                        'by_warehouse' => [],
                        'by_delivery_fo' => [],
                    ];
                }
                
                $salesByNmId[$nmId]['total'] += $qty;
                if ($isLocal) {
                    $salesByNmId[$nmId]['local'] += $qty;
                }
                
                // Статистика по складам и ФО доставки
                $salesByNmId[$nmId]['by_warehouse'][$warehouseName] = 
                    ($salesByNmId[$nmId]['by_warehouse'][$warehouseName] ?? 0) + $qty;
                $salesByNmId[$nmId]['by_delivery_fo'][$deliveryFo] = 
                    ($salesByNmId[$nmId]['by_delivery_fo'][$deliveryFo] ?? 0) + $qty;
            }
            
            Log::info('WB getSalesByRegion: loaded sales with warehouse data', [
                'count' => count($salesByNmId),
                'total_sales' => array_sum(array_column($salesByNmId, 'total')),
                'date_from' => $dateFrom,
            ]);

            return $salesByNmId;
        } catch (\Exception $e) {
            Log::error('WB getSalesByRegion error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Маппинг складов WB на федеральные округа
     */
    private function getWarehouseToFoMapping(): array
    {
        return [
            // Центральный ФО
            'Коледино' => 'Центральный федеральный округ',
            'Подольск' => 'Центральный федеральный округ',
            'Подольск 3' => 'Центральный федеральный округ',
            'Подольск 4' => 'Центральный федеральный округ',
            'Электросталь' => 'Центральный федеральный округ',
            'Тула' => 'Центральный федеральный округ',
            'Белые Столбы' => 'Центральный федеральный округ',
            'Белые Столбы 2' => 'Центральный федеральный округ',
            'Чехов' => 'Центральный федеральный округ',
            'Чехов 2' => 'Центральный федеральный округ',
            'Домодедово' => 'Центральный федеральный округ',
            'Внуково' => 'Центральный федеральный округ',
            'Пушкино' => 'Центральный федеральный округ',
            'Пушкино 2' => 'Центральный федеральный округ',
            'Рязань' => 'Центральный федеральный округ',
            'Рязань (Тюшевское)' => 'Центральный федеральный округ',
            'Брянск' => 'Центральный федеральный округ',
            'Котовск' => 'Центральный федеральный округ',
            'Вёшки' => 'Центральный федеральный округ',
            // Северо-Западный ФО
            'Санкт-Петербург' => 'Северо-Западный федеральный округ',
            'СПб Шушары' => 'Северо-Западный федеральный округ',
            'СПб Уткина Заводь' => 'Северо-Западный федеральный округ',
            // Южный ФО
            'Краснодар' => 'Южный федеральный округ',
            'Ростов-на-Дону' => 'Южный федеральный округ',
            // Северо-Кавказский ФО
            'Невинномысск' => 'Северо-Кавказский федеральный округ',
            // Приволжский ФО
            'Казань' => 'Приволжский федеральный округ',
            'Нижний Новгород' => 'Приволжский федеральный округ',
            'Самара' => 'Приволжский федеральный округ',
            'Самара (Новосемейкино)' => 'Приволжский федеральный округ',
            // Уральский ФО
            'Екатеринбург' => 'Уральский федеральный округ',
            'Екатеринбург 2' => 'Уральский федеральный округ',
            // Сибирский ФО
            'Новосибирск' => 'Сибирский федеральный округ',
            'Красноярск' => 'Сибирский федеральный округ',
            // Дальневосточный ФО
            'Хабаровск' => 'Дальневосточный федеральный округ',
        ];
    }

    /**
     * Попытка определить ФО склада по названию
     */
    private function guessWarehouseFo(string $warehouseName): string
    {
        $mapping = $this->getWarehouseToFoMapping();
        
        // Точное совпадение
        if (isset($mapping[$warehouseName])) {
            return $mapping[$warehouseName];
        }
        
        // Частичное совпадение
        foreach ($mapping as $warehouse => $fo) {
            if (stripos($warehouseName, $warehouse) !== false || stripos($warehouse, $warehouseName) !== false) {
                return $fo;
            }
        }
        
        // По умолчанию — ЦФО (большинство складов там)
        return 'Центральный федеральный округ';
    }

    /**
     * Получить SPP (процент скидки продавца) из продаж
     */
    public function getSppFromSales(int $days = 30): array
    {
        try {
            $dateFrom = now()->subDays($days)->format('Y-m-d');
            
            $response = $this->client->statistics("/api/v1/supplier/sales", [
                'dateFrom' => $dateFrom,
            ]);

            $sppByNmId = [];
            foreach ($response ?? [] as $sale) {
                $nmId = $sale['nmId'] ?? null;
                if (!$nmId) continue;

                $spp = (float)($sale['spp'] ?? 0);
                
                if (!isset($sppByNmId[$nmId])) {
                    $sppByNmId[$nmId] = ['values' => [], 'count' => 0];
                }
                
                $sppByNmId[$nmId]['values'][] = $spp;
                $sppByNmId[$nmId]['count']++;
            }

            // Вычисляем средний SPP
            $result = [];
            foreach ($sppByNmId as $nmId => $data) {
                $avgSpp = count($data['values']) > 0 
                    ? array_sum($data['values']) / count($data['values']) 
                    : 0;
                $result[$nmId] = round($avgSpp, 2);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('WB getSppFromSales error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
