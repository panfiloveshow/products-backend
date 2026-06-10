<?php

namespace App\Domains\Wildberries\Api;

use App\Domains\Marketplace\Contracts\ProductsApiInterface;
use App\Models\Integration;

/**
 * API для работы с товарами Wildberries
 * 
 * Актуальные Endpoints (обновлено 2024-12):
 * 
 * Content API (content-api.wildberries.ru):
 * - POST /content/v2/get/cards/list - список карточек с пагинацией
 * 
 * Prices API (discounts-prices-api.wildberries.ru):
 * - POST /api/v2/list/goods/filter - товары с ценами по nmID
 * - GET /api/v2/list/goods/size/nm - цены по размерам
 * 
 * @see https://dev.wildberries.ru/openapi/work-with-products
 */
class ProductsApi implements ProductsApiInterface
{
    public function __construct(
        private WildberriesClient $client
    ) {}

    /**
     * Получить список товаров с полными данными (включая габариты)
     * 
     * @param Integration $integration
     * @param array $options [limit, cursor, filter, sort]
     * @return array
     */
    public function getProducts(?Integration $integration = null, array $options = []): array
    {
        $limit = $options['limit'] ?? 100;
        $cursor = $options['cursor'] ?? null;
        
        $body = [
            'settings' => [
                'cursor' => [
                    'limit' => $limit,
                ],
                'filter' => [
                    'withPhoto' => CardListWithPhotoFilter::allCards(),
                ],
            ],
        ];

        if ($cursor) {
            $body['settings']['cursor']['updatedAt'] = $cursor['updatedAt'] ?? null;
            $body['settings']['cursor']['nmID'] = $cursor['nmID'] ?? null;
        }

        $response = $this->client->contentPost('/content/v2/get/cards/list', $body);

        if (!$response) {
            return [];
        }

        $cards = $response['cards'] ?? [];
        
        // Не делаем дополнительный поиск по nmID по умолчанию: при полной пагинации
        // это превращает sync в O(pages^2) Content API calls. Включать только для
        // точечных сценариев, где старый ответ карточек реально пришёл без dimensions.
        if (! empty($cards) && ($options['enrich_dimensions'] ?? false)) {
            $nmIds = array_column($cards, 'nmID');
            $cardsWithDimensions = $this->getCardsByNmIds($nmIds);
            
            // Мержим данные
            foreach ($cards as &$card) {
                $nmId = $card['nmID'] ?? null;
                if ($nmId && isset($cardsWithDimensions[$nmId])) {
                    $fullCard = $cardsWithDimensions[$nmId];
                    $card['dimensions'] = $fullCard['dimensions'] ?? null;
                    $card['characteristics'] = $fullCard['characteristics'] ?? $card['characteristics'] ?? [];
                }
            }
        }

        return [
            'cards' => $cards,
            'cursor' => $response['cursor'] ?? null,
        ];
    }
    
    /**
     * Получить карточки по nmId с полными данными (dimensions, characteristics)
     * 
     * Используем /content/v2/get/cards/list с пагинацией и фильтрацией по nmID.
     * Эндпоинт /content/v2/cards/filter устарел и возвращает 404.
     * 
     * @param array $nmIds
     * @return array [nmId => card]
     */
    public function getCardsByNmIds(array $nmIds): array
    {
        if (empty($nmIds)) {
            return [];
        }
        
        $nmIdsSet = array_flip($nmIds);
        $result = [];
        $cursor = null;
        $maxIterations = 50;
        $iteration = 0;
        
        // Получаем все карточки через пагинацию и фильтруем по nmID
        do {
            $body = [
                'settings' => [
                    'cursor' => [
                        'limit' => 100,
                    ],
                    'filter' => [
                        'withPhoto' => CardListWithPhotoFilter::allCards(),
                    ],
                ],
            ];
            
            if ($cursor) {
                $body['settings']['cursor']['updatedAt'] = $cursor['updatedAt'] ?? null;
                $body['settings']['cursor']['nmID'] = $cursor['nmID'] ?? null;
            }
            
            $response = $this->client->contentPost('/content/v2/get/cards/list', $body);
            
            if (!$response) {
                break;
            }
            
            $cards = $response['cards'] ?? [];
            $cursor = $response['cursor'] ?? null;
            
            foreach ($cards as $card) {
                $nmId = $card['nmID'] ?? null;
                if ($nmId && isset($nmIdsSet[$nmId])) {
                    $result[$nmId] = $card;
                    unset($nmIdsSet[$nmId]);
                }
            }
            
            $iteration++;
            
            // Выходим если нашли все нужные карточки или закончились данные
            $hasMore = !empty($cards) && $cursor && isset($cursor['nmID']) && !empty($nmIdsSet);
            
        } while ($hasMore && $iteration < $maxIterations);
        
        return $result;
    }

    /**
     * Получить товар по SKU (vendorCode)
     */
    public function getProductBySku(string $sku, ?Integration $integration = null): ?array
    {
        $body = [
            'vendorCodes' => [$sku],
        ];

        $response = $this->client->contentPost('/content/v2/get/cards/list', [
            'settings' => [
                'cursor' => ['limit' => 1],
                'filter' => [
                    'withPhoto' => CardListWithPhotoFilter::allCards(),
                    'textSearch' => $sku,
                ],
            ],
        ]);

        $cards = $response['cards'] ?? [];
        
        foreach ($cards as $card) {
            if ($card['vendorCode'] === $sku) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Получить цены товаров
     * 
     * GET /api/v2/list/goods/filter (discounts-prices-api.wildberries.ru)
     * 
     * Возвращает актуальные цены, скидки, цены для клуба WB.
     * Цены находятся в массиве sizes для каждого товара.
     * 
     * @param Integration|null $integration
     * @param array $nmIds Массив nmID для фильтрации (filterNmID - только один за раз!)
     * 
     * @see https://dev.wildberries.ru/openapi/work-with-products
     */
    public function getPrices(?Integration $integration = null, array $nmIds = []): array
    {
        // Если nmIds переданы, запрашиваем по одному (API ограничение filterNmID)
        // Но лучше получить все и отфильтровать
        return $this->getAllPrices($nmIds);
    }
    
    /**
     * Получить все цены с пагинацией
     * 
     * GET /api/v2/list/goods/filter (discounts-prices-api.wildberries.ru)
     * 
     * Response structure:
     * {
     *   "data": {
     *     "listGoods": [{
     *       "nmID": 98486,
     *       "vendorCode": "07326060",
     *       "sizes": [{
     *         "sizeID": 3123515574,
     *         "price": 500,
     *         "discountedPrice": 350,
     *         "clubDiscountedPrice": 332.5,
     *         "techSizeName": "42"
     *       }],
     *       "currencyIsoCode4217": "RUB",
     *       "discount": 30,
     *       "clubDiscount": 5,
     *       "editableSizePrice": true,
     *       "isBadTurnover": true
     *     }]
     *   }
     * }
     */
    private function getAllPrices(array $filterNmIds = []): array
    {
        $prices = [];
        $offset = 0;
        $limit = 1000;
        $maxIterations = 200;
        $iteration = 0;

        \Illuminate\Support\Facades\Log::info('WB ProductsApi: Requesting prices from Prices API', [
            'endpoint' => '/api/v2/list/goods/filter',
            'filterNmIds' => count($filterNmIds),
        ]);

        do {
            $response = $this->client->pricesGet('/api/v2/list/goods/filter', [
                'limit'  => $limit,
                'offset' => $offset,
            ]);

            if (!$response) {
                \Illuminate\Support\Facades\Log::warning('WB ProductsApi: Prices API returned null', [
                    'offset' => $offset,
                ]);
                break;
            }

            if (!isset($response['data']['listGoods'])) {
                \Illuminate\Support\Facades\Log::warning('WB ProductsApi: Prices API response missing listGoods', [
                    'response_keys' => array_keys($response),
                    'error'         => $response['error'] ?? null,
                    'errorText'     => $response['errorText'] ?? null,
                ]);
                break;
            }

            $items = $response['data']['listGoods'];

            if ($iteration === 0) {
                \Illuminate\Support\Facades\Log::info('WB ProductsApi: Prices API first response', [
                    'count'  => count($items),
                    'sample' => array_slice($items, 0, 2),
                ]);
            }

            foreach ($items as $item) {
                $nmId       = $item['nmID'] ?? null;
                $vendorCode = $item['vendorCode'] ?? null;

                if (!$nmId) continue;

                $sizes       = $item['sizes'] ?? [];
                $firstSize   = $sizes[0] ?? [];

                $basePrice       = (float) ($firstSize['price'] ?? 0);
                $discountedPrice = (float) ($firstSize['discountedPrice'] ?? 0);
                $clubPrice       = (float) ($firstSize['clubDiscountedPrice'] ?? 0);

                $priceData = [
                    'nmID'               => $nmId,
                    'vendorCode'         => $vendorCode,
                    'price'              => $basePrice,
                    'discount'           => (int) ($item['discount'] ?? 0),
                    'discounted_price'   => $discountedPrice,
                    'club_price'         => $clubPrice,
                    'club_discount'      => (int) ($item['clubDiscount'] ?? 0),
                    'final_price'        => $discountedPrice > 0 ? $discountedPrice : $basePrice,
                    'currency'           => $item['currencyIsoCode4217'] ?? 'RUB',
                    'is_bad_turnover'    => $item['isBadTurnover'] ?? false,
                    'editable_size_price'=> $item['editableSizePrice'] ?? false,
                    'sizes'              => array_map(fn($s) => [
                        'sizeID'              => $s['sizeID'] ?? null,
                        'price'               => (float) ($s['price'] ?? 0),
                        'discountedPrice'     => (float) ($s['discountedPrice'] ?? 0),
                        'clubDiscountedPrice' => (float) ($s['clubDiscountedPrice'] ?? 0),
                        'techSizeName'        => $s['techSizeName'] ?? '',
                    ], $sizes),
                ];

                // Индексируем по string(nmID) и vendorCode
                $prices[(string) $nmId] = $priceData;
                if ($vendorCode) {
                    $prices[$vendorCode] = $priceData;
                }
                foreach ($priceData['sizes'] as $sizePrice) {
                    $sizeId = $sizePrice['sizeID'] ?? null;
                    if (! $sizeId) {
                        continue;
                    }

                    $sizeFinal = (float) ($sizePrice['discountedPrice'] ?: $sizePrice['price']);
                    $sizeData = array_merge($priceData, [
                        'price' => (float) $sizePrice['price'],
                        'discounted_price' => (float) $sizePrice['discountedPrice'],
                        'club_price' => (float) $sizePrice['clubDiscountedPrice'],
                        'final_price' => $sizeFinal,
                        'sizeID' => $sizeId,
                        'techSizeName' => $sizePrice['techSizeName'] ?? '',
                    ]);

                    $prices[(string) $nmId.':'.(string) $sizeId] = $sizeData;
                }
            }

            $offset += $limit;
            $iteration++;

        } while (count($items) === $limit && $iteration < $maxIterations);
        
        \Illuminate\Support\Facades\Log::info('WB ProductsApi: Prices loaded', [
            'total' => count($prices),
            'iterations' => $iteration,
        ]);
        
        return $prices;
    }

    /**
     * Получить рейтинги товаров через публичный API WB
     * 
     * @param array $nmIds Массив nmID товаров
     * @return array [nmId => ['rating' => float, 'feedbackCount' => int]]
     * @deprecated Используйте getCardRatings() для получения рейтинга карточки
     */
    public function getRatings(array $nmIds): array
    {
        // Публичные API WB блокируют серверные запросы
        // Используйте getCardRatings() для получения рейтинга карточки через официальный API
        return [];
    }
    
    /**
     * Получить рейтинги карточек через официальный WB Analytics API
     * 
     * Endpoint: POST /api/analytics/v3/sales-funnel/products
     * Возвращает productRating (рейтинг карточки 0-10) и feedbackRating (рейтинг по отзывам)
     * 
     * @param array $nmIds Массив nmID товаров (опционально, пустой = все товары)
     * @return array [nmId => ['productRating' => int, 'feedbackRating' => float]]
     */
    public function getCardRatings(array $nmIds = []): array
    {
        $ratings = [];
        $limit = 100;
        $offset = 0;
        
        // Период: последние 30 дней (selectedPeriod должен быть ПОСЛЕ pastPeriod).
        // 30 дней даёт стабильный % выкупа из воронки (как в ЛК «за месяц»);
        // рейтинги — снимок на текущий момент и от периода не зависят.
        $selectedStart = now()->subDays(30)->format('Y-m-d');
        $selectedEnd = now()->format('Y-m-d');
        $pastStart = now()->subDays(60)->format('Y-m-d');
        $pastEnd = now()->subDays(31)->format('Y-m-d');
        
        try {
            do {
                $body = [
                    'selectedPeriod' => [
                        'start' => $selectedStart,
                        'end' => $selectedEnd,
                    ],
                    'pastPeriod' => [
                        'start' => $pastStart,
                        'end' => $pastEnd,
                    ],
                    'nmIds' => $nmIds,
                    'limit' => $limit,
                    'offset' => $offset,
                ];
                
                $response = $this->client->analyticsPost('/api/analytics/v3/sales-funnel/products', $body);
                
                if (!$response || !isset($response['data']['products'])) {
                    \Illuminate\Support\Facades\Log::warning('WB getCardRatings: Empty response', [
                        'offset' => $offset,
                    ]);
                    break;
                }
                
                $products = $response['data']['products'];
                
                foreach ($products as $item) {
                    $product = $item['product'] ?? [];
                    $nmId = $product['nmId'] ?? null;

                    if (! $nmId) {
                        continue;
                    }

                    $entry = [
                        'productRating' => $product['productRating'] ?? null,
                        'feedbackRating' => $product['feedbackRating'] ?? null,
                    ];

                    // % выкупа из воронки продаж WB (как в ЛК). Авторитетнее трейлингового
                    // sales/orders, которое ломается из-за лага доставки/выкупа.
                    // Структура ответа: item.statistic.selected (не statistics.selectedPeriod).
                    $selected = $item['statistic']['selected'] ?? [];
                    $ordersCount = (int) ($selected['orderCount'] ?? 0);
                    if ($ordersCount > 0) {
                        $buyoutsCount = (int) ($selected['buyoutsCount'] ?? 0);
                        $buyoutsPercent = $selected['conversions']['buyoutsPercent'] ?? null;
                        $entry['redemption_rate'] = is_numeric($buyoutsPercent)
                            ? round((float) $buyoutsPercent, 2)
                            : round(min(100, ($buyoutsCount / max(1, $ordersCount)) * 100), 2);
                        $entry['redemption_orders_count'] = $ordersCount;
                        $entry['redemption_buyouts_count'] = $buyoutsCount;
                        $entry['redemption_source'] = 'wb_sales_funnel';
                    }

                    $ratings[(string) $nmId] = $entry;
                }
                
                $offset += $limit;
                
                // Пауза между запросами для соблюдения лимитов API
                if (count($products) === $limit) {
                    usleep(200000); // 200ms
                }
                
            } while (count($products) === $limit);
            
            \Illuminate\Support\Facades\Log::info('WB getCardRatings: Completed', [
                'total_ratings' => count($ratings),
            ]);
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('WB getCardRatings: Error', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $ratings;
    }

    /**
     * Получить все товары с пагинацией
     */
    public function getAllProducts(Integration $integration, int $batchSize = 100): \Generator
    {
        $cursor = null;

        do {
            $result = $this->getProducts($integration, [
                'limit' => $batchSize,
                'cursor' => $cursor,
            ]);

            $cards = $result['cards'] ?? [];
            $cursor = $result['cursor'] ?? null;

            foreach ($cards as $card) {
                yield $card;
            }

        } while (!empty($cards) && $cursor);
    }
}
