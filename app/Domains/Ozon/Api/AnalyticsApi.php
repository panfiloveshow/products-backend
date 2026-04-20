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
     * Проверка Premium статуса аккаунта
     * Premium аккаунты имеют доступ к расширенной аналитике
     */
    public function checkPremiumStatus(): array
    {
        try {
            $response = $this->client->post('/v1/analytics/data', [
                'date_from' => now()->subDays(7)->format('Y-m-d'),
                'date_to' => now()->format('Y-m-d'),
                'metrics' => ['ordered_units', 'delivered_units', 'returns', 'cancellations'],
                'dimension' => ['sku'],
                'filters' => [],
                'limit' => 1,
                'offset' => 0,
            ]);

            $rows = $response['result']['data'] ?? [];
            
            if (empty($rows)) {
                return [
                    'is_premium' => null,
                    'available_metrics' => ['ordered_units'],
                    'reason' => 'No data to determine premium status',
                ];
            }

            $metrics = $rows[0]['metrics'] ?? [];
            $metricsCount = count($metrics);
            $isPremium = $metricsCount >= 4;

            // Дополнительная проверка
            if ($isPremium && $metricsCount >= 4) {
                $orderedUnits = (int)($metrics[0] ?? 0);
                $deliveredUnits = (int)($metrics[1] ?? 0);
                $returns = (int)($metrics[2] ?? 0);
                
                if ($orderedUnits > 100 && $deliveredUnits === 0 && $returns === 0) {
                    $isPremium = false;
                }
            }

            return [
                'is_premium' => $isPremium,
                'available_metrics' => $isPremium 
                    ? ['ordered_units', 'delivered_units', 'returns', 'cancellations']
                    : ['ordered_units'],
                'reason' => $isPremium 
                    ? 'Full analytics access (Premium)' 
                    : 'Limited analytics access',
            ];
        } catch (\Exception $e) {
            Log::error('Ozon checkPremiumStatus error', ['error' => $e->getMessage()]);
            return [
                'is_premium' => false,
                'available_metrics' => [],
                'reason' => 'Error: ' . $e->getMessage(),
            ];
        }
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
        $dateFrom = $dateFrom ?? now()->subDays(30)->format('Y-m-d');
        $dateTo = $dateTo ?? now()->format('Y-m-d');

        try {
            $response = $this->client->post('/v1/analytics/data', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'metrics' => ['ordered_units', 'delivered_units', 'returns', 'cancellations'],
                'dimension' => ['sku'],
                'filters' => [],
                'sort' => [['key' => 'ordered_units', 'order' => 'DESC']],
                'limit' => 1000,
                'offset' => 0,
            ]);

            $result = [];
            $rawData = $response['result']['data'] ?? [];
            
            // Логируем первые 3 записи для диагностики формата
            if (!empty($rawData)) {
                $sampleRows = array_slice($rawData, 0, 3);
                Log::info('Ozon getRedemptionRateFromAnalytics sample data', [
                    'sample_rows' => $sampleRows,
                    'map_keys_sample' => array_slice(array_keys($productIdToSkuMap), 0, 5),
                ]);
            }
            
            foreach ($rawData as $row) {
                // API возвращает SKU в dimensions[0]['id'] (числовой ID товара в Ozon)
                $ozonSku = $row['dimensions'][0]['id'] ?? null;
                if (!$ozonSku) continue;

                $ordered = (int)($row['metrics'][0] ?? 0);
                $delivered = (int)($row['metrics'][1] ?? 0);
                $returns = (int)($row['metrics'][2] ?? 0);
                $cancellations = (int)($row['metrics'][3] ?? 0);

                // % выкупа = (ordered − cancellations − returns) / ordered × 100
                // Ozon в своём отчёте «redemptions_report» считает ровно так же —
                // вычитает и отмены, и возвраты (колонка N «Сумма отмен и возвратов»).
                // Раньше код учитывал только cancellations — из-за этого наш выкуп
                // был завышен на ~процент возвратов (для одежды/обуви это 10–15%).
                $redemptionRate = 100;
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
                    'source' => 'api',
                    'has_full_data' => ($delivered + $returns) > 0 || $ordered > 0,
                ];

                // Сохраняем по ozon_sku (числовой ID)
                $result[(string)$ozonSku] = $data;
                
                // Если есть маппинг ozon_sku -> offer_id, также сохраняем по offer_id (SKU продавца)
                if (isset($productIdToSkuMap[(string)$ozonSku])) {
                    $offerSku = $productIdToSkuMap[(string)$ozonSku];
                    $result[$offerSku] = $data;
                }
            }

            Log::info('Ozon getRedemptionRateFromAnalytics success', [
                'count' => count($result),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'result_keys_sample' => array_slice(array_keys($result), 0, 10),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getRedemptionRateFromAnalytics error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить эквайринг по SKU
     */
    public function getAcquiringBySku(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $dateFrom = $dateFrom ?? now()->subDays(30)->format('Y-m-d');
        $dateTo = $dateTo ?? now()->format('Y-m-d');

        try {
            $response = $this->client->post('/v1/finance/realization', [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            $result = [];
            foreach ($response['result']['rows'] ?? [] as $row) {
                $sku = $row['offer_id'] ?? null;
                if (!$sku) continue;

                $result[$sku] = [
                    'acquiring_fee' => (float)($row['acquiring_fee'] ?? 0),
                    'sale_commission' => (float)($row['sale_commission'] ?? 0),
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Ozon getAcquiringBySku error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Получить индекс локализации (среднее время доставки)
     * С апреля 2025 Ozon заменил индекс локализации на среднее время доставки
     * API: POST /v1/analytics/average-delivery-time/summary
     * 
     * @return array ['average_delivery_time' => int, 'tariff_coefficient' => float, 'additional_fee_percent' => float]
     */
    public function getLocalizationIndex(): array
    {
        try {
            // API для среднего времени доставки (требует пустой JSON объект {})
            $response = $this->client->post('/v1/analytics/average-delivery-time/summary', [], true);
            
            if ($response && isset($response['average_delivery_time'])) {
                $avgTime = (int) $response['average_delivery_time'];
                $tariff = $response['current_tariff'] ?? [];
                
                // tariff_value — коэффициент в % (например 16 = 16% = 1.16x)
                // Преобразуем в множитель: 16% -> 1.16
                $tariffValue = $tariff['tariff_value'] ?? null;
                $coefficient = $tariffValue !== null ? (1 + $tariffValue / 100) : $this->calculateDeliveryCoefficient($avgTime);
                
                // fee — дополнительный % от цены товара
                $additionalPercent = $tariff['fee'] ?? $this->calculateAdditionalPercent($avgTime);
                
                Log::info('Ozon localization index fetched from API', [
                    'average_delivery_time' => $avgTime,
                    'tariff_value_percent' => $tariffValue,
                    'coefficient' => $coefficient,
                    'fee_percent' => $additionalPercent,
                    'tariff_status' => $tariff['tariff_status'] ?? 'UNKNOWN',
                ]);
                
                return [
                    'average_delivery_time' => $avgTime,
                    'tariff_coefficient' => round($coefficient, 2),
                    'additional_fee_percent' => round($additionalPercent, 2),
                    'tariff_status' => $tariff['tariff_status'] ?? 'ACTIVE',
                ];
            }
            
            // Fallback: возвращаем дефолтные значения
            Log::info('Ozon localization index API returned no data, using defaults');
            return $this->getDefaultLocalizationIndex();
        } catch (\Exception $e) {
            Log::warning('Ozon getLocalizationIndex error, using defaults', ['error' => $e->getMessage()]);
            return $this->getDefaultLocalizationIndex();
        }
    }
    
    /**
     * Рассчитать коэффициент по времени доставки (таблица Ozon декабрь 2025)
     */
    private function calculateDeliveryCoefficient(int $hours): float
    {
        // Таблица коэффициентов Ozon FBO (декабрь 2025)
        // https://seller-edu.ozon.ru/docs/fbo/tarify-fbo.html
        return match (true) {
            $hours <= 24 => 1.0,    // До 24 часов
            $hours <= 36 => 1.2,    // 24-36 часов
            $hours <= 48 => 1.4,    // 36-48 часов
            $hours <= 72 => 1.6,    // 48-72 часов
            default => 1.8,         // Более 72 часов
        };
    }
    
    /**
     * Рассчитать дополнительный % от цены по времени доставки
     */
    private function calculateAdditionalPercent(int $hours): float
    {
        // Дополнительный % для дорогих товаров при долгой доставке
        return match (true) {
            $hours <= 36 => 0,
            $hours <= 48 => 1.0,
            default => 2.0,
        };
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
