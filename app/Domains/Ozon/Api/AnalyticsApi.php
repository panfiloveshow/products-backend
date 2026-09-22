<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с аналитикой Ozon (включая Premium)
 */
class AnalyticsApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Проверка Premium статуса аккаунта: премиум-метрики /v1/analytics/data
     * без Premium Plus/Pro Ozon отклоняет (400/403). Итог кэшируется на сутки
     * по Client-Id (AnalyticsDataClient), отказ повторно не проверяем.
     */
    public function checkPremiumStatus(): array
    {
        $premiumMetrics = ['ordered_units', 'delivered_units', 'returns', 'cancellations'];
        $analytics = new AnalyticsDataClient($this->client);

        if ($analytics->premiumKnown() === false) {
            return [
                'is_premium' => false,
                'available_metrics' => AnalyticsDataClient::BASIC_METRICS,
                'reason' => 'Premium-метрики отклонены Ozon (кэш на сутки)',
            ];
        }

        $report = $analytics->fetch([
            'date_from' => now()->subDays(7)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
            'metrics' => $premiumMetrics,
            'dimension' => ['sku'],
            'filters' => [],
            'limit' => 1,
            'offset' => 0,
        ], 1, true, false);

        if ($report['status'] === 'premium_required') {
            return [
                'is_premium' => false,
                'available_metrics' => AnalyticsDataClient::BASIC_METRICS,
                'reason' => 'Limited analytics access',
            ];
        }
        if ($report['status'] !== 'ok') {
            // 429/сбой — статус неизвестен, вызывающий оставит сохранённый.
            return [
                'is_premium' => null,
                'available_metrics' => [],
                'reason' => 'Analytics unavailable: '.$report['status'],
            ];
        }

        $row = $report['rows'][0]['metrics'] ?? null;
        if ($row === null) {
            return [
                'is_premium' => null,
                'available_metrics' => ['ordered_units'],
                'reason' => 'No data to determine premium status',
            ];
        }

        // Бывало, что без подписки Ozon отдавал нули вместо отказа.
        $isPremium = ! ($row['ordered_units'] > 100 && $row['delivered_units'] == 0 && $row['returns'] == 0);
        $analytics->rememberPremium($isPremium);

        return [
            'is_premium' => $isPremium,
            'available_metrics' => $isPremium ? $premiumMetrics : AnalyticsDataClient::BASIC_METRICS,
            'reason' => $isPremium ? 'Full analytics access (Premium)' : 'Limited analytics access',
        ];
    }

    /**
     * Получить % выкупа из аналитики (для Premium)
     */
    /**
     * Получить % выкупа из API аналитики
     * 
     * @param array $productIdToSkuMap Маппинг product_id -> SKU (опционально)
     * @return array Данные по SKU или product_id
     */
    public function getRedemptionRateFromAnalytics(?string $dateFrom = null, ?string $dateTo = null, array $productIdToSkuMap = []): array
    {
        // Окно D-28…D-1 — совпадает с виджетом Ozon «Выкупы по товару».
        $defaultDateTo = now()->subDays(1)->toDateString();
        $defaultDateFrom = now()->subDays(28)->toDateString();
        $dateFrom = $dateFrom ?? $defaultDateFrom;
        $dateTo = $dateTo ?? $defaultDateTo;

        $result = [];
        $pageSize = 1000;
        $maxPages = 50; // hard-cap: 50 000 SKU — страхует от зависания при кривом ответе API

        // Premium-метрики (delivered_units/returns/cancellations) доступны только Premium-продавцам;
        // у не-Premium Ozon отклоняет весь запрос. Вызывающий зовёт метод только при
        // сохранённом Premium; отдельную пробу не делаем (лимит 1 запрос/мин, 50/сутки).
        $analytics = new AnalyticsDataClient($this->client);
        if ($analytics->premiumKnown() === false) {
            Log::info('Ozon getRedemptionRateFromAnalytics: не-Premium — analytics-выкуп пропущен (fallback на другой источник)');

            return [];
        }

        try {
            $report = $analytics->fetch([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'metrics' => ['ordered_units', 'delivered_units', 'returns', 'cancellations'],
                'dimension' => ['sku'],
                'filters' => [],
                'sort' => [['key' => 'ordered_units', 'order' => 'DESC']],
                'limit' => $pageSize,
                'offset' => 0,
            ], $maxPages, true, false);

            if ($report['status'] === 'premium_required') {
                return [];
            }

            if ($report['rows'] !== []) {
                Log::info('Ozon getRedemptionRateFromAnalytics sample data', [
                    'sample_rows' => array_slice($report['rows'], 0, 3),
                    'map_keys_sample' => array_slice(array_keys($productIdToSkuMap), 0, 5),
                ]);
            }

            foreach ($report['rows'] as $row) {
                $ozonSku = $row['dimensions'][0]['id'] ?? null;
                if (!$ozonSku) continue;

                $ordered = (int) ($row['metrics']['ordered_units'] ?? 0);
                $delivered = (int) ($row['metrics']['delivered_units'] ?? 0);
                $returns = (int) ($row['metrics']['returns'] ?? 0);
                $cancellations = (int) ($row['metrics']['cancellations'] ?? 0);

                // Выкуп в Ozon Seller считаем как:
                // (ordered - cancellations - returns) / ordered.
                // delivered_units из analytics API часто не совпадает с блоком
                // "Выкуплено товаров" в ЛК, поэтому для buyout не используем его напрямую.
                $redemptionRate = 100;
                $notRedeemed = 0;
                if ($ordered > 0) {
                    $notRedeemed = min($ordered, max(0, $cancellations) + max(0, $returns));
                    $redemptionRate = round((($ordered - $notRedeemed) / $ordered) * 100, 2);
                }

                $data = [
                    'ozon_sku' => $ozonSku,
                    'ordered_units' => $ordered,
                    'delivered_units' => $delivered,
                    'returns' => $returns,
                    'cancellations' => $cancellations,
                    'redemption_rate' => $redemptionRate,
                    'orders_count' => $ordered,
                    'returns_count' => $returns,
                    // delivered_count в нашем API = "выкуплено" (как в виджете Ozon),
                    // а сырой delivered_units сохраняем отдельно для отладки.
                    'delivered_count' => max(0, $ordered - $notRedeemed),
                    'delivered_units_raw' => $delivered,
                    'cancelled_count' => $cancellations,
                    'cancellations_count' => $cancellations,
                    'source' => 'api',
                    'has_full_data' => ($delivered + $returns) > 0 || $ordered > 0,
                ];

                $result[(string)$ozonSku] = $data;

                if (isset($productIdToSkuMap[(string)$ozonSku])) {
                    $offerSku = $productIdToSkuMap[(string)$ozonSku];
                    $result[$offerSku] = $data;
                }
            }

            Log::info('Ozon getRedemptionRateFromAnalytics success', [
                'count' => count($result),
                'status' => $report['status'],
                'rows' => count($report['rows']),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'result_keys_sample' => array_slice(array_keys($result), 0, 10),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getRedemptionRateFromAnalytics error', [
                'error' => $e->getMessage(),
                'partial_count' => count($result),
            ]);
            return $result;
        }
    }

    /**
     * Индекс локальности продаж и переплата за нелокальную логистику.
     * API: POST /v1/analytics/local-sale/total (бета, «Локальность продаж»).
     *
     * /v1/analytics/average-delivery-time/summary Ozon удалил 19.05.2026. В local-sale нет
     * среднего времени доставки, коэффициента и доп. % тарифа — для них остаются дефолты
     * (tariff_status = UNKNOWN), вместо них отдаём индекс локальности и переплату (в рублях).
     *
     * @return array{average_delivery_time:int, tariff_coefficient:float, additional_fee_percent:float|int,
     *   tariff_status:string, local_sales_index:?float, local_quantity:?int, total_quantity:?int,
     *   overpayment_total:?float, overpayment_non_local:?float}
     */
    public function getLocalizationIndex(): array
    {
        $index = $this->getDefaultLocalizationIndex() + [
            'local_sales_index' => null,
            'local_quantity' => null,
            'total_quantity' => null,
            'overpayment_total' => null,
            'overpayment_non_local' => null,
        ];

        $response = $this->client->post('/v1/analytics/local-sale/total', [
            'period' => [
                'from' => now()->subDays(28)->toDateString(),
                'to' => now()->subDay()->toDateString(),
            ],
        ]);

        if (! is_array($response) || ! empty($response['_error']) || ! isset($response['local_data']['index'])) {
            Log::warning('Ozon local-sale/total: индекс локальности не получен, остаются дефолты', [
                'http_status' => $response['_http_status'] ?? null,
            ]);

            return $index;
        }

        $index = array_merge($index, [
            'local_sales_index' => round((float) $response['local_data']['index'], 2),
            'local_quantity' => (int) ($response['local_data']['local_quantity'] ?? 0),
            'total_quantity' => (int) ($response['local_data']['total_quantity'] ?? 0),
            'overpayment_total' => round((float) ($response['overpayment']['total'] ?? 0), 2),
            'overpayment_non_local' => round((float) ($response['overpayment']['non_local_delivery'] ?? 0), 2),
        ]);

        Log::info('Ozon local sales index fetched from API', $index);

        return $index;
    }

    /**
     * Дефолтные значения индекса локализации
     */
    private function getDefaultLocalizationIndex(): array
    {
        return [
            'average_delivery_time' => 29,
            'tariff_coefficient' => 1.0,
            'additional_fee_percent' => 0,
            'tariff_status' => 'UNKNOWN',
        ];
    }
    
    /**
     * Получить рейтинги карточек (content rating) по SKU
     * Возвращает рейтинг качества заполнения карточки от 0 до 100
     * 
     * @param array $skus Массив SKU товаров
     * @return array [sku => rating]
     */
    public function getProductRatingsBySku(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }
        
        try {
            // API принимает массив SKU (строки)
            $response = $this->client->post('/v1/product/rating-by-sku', [
                'skus' => array_map('strval', $skus),
            ]);

            // Логируем полный ответ от API для отладки
            Log::info('Ozon /v1/product/rating-by-sku raw response', [
                'request_skus_count' => count($skus),
                'request_skus_sample' => array_slice($skus, 0, 3),
                'response' => $response,
            ]);

            $result = [];
            $firstFewRatings = [];
            foreach ($response['products'] ?? [] as $index => $product) {
                $sku = $product['sku'] ?? null;
                $rating = $product['rating'] ?? null;
                
                if ($sku !== null && $rating !== null) {
                    // Оставляем рейтинг как есть 0-100 (индекс качества карточки)
                    $result[(string) $sku] = round($rating, 2);
                    
                    // Логируем первые 3 товара для отладки
                    if ($index < 3) {
                        $firstFewRatings[] = [
                            'sku' => $sku,
                            'rating' => $rating,
                        ];
                    }
                }
            }
            
            Log::info('Ozon getProductRatingsBySku loaded', [
                'count' => count($result),
                'first_ratings' => $firstFewRatings,
            ]);
            
            return $result;
        } catch (\Exception $e) {
            Log::warning('Ozon getProductRatingsBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
