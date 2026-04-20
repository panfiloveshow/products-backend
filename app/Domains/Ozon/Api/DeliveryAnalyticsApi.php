<?php

namespace App\Domains\Ozon\Api;

/**
 * API для аналитики доставки Ozon
 * 
 * Endpoints:
 * - POST /v1/analytics/average-delivery-time/details — детальная аналитика по времени доставки
 * - POST /v1/analytics/average-delivery-time — общая аналитика по времени доставки
 * 
 * Данные соответствуют разделу "Аналитика → География продаж → Среднее время доставки"
 * в личном кабинете продавца Ozon
 * 
 * @see https://docs.ozon.ru/api/seller
 */
class DeliveryAnalyticsApi
{
    /**
     * Периоды поставки
     */
    public const SUPPLY_PERIOD_FOUR_WEEKS = 'FOUR_WEEKS';
    public const SUPPLY_PERIOD_EIGHT_WEEKS = 'EIGHT_WEEKS';
    
    /**
     * Схемы доставки
     */
    public const DELIVERY_SCHEMA_ALL = 'ALL';
    public const DELIVERY_SCHEMA_FBO = 'FBO';
    public const DELIVERY_SCHEMA_FBS = 'FBS';
    
    /**
     * Уровни внимания
     */
    public const ATTENTION_LOW = 'LOW';
    public const ATTENTION_MEDIUM = 'ATTENTION_MEDIUM';
    public const ATTENTION_HIGH = 'ATTENTION_HI';

    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получить детальную аналитику по времени доставки
     * 
     * POST /v1/analytics/average-delivery-time/details
     * 
     * Возвращает данные по каждому товару с разбивкой по кластерам:
     * - Среднее время доставки
     * - Рекомендуемая поставка
     * - Заказы (быстро/средне/долго)
     * - Доля влияния на общий показатель
     * - Упущенная прибыль
     * 
     * @param int $clusterId ID кластера
     * @param array $filters [
     *   'delivery_schema' => 'ALL'|'FBO'|'FBS',
     *   'supply_period' => 'FOUR_WEEKS'|'EIGHT_WEEKS',
     * ]
     * @param int $limit Максимум записей (до 1000)
     * @param int $offset Смещение
     */
    public function getDeliveryTimeDetails(
        int $clusterId,
        array $filters = [],
        int $limit = 1000,
        int $offset = 0
    ): array {
        $body = [
            'cluster_id' => $clusterId,
            'limit' => min($limit, 1000),
            'offset' => $offset,
        ];

        if (!empty($filters)) {
            $body['filters'] = [
                'delivery_schema' => $filters['delivery_schema'] ?? self::DELIVERY_SCHEMA_ALL,
                'supply_period' => $filters['supply_period'] ?? self::SUPPLY_PERIOD_EIGHT_WEEKS,
            ];
        }

        $response = $this->client->post('/v1/analytics/average-delivery-time/details', $body);

        if (!$response) {
            return ['data' => [], 'total_rows' => 0];
        }

        return [
            'data' => array_map(
                fn($item) => $this->mapDeliveryTimeItem($item),
                $response['data'] ?? []
            ),
            'total_rows' => $response['total_rows'] ?? 0,
        ];
    }

    /**
     * Получить общую аналитику по времени доставки
     * 
     * POST /v1/analytics/average-delivery-time
     * 
     * Возвращает агрегированные данные по всем кластерам
     */
    public function getDeliveryTimeOverall(array $filters = []): array
    {
        $body = [];

        if (!empty($filters)) {
            $body['filters'] = [
                'delivery_schema' => $filters['delivery_schema'] ?? self::DELIVERY_SCHEMA_ALL,
                'supply_period' => $filters['supply_period'] ?? self::SUPPLY_PERIOD_EIGHT_WEEKS,
            ];
        }

        $response = $this->client->post('/v1/analytics/average-delivery-time', $body);

        if (!$response) {
            return ['data' => [], 'total' => null];
        }

        return [
            'data' => array_map(
                fn($item) => $this->mapClusterData($item),
                $response['data'] ?? []
            ),
            'total' => $this->mapTotalMetrics($response['total'] ?? []),
        ];
    }

    /**
     * Получить рекомендации по поставкам для всех товаров
     * 
     * Агрегирует данные из getDeliveryTimeDetails для всех кластеров
     * 
     * @param array $clusterIds Список ID кластеров (если пусто — все доступные)
     * @param string $deliverySchema Схема доставки
     * @param string $supplyPeriod Период поставки
     */
    public function getSupplyRecommendations(
        array $clusterIds = [],
        string $deliverySchema = self::DELIVERY_SCHEMA_ALL,
        string $supplyPeriod = self::SUPPLY_PERIOD_EIGHT_WEEKS
    ): array {
        // Если кластеры не указаны, получаем общую аналитику для списка кластеров
        if (empty($clusterIds)) {
            $overall = $this->getDeliveryTimeOverall([
                'delivery_schema' => $deliverySchema,
                'supply_period' => $supplyPeriod,
            ]);
            
            $clusterIds = array_unique(array_map(
                fn($item) => $item['delivery_cluster_id'] ?? null,
                $overall['data'] ?? []
            ));
            $clusterIds = array_filter($clusterIds);
        }

        $allRecommendations = [];
        
        foreach ($clusterIds as $clusterId) {
            $details = $this->getDeliveryTimeDetails(
                $clusterId,
                [
                    'delivery_schema' => $deliverySchema,
                    'supply_period' => $supplyPeriod,
                ],
                1000
            );

            foreach ($details['data'] as $item) {
                $sku = $item['offer_id'];
                
                if (!isset($allRecommendations[$sku])) {
                    $allRecommendations[$sku] = [
                        'sku' => $sku,
                        'product_id' => $item['sku'],
                        'name' => $item['name'],
                        'delivery_schema' => $item['delivery_schema'],
                        'average_delivery_time' => $item['average_delivery_time'],
                        'average_delivery_time_status' => $item['average_delivery_time_status'],
                        'recommended_supply' => $item['recommended_supply'],
                        'orders_total' => $item['orders_total'],
                        'orders_fast' => $item['orders_fast'],
                        'orders_fast_percent' => $item['orders_fast_percent'],
                        'orders_medium' => $item['orders_medium'],
                        'orders_medium_percent' => $item['orders_medium_percent'],
                        'orders_long' => $item['orders_long'],
                        'orders_long_percent' => $item['orders_long_percent'],
                        'impact_share' => $item['impact_share'],
                        'attention_level' => $item['attention_level'],
                        'lost_profit' => $item['lost_profit'],
                        'clusters' => [],
                    ];
                }

                // Добавляем данные по кластеру
                foreach ($item['clusters_data'] as $cluster) {
                    $clusterKey = (string) ($cluster['cluster_id'] ?? '');
                    if ($clusterKey === '') {
                        continue;
                    }

                    if (! isset($allRecommendations[$sku]['clusters'][$clusterKey])) {
                        $allRecommendations[$sku]['clusters'][$clusterKey] = $cluster;
                        continue;
                    }

                    $existing = $allRecommendations[$sku]['clusters'][$clusterKey];
                    $allRecommendations[$sku]['clusters'][$clusterKey] = [
                        ...$existing,
                        'orders_count' => (int) ($existing['orders_count'] ?? 0) + (int) ($cluster['orders_count'] ?? 0),
                        'sales_quantity' => (int) ($existing['sales_quantity'] ?? 0) + (int) ($cluster['sales_quantity'] ?? 0),
                        'sales_amount' => (float) ($existing['sales_amount'] ?? 0) + (float) ($cluster['sales_amount'] ?? 0),
                    ];
                }
            }
        }

        foreach ($allRecommendations as $sku => $recommendation) {
            $clusters = array_values($recommendation['clusters'] ?? []);
            $totalOrders = array_sum(array_map(
                static fn (array $cluster): int => (int) ($cluster['orders_count'] ?? 0),
                $clusters
            ));

            if ($totalOrders > 0) {
                foreach ($clusters as &$cluster) {
                    $cluster['orders_percent'] = round(((int) ($cluster['orders_count'] ?? 0) / $totalOrders) * 100, 2);
                }
                unset($cluster);
            }

            usort($clusters, static function (array $left, array $right): int {
                return ((int) ($right['orders_count'] ?? 0)) <=> ((int) ($left['orders_count'] ?? 0));
            });

            $allRecommendations[$sku]['clusters'] = [];
            foreach ($clusters as $cluster) {
                $clusterKey = (string) ($cluster['cluster_id'] ?? '');
                if ($clusterKey === '') {
                    continue;
                }
                $allRecommendations[$sku]['clusters'][$clusterKey] = $cluster;
            }
        }

        // Сортируем по уровню внимания и упущенной прибыли
        uasort($allRecommendations, function ($a, $b) {
            $attentionOrder = [
                self::ATTENTION_HIGH => 0,
                self::ATTENTION_MEDIUM => 1,
                self::ATTENTION_LOW => 2,
            ];
            
            $aOrder = $attentionOrder[$a['attention_level']] ?? 3;
            $bOrder = $attentionOrder[$b['attention_level']] ?? 3;
            
            if ($aOrder !== $bOrder) {
                return $aOrder - $bOrder;
            }
            
            return ($b['lost_profit'] ?? 0) - ($a['lost_profit'] ?? 0);
        });

        return array_values($allRecommendations);
    }

    /**
     * Получить список кластеров с их характеристиками
     */
    public function getClusters(): array
    {
        $overall = $this->getDeliveryTimeOverall();
        
        $clusters = [];
        foreach ($overall['data'] as $item) {
            foreach ($item['clusters_data'] ?? [] as $cluster) {
                $clusterId = $cluster['cluster_id'];
                if (!isset($clusters[$clusterId])) {
                    $clusters[$clusterId] = [
                        'id' => $clusterId,
                        'delivery_time_fbo' => $cluster['delivery_time_fbo'],
                        'delivery_time_fbs' => $cluster['delivery_time_fbs'],
                        'delivery_time_status' => $cluster['delivery_time_status'],
                        'orders_count' => 0,
                        'orders_percent' => 0,
                    ];
                }
                $clusters[$clusterId]['orders_count'] += $cluster['orders_count'] ?? 0;
            }
        }

        return array_values($clusters);
    }

    /**
     * Маппинг элемента детальной аналитики
     */
    private function mapDeliveryTimeItem(array $item): array
    {
        $metrics = $item['metrics'] ?? [];
        $ordersCount = $metrics['orders_count'] ?? [];
        
        return [
            // Информация о товаре
            'sku' => $item['item']['sku'] ?? null,
            'offer_id' => $item['item']['offer_id'] ?? null,
            'name' => $item['item']['name'] ?? null,
            'delivery_schema' => $item['item']['delivery_schema'] ?? null,
            
            // Метрики
            'average_delivery_time' => $metrics['average_delivery_time'] ?? null,
            'average_delivery_time_status' => $metrics['average_delivery_time_status'] ?? null,
            'recommended_supply' => $metrics['recommended_supply'] ?? 0,
            
            // Заказы
            'orders_total' => $ordersCount['total'] ?? 0,
            'orders_fast' => $ordersCount['fast']['value'] ?? 0,
            'orders_fast_percent' => $ordersCount['fast']['percent'] ?? 0,
            'orders_medium' => $ordersCount['medium']['value'] ?? 0,
            'orders_medium_percent' => $ordersCount['medium']['percent'] ?? 0,
            'orders_long' => $ordersCount['long']['value'] ?? 0,
            'orders_long_percent' => $ordersCount['long']['percent'] ?? 0,
            
            // Влияние и прибыль
            'impact_share' => $metrics['impact_share'] ?? 0,
            'exact_impact_share' => $metrics['exact_impact_share'] ?? null,
            'attention_level' => $metrics['attention_level'] ?? self::ATTENTION_LOW,
            'lost_profit' => $metrics['lost_profit'] ?? 0,
            
            // Данные по кластерам
            'clusters_data' => array_map(
                fn($c) => $this->mapClusterItem($c),
                $item['clusters_data'] ?? []
            ),
        ];
    }

    /**
     * Маппинг данных кластера
     */
    private function mapClusterData(array $item): array
    {
        $metrics = $item['metrics'] ?? [];
        $ordersCount = $metrics['orders_count'] ?? [];
        
        return [
            'delivery_cluster_id' => $item['delivery_cluster_id'] ?? null,
            'average_delivery_time' => $metrics['average_delivery_time'] ?? null,
            'average_delivery_time_status' => $metrics['average_delivery_time_status'] ?? null,
            'recommended_supply' => $metrics['recommended_supply'] ?? 0,
            'orders_total' => $ordersCount['total'] ?? 0,
            'orders_fast' => $ordersCount['fast']['value'] ?? 0,
            'orders_fast_percent' => $ordersCount['fast']['percent'] ?? 0,
            'orders_medium' => $ordersCount['medium']['value'] ?? 0,
            'orders_medium_percent' => $ordersCount['medium']['percent'] ?? 0,
            'orders_long' => $ordersCount['long']['value'] ?? 0,
            'orders_long_percent' => $ordersCount['long']['percent'] ?? 0,
            'impact_share' => $metrics['impact_share'] ?? 0,
            'attention_level' => $metrics['attention_level'] ?? self::ATTENTION_LOW,
            'lost_profit' => $metrics['lost_profit'] ?? 0,
            'clusters_data' => array_map(
                fn($c) => $this->mapClusterItem($c),
                $item['clusters_data'] ?? []
            ),
        ];
    }

    /**
     * Маппинг элемента кластера
     */
    private function mapClusterItem(array $cluster): array
    {
        return [
            'cluster_id' => $cluster['cluster_id'] ?? null,
            'delivery_time_fbo' => $cluster['delivery_time_FBO'] ?? null,
            'delivery_time_fbs' => $cluster['delivery_time_FBS'] ?? null,
            'delivery_time_status' => $cluster['delivery_time_status'] ?? null,
            'orders_count' => $cluster['orders_count'] ?? 0,
            'orders_percent' => $cluster['orders_percent'] ?? 0,
            'another_delivery_time' => $cluster['another_delivery_time'] ?? [],
            // Добавляем поля продаж для фронтенда
            'sales_quantity' => $cluster['orders_count'] ?? 0, // Количество проданных штук
            'sales_amount' => $cluster['sales_amount'] ?? 0,   // Сумма продаж (если есть в API)
            'sales_percent' => $cluster['sales_percent'] ?? 0, // Процент от общей выручки (если есть в API)
        ];
    }

    /**
     * Маппинг общих метрик
     */
    private function mapTotalMetrics(array $total): ?array
    {
        if (empty($total)) {
            return null;
        }

        $ordersCount = $total['orders_count'] ?? [];
        
        return [
            'average_delivery_time' => $total['average_delivery_time'] ?? null,
            'average_delivery_time_status' => $total['average_delivery_time_status'] ?? null,
            'recommended_supply' => $total['recommended_supply'] ?? 0,
            'orders_total' => $ordersCount['total'] ?? 0,
            'orders_fast' => $ordersCount['fast']['value'] ?? 0,
            'orders_fast_percent' => $ordersCount['fast']['percent'] ?? 0,
            'orders_medium' => $ordersCount['medium']['value'] ?? 0,
            'orders_medium_percent' => $ordersCount['medium']['percent'] ?? 0,
            'orders_long' => $ordersCount['long']['value'] ?? 0,
            'orders_long_percent' => $ordersCount['long']['percent'] ?? 0,
            'impact_share' => $total['impact_share'] ?? 100,
            'attention_level' => $total['attention_level'] ?? self::ATTENTION_LOW,
            'lost_profit' => $total['lost_profit'] ?? 0,
        ];
    }
}
