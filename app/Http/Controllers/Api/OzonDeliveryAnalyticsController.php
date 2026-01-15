<?php

namespace App\Http\Controllers\Api;

use App\Domains\Marketplace\MarketplaceFactory;
use App\Domains\Ozon\Api\DeliveryAnalyticsApi;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для аналитики доставки Ozon
 * 
 * Предоставляет данные из раздела "Аналитика → География продаж → Среднее время доставки"
 */
class OzonDeliveryAnalyticsController extends Controller
{
    /**
     * GET /api/ozon/delivery-analytics
     * 
     * Получить общую аналитику по времени доставки
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'delivery_schema' => 'nullable|in:ALL,FBO,FBS',
            'supply_period' => 'nullable|in:FOUR_WEEKS,EIGHT_WEEKS',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Этот endpoint только для Ozon',
            ], 422);
        }

        $api = $this->getDeliveryAnalyticsApi($integration);
        
        $data = $api->getDeliveryTimeOverall([
            'delivery_schema' => $validated['delivery_schema'] ?? 'ALL',
            'supply_period' => $validated['supply_period'] ?? 'EIGHT_WEEKS',
        ]);

        return response()->json([
            'message' => 'Success',
            'data' => $data,
        ]);
    }

    /**
     * GET /api/ozon/delivery-analytics/details
     * 
     * Получить детальную аналитику по времени доставки для кластера
     */
    public function details(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'cluster_id' => 'required|integer',
            'delivery_schema' => 'nullable|in:ALL,FBO,FBS',
            'supply_period' => 'nullable|in:FOUR_WEEKS,EIGHT_WEEKS',
            'limit' => 'nullable|integer|min:1|max:1000',
            'offset' => 'nullable|integer|min:0',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Этот endpoint только для Ozon',
            ], 422);
        }

        $api = $this->getDeliveryAnalyticsApi($integration);
        
        $data = $api->getDeliveryTimeDetails(
            $validated['cluster_id'],
            [
                'delivery_schema' => $validated['delivery_schema'] ?? 'ALL',
                'supply_period' => $validated['supply_period'] ?? 'EIGHT_WEEKS',
            ],
            $validated['limit'] ?? 1000,
            $validated['offset'] ?? 0
        );

        return response()->json([
            'message' => 'Success',
            'data' => $data['data'],
            'total_rows' => $data['total_rows'],
        ]);
    }

    /**
     * GET /api/ozon/delivery-analytics/recommendations
     * 
     * Получить рекомендации по поставкам на основе аналитики доставки
     * 
     * Это данные, аналогичные разделу "Рекомендации по поставкам" в ЛК Ozon
     */
    public function recommendations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'delivery_schema' => 'nullable|in:ALL,FBO,FBS',
            'supply_period' => 'nullable|in:FOUR_WEEKS,EIGHT_WEEKS',
            'attention_level' => 'nullable|in:LOW,ATTENTION_MEDIUM,ATTENTION_HI',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Этот endpoint только для Ozon',
            ], 422);
        }

        $api = $this->getDeliveryAnalyticsApi($integration);
        
        $deliverySchema = $validated['delivery_schema'] ?? 'ALL';
        $supplyPeriod = $validated['supply_period'] ?? 'EIGHT_WEEKS';
        
        $recommendations = $api->getSupplyRecommendations(
            [],
            $deliverySchema,
            $supplyPeriod
        );

        // Получаем общую статистику из Ozon API (даже если детальные данные пустые)
        $overallData = $api->getDeliveryTimeOverall([
            'delivery_schema' => $deliverySchema,
            'supply_period' => $supplyPeriod,
        ]);
        $overallTotal = $overallData['total'] ?? [];

        // Фильтруем по уровню внимания если указан
        if (!empty($validated['attention_level'])) {
            $recommendations = array_filter(
                $recommendations,
                fn($r) => $r['attention_level'] === $validated['attention_level']
            );
            $recommendations = array_values($recommendations);
        }

        // Статистика из детальных данных
        $deliveryTimes = array_filter(array_column($recommendations, 'average_delivery_time'));
        $avgDeliveryTimeHours = count($deliveryTimes) > 0 
            ? round(array_sum($deliveryTimes) / count($deliveryTimes), 1) 
            : ($overallTotal['average_delivery_time'] ?? 0);
        
        // Добавляем поле average_delivery_time_days к каждому товару для удобства фронтенда
        $recommendations = array_map(function($item) {
            $item['average_delivery_time_hours'] = $item['average_delivery_time'] ?? 0;
            $item['average_delivery_time_days'] = round(($item['average_delivery_time'] ?? 0) / 24, 1);
            return $item;
        }, $recommendations);
        
        // Если детальные данные пустые, берём статистику из overall
        $stats = [
            'total' => count($recommendations),
            'by_attention' => [
                'high' => count(array_filter($recommendations, fn($r) => $r['attention_level'] === 'ATTENTION_HI')),
                'medium' => count(array_filter($recommendations, fn($r) => $r['attention_level'] === 'ATTENTION_MEDIUM')),
                'low' => count(array_filter($recommendations, fn($r) => $r['attention_level'] === 'LOW')),
            ],
            'total_recommended_supply' => count($recommendations) > 0 
                ? array_sum(array_column($recommendations, 'recommended_supply'))
                : ($overallTotal['recommended_supply'] ?? 0),
            'total_lost_profit' => count($recommendations) > 0 
                ? array_sum(array_column($recommendations, 'lost_profit'))
                : ($overallTotal['lost_profit'] ?? 0),
            // Время доставки в часах и днях
            'average_delivery_time' => $avgDeliveryTimeHours,
            'average_delivery_time_hours' => $avgDeliveryTimeHours,
            'average_delivery_time_days' => round($avgDeliveryTimeHours / 24, 1),
            // Дополнительные поля из overall
            'overall_attention_level' => $overallTotal['attention_level'] ?? 'LOW',
            'overall_orders_total' => $overallTotal['orders_count']['total'] ?? 0,
            'overall_orders_fast' => $overallTotal['orders_count']['fast']['value'] ?? 0,
            'overall_orders_medium' => $overallTotal['orders_count']['medium']['value'] ?? 0,
            'overall_orders_long' => $overallTotal['orders_count']['long']['value'] ?? 0,
            'overall_impact_share' => $overallTotal['impact_share'] ?? 0,
        ];

        return response()->json([
            'message' => 'Success',
            'data' => $recommendations,
            'stats' => $stats,
        ]);
    }

    /**
     * GET /api/ozon/delivery-analytics/clusters
     * 
     * Получить список кластеров доставки
     */
    public function clusters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Этот endpoint только для Ozon',
            ], 422);
        }

        $api = $this->getDeliveryAnalyticsApi($integration);
        $clusters = $api->getClusters();

        return response()->json([
            'message' => 'Success',
            'data' => $clusters,
        ]);
    }

    /**
     * GET /api/ozon/delivery-analytics/by-clusters
     * 
     * Получить аналитику по кластерам доставки (как на скриншоте Ozon)
     * 
     * Возвращает данные в формате:
     * - Кластер доставки + Название товара
     * - Ср. время доставки до покупателя (в часах)
     * - Доля влияния на общий показатель
     * - Переплата за логистику (lost_profit)
     * - Рекомендуемая поставка
     * - Заказано товаров (Всего, Долго, Средне, Быстро)
     * - Кластера отгрузки (распределение по складам)
     */
    public function byClusters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'delivery_schema' => 'nullable|in:ALL,FBO,FBS',
            'supply_period' => 'nullable|in:FOUR_WEEKS,EIGHT_WEEKS',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Этот endpoint только для Ozon',
            ], 422);
        }

        $marketplace = MarketplaceFactory::create(
            $integration->marketplace,
            $integration->getDecryptedCredentials(),
            $integration
        );
        $client = $marketplace->api();

        // Получаем список кластеров с названиями
        $clusterList = $client->post('/v1/cluster/list', ['cluster_type' => 'CLUSTER_TYPE_OZON']);
        $clusterNames = [];
        foreach ($clusterList['clusters'] ?? [] as $c) {
            $clusterNames[$c['id']] = $c['name'] ?? "Кластер {$c['id']}";
        }

        // Получаем аналитику по времени доставки
        $response = $client->post('/v1/analytics/average-delivery-time', [
            'filters' => [
                'delivery_schema' => $validated['delivery_schema'] ?? 'ALL',
                'supply_period' => $validated['supply_period'] ?? 'EIGHT_WEEKS',
            ]
        ]);

        $total = $response['total'] ?? [];
        $data = $response['data'] ?? [];

        // Форматируем данные по кластерам
        $clusters = [];
        foreach ($data as $item) {
            $clusterId = $item['delivery_cluster_id'] ?? null;
            $metrics = $item['metrics'] ?? [];
            $ordersCount = $metrics['orders_count'] ?? [];

            $clusters[] = [
                'cluster_id' => $clusterId,
                'cluster_name' => $clusterNames[$clusterId] ?? "Кластер {$clusterId}",
                'average_delivery_time_hours' => $metrics['average_delivery_time'] ?? 0,
                'average_delivery_time_status' => $metrics['average_delivery_time_status'] ?? 'UNKNOWN',
                'impact_share' => $metrics['impact_share'] ?? 0,
                'exact_impact_share' => (float) ($metrics['exact_impact_share'] ?? 0),
                'lost_profit' => $metrics['lost_profit'] ?? 0,
                'recommended_supply' => $metrics['recommended_supply'] ?? 0,
                'attention_level' => $metrics['attention_level'] ?? 'LOW',
                'orders' => [
                    'total' => $ordersCount['total'] ?? 0,
                    'long' => [
                        'value' => $ordersCount['long']['value'] ?? 0,
                        'percent' => $ordersCount['long']['percent'] ?? 0,
                    ],
                    'medium' => [
                        'value' => $ordersCount['medium']['value'] ?? 0,
                        'percent' => $ordersCount['medium']['percent'] ?? 0,
                    ],
                    'fast' => [
                        'value' => $ordersCount['fast']['value'] ?? 0,
                        'percent' => $ordersCount['fast']['percent'] ?? 0,
                    ],
                ],
            ];
        }

        // Сортируем по доле влияния (убывание)
        usort($clusters, fn($a, $b) => $b['exact_impact_share'] <=> $a['exact_impact_share']);

        // Форматируем итого
        $totalOrders = $total['orders_count'] ?? [];
        $summary = [
            'average_delivery_time_hours' => $total['average_delivery_time'] ?? 0,
            'average_delivery_time_status' => $total['average_delivery_time_status'] ?? 'UNKNOWN',
            'impact_share' => 100,
            'lost_profit' => $total['lost_profit'] ?? 0,
            'recommended_supply' => $total['recommended_supply'] ?? 0,
            'attention_level' => $total['attention_level'] ?? 'LOW',
            'orders' => [
                'total' => $totalOrders['total'] ?? 0,
                'long' => [
                    'value' => $totalOrders['long']['value'] ?? 0,
                    'percent' => $totalOrders['long']['percent'] ?? 0,
                ],
                'medium' => [
                    'value' => $totalOrders['medium']['value'] ?? 0,
                    'percent' => $totalOrders['medium']['percent'] ?? 0,
                ],
                'fast' => [
                    'value' => $totalOrders['fast']['value'] ?? 0,
                    'percent' => $totalOrders['fast']['percent'] ?? 0,
                ],
            ],
        ];

        return response()->json([
            'message' => 'Success',
            'summary' => $summary,
            'clusters' => $clusters,
            'cluster_names' => $clusterNames,
        ]);
    }

    /**
     * GET /api/ozon/delivery-analytics/by-products
     * 
     * Получить аналитику по товарам с разбивкой по кластерам отгрузки
     * (как на скриншоте Ozon: "Кластер доставки + Название товара")
     * 
     * Возвращает данные в формате:
     * - Товар (SKU, название)
     * - Ср. время доставки до покупателя (в часах)
     * - Доля влияния на общий показатель
     * - Переплата за логистику (lost_profit)
     * - Рекомендуемая поставка на 28 дней
     * - Заказано товаров (Всего, Долго, Средне, Быстро)
     * - Кластера отгрузки (распределение продаж по складам)
     */
    public function byProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'delivery_schema' => 'nullable|in:ALL,FBO,FBS',
            'supply_period' => 'nullable|in:FOUR_WEEKS,EIGHT_WEEKS',
            'cluster_id' => 'nullable|integer',
            'attention_level' => 'nullable|in:LOW,ATTENTION_MEDIUM,ATTENTION_HI',
            'limit' => 'nullable|integer|min:1|max:1000',
            'offset' => 'nullable|integer|min:0',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);

        if ($integration->marketplace !== 'ozon') {
            return response()->json([
                'message' => 'Этот endpoint только для Ozon',
            ], 422);
        }

        $marketplace = MarketplaceFactory::create(
            $integration->marketplace,
            $integration->getDecryptedCredentials(),
            $integration
        );
        $client = $marketplace->api();

        // Получаем список кластеров с названиями
        $clusterList = $client->post('/v1/cluster/list', ['cluster_type' => 'CLUSTER_TYPE_OZON']);
        $clusterNames = [];
        foreach ($clusterList['clusters'] ?? [] as $c) {
            $clusterNames[$c['id']] = $c['name'] ?? "Кластер {$c['id']}";
        }

        $deliverySchema = $validated['delivery_schema'] ?? 'ALL';
        $supplyPeriod = $validated['supply_period'] ?? 'EIGHT_WEEKS';
        $limit = $validated['limit'] ?? 1000;
        $offset = $validated['offset'] ?? 0;

        // Получаем общую аналитику для списка кластеров доставки
        $overallResponse = $client->post('/v1/analytics/average-delivery-time', [
            'filters' => [
                'delivery_schema' => $deliverySchema,
                'supply_period' => $supplyPeriod,
            ]
        ]);

        $total = $overallResponse['total'] ?? [];
        $overallData = $overallResponse['data'] ?? [];

        // Собираем ID кластеров доставки
        $deliveryClusterIds = [];
        if (!empty($validated['cluster_id'])) {
            $deliveryClusterIds = [$validated['cluster_id']];
        } else {
            foreach ($overallData as $item) {
                $clusterId = $item['delivery_cluster_id'] ?? null;
                if ($clusterId) {
                    $deliveryClusterIds[] = $clusterId;
                }
            }
            $deliveryClusterIds = array_unique($deliveryClusterIds);
        }

        // Собираем данные по товарам из всех кластеров
        $productsByKey = [];
        
        foreach ($deliveryClusterIds as $clusterId) {
            $detailsResponse = $client->post('/v1/analytics/average-delivery-time/details', [
                'cluster_id' => $clusterId,
                'limit' => $limit,
                'offset' => $offset,
                'filters' => [
                    'delivery_schema' => $deliverySchema,
                    'supply_period' => $supplyPeriod,
                ],
            ]);

            foreach ($detailsResponse['data'] ?? [] as $item) {
                $itemData = $item['item'] ?? [];
                $metrics = $item['metrics'] ?? [];
                $ordersCount = $metrics['orders_count'] ?? [];
                
                $sku = $itemData['offer_id'] ?? null;
                $productId = $itemData['sku'] ?? null;
                
                if (!$sku) continue;

                // Ключ: кластер доставки + SKU (как на скриншоте Ozon)
                $key = "{$clusterId}_{$sku}";
                
                if (!isset($productsByKey[$key])) {
                    $productsByKey[$key] = [
                        'delivery_cluster_id' => $clusterId,
                        'delivery_cluster_name' => $clusterNames[$clusterId] ?? "Кластер {$clusterId}",
                        'sku' => $sku,
                        'product_id' => $productId,
                        'name' => $itemData['name'] ?? null,
                        'delivery_schema' => $itemData['delivery_schema'] ?? null,
                        'average_delivery_time_hours' => $metrics['average_delivery_time'] ?? 0,
                        'average_delivery_time_status' => $metrics['average_delivery_time_status'] ?? 'UNKNOWN',
                        'impact_share' => $metrics['impact_share'] ?? 0,
                        'exact_impact_share' => (float) ($metrics['exact_impact_share'] ?? 0),
                        'lost_profit' => $metrics['lost_profit'] ?? 0,
                        'recommended_supply' => $metrics['recommended_supply'] ?? 0,
                        'attention_level' => $metrics['attention_level'] ?? 'LOW',
                        'orders' => [
                            'total' => $ordersCount['total'] ?? 0,
                            'long' => [
                                'value' => $ordersCount['long']['value'] ?? 0,
                                'percent' => $ordersCount['long']['percent'] ?? 0,
                            ],
                            'medium' => [
                                'value' => $ordersCount['medium']['value'] ?? 0,
                                'percent' => $ordersCount['medium']['percent'] ?? 0,
                            ],
                            'fast' => [
                                'value' => $ordersCount['fast']['value'] ?? 0,
                                'percent' => $ordersCount['fast']['percent'] ?? 0,
                            ],
                        ],
                    ];
                }
            }
        }

        $products = array_values($productsByKey);

        // Фильтруем по уровню внимания если указан
        if (!empty($validated['attention_level'])) {
            $products = array_filter(
                $products,
                fn($p) => $p['attention_level'] === $validated['attention_level']
            );
            $products = array_values($products);
        }

        // Сортируем по доле влияния (убывание)
        usort($products, fn($a, $b) => $b['exact_impact_share'] <=> $a['exact_impact_share']);

        // Форматируем итого
        $totalOrders = $total['orders_count'] ?? [];
        $summary = [
            'average_delivery_time_hours' => $total['average_delivery_time'] ?? 0,
            'average_delivery_time_status' => $total['average_delivery_time_status'] ?? 'UNKNOWN',
            'impact_share' => 100,
            'lost_profit' => $total['lost_profit'] ?? 0,
            'recommended_supply' => $total['recommended_supply'] ?? 0,
            'attention_level' => $total['attention_level'] ?? 'LOW',
            'orders' => [
                'total' => $totalOrders['total'] ?? 0,
                'long' => [
                    'value' => $totalOrders['long']['value'] ?? 0,
                    'percent' => $totalOrders['long']['percent'] ?? 0,
                ],
                'medium' => [
                    'value' => $totalOrders['medium']['value'] ?? 0,
                    'percent' => $totalOrders['medium']['percent'] ?? 0,
                ],
                'fast' => [
                    'value' => $totalOrders['fast']['value'] ?? 0,
                    'percent' => $totalOrders['fast']['percent'] ?? 0,
                ],
            ],
        ];

        // Статистика
        $stats = [
            'total_products' => count($products),
            'by_attention' => [
                'high' => count(array_filter($products, fn($p) => $p['attention_level'] === 'ATTENTION_HI')),
                'medium' => count(array_filter($products, fn($p) => $p['attention_level'] === 'ATTENTION_MEDIUM')),
                'low' => count(array_filter($products, fn($p) => $p['attention_level'] === 'LOW')),
            ],
            'total_lost_profit' => array_sum(array_column($products, 'lost_profit')),
            'total_recommended_supply' => array_sum(array_column($products, 'recommended_supply')),
        ];

        return response()->json([
            'message' => 'Success',
            'summary' => $summary,
            'products' => $products,
            'stats' => $stats,
            'cluster_names' => $clusterNames,
        ]);
    }

    /**
     * Получить API для аналитики доставки
     */
    private function getDeliveryAnalyticsApi(Integration $integration): DeliveryAnalyticsApi
    {
        $marketplace = MarketplaceFactory::create(
            $integration->marketplace,
            $integration->getDecryptedCredentials(),
            $integration
        );

        return new DeliveryAnalyticsApi($marketplace->api());
    }
}
