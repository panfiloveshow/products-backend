<?php

namespace App\Domains\Ozon\Api;

use App\Domains\Marketplace\Contracts\ProductsApiInterface;
use App\Models\Integration;

/**
 * API для работы с товарами Ozon
 * 
 * Endpoints для чтения:
 * - POST /v3/product/list - список товаров
 * - POST /v3/product/info/list - информация о товаре
 * - POST /v5/product/info/prices - цены товаров
 * - GET /v1/actions, POST /v2/actions/products - акционные цены
 *
 * Endpoints для записи (выгрузка на маркетплейс):
 * - POST /v3/product/import - создание/обновление товаров
 * - POST /v2/products/stocks - обновление остатков FBS
 * - POST /v1/product/import/prices - обновление цен
 * - POST /v1/product/attributes/update - обновление атрибутов
 * 
 * @see https://docs.ozon.ru/api/seller
 */
class ProductsApi implements ProductsApiInterface
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получить список товаров
     * Ozon API v3 (обновлено с v2)
     */
    public function getProducts(?Integration $integration = null, array $options = []): array
    {
        $limit = $options['limit'] ?? 100;
        $lastId = $options['last_id'] ?? '';
        
        $response = $this->client->post('/v3/product/list', [
            'filter' => [
                'visibility' => 'ALL',
            ],
            'limit' => $limit,
            'last_id' => $lastId,
        ]);

        if (!$response) {
            return [];
        }

        return [
            'items' => $response['result']['items'] ?? [],
            'total' => $response['result']['total_items'] ?? $response['result']['total'] ?? 0,
            'last_id' => $response['result']['last_id'] ?? '',
            'complete' => ! ($response['_error'] ?? false) && is_array($response['result']['items'] ?? null)
                && array_key_exists('last_id', $response['result'] ?? []),
        ];
    }

    /**
     * Получить товар по SKU (offer_id).
     * /v2/product/info удалён 10.03.2025 → /v3/product/info/list (items без обёртки result).
     */
    public function getProductBySku(string $sku, ?Integration $integration = null): ?array
    {
        $response = $this->client->post('/v3/product/info/list', [
            'offer_id' => [$sku],
        ]);

        return $response['items'][0] ?? null;
    }

    /**
     * Получить товары по списку product_id с полными данными (name, images, description)
     * 
     * Использует POST /v4/product/info/attributes — актуальный эндпоинт,
     * который возвращает все данные: name, images, description, attributes, stocks
     */
    public function getProductsByIds(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        // Конвертируем product_id в строки (API требует массив строк)
        $productIdsStr = array_map('strval', $productIds);
        
        // Используем POST /v4/product/info/attributes — возвращает ВСЕ данные
        // Формат: filter.product_id = ["123", "456"], limit вне filter
        $response = $this->client->post('/v4/product/info/attributes', [
            'filter' => [
                'product_id' => $productIdsStr,
                'visibility' => 'ALL',
            ],
            'limit' => 1000,
            'sort_dir' => 'ASC',
        ]);
        
        \Log::debug('Ozon getProductsByIds response', [
            'product_ids_count' => count($productIds),
            'response_keys' => $response ? array_keys($response) : [],
            'has_result' => isset($response['result']),
            'has_items' => isset($response['items']),
            'result_type' => isset($response['result']) ? gettype($response['result']) : 'N/A',
        ]);
        
        // Ответ может быть в разных форматах
        $items = [];
        if (isset($response['result']) && is_array($response['result'])) {
            // Если result — массив items
            if (isset($response['result']['items'])) {
                $items = $response['result']['items'];
            } elseif (isset($response['result'][0])) {
                // Если result — сам массив товаров
                $items = $response['result'];
            }
        } elseif (isset($response['items'])) {
            $items = $response['items'];
        }
        
        \Log::debug('Ozon getProductsByIds items', [
            'items_count' => count($items),
            'first_item_keys' => !empty($items) ? array_keys($items[0]) : [],
        ]);
        
        // Нормализуем данные для совместимости
        foreach ($items as &$item) {
            // images может быть в разных полях
            if (empty($item['images']) && !empty($item['primary_image'])) {
                $item['images'] = is_array($item['primary_image']) ? $item['primary_image'] : [$item['primary_image']];
            }
        }

        return $items;
    }

    /**
     * Получить товары с комиссиями через v3 API
     * 
     * POST /v3/product/info/list — возвращает комиссии для каждой схемы
     * 
     * @param array $offerIds Массив offer_id
     * @return array Ассоциативный массив [offer_id => commissions]
     */
    public function getProductsWithCommissions(array $offerIds): array
    {
        if (empty($offerIds)) {
            return [];
        }
        
        $result = [];
        
        // API принимает максимум 1000 offer_id за раз
        $chunks = array_chunk($offerIds, 1000);
        
        foreach ($chunks as $chunk) {
            try {
                $response = $this->client->post('/v3/product/info/list', [
                    'offer_id' => $chunk,
                ]);
                
                foreach ($response['items'] ?? [] as $item) {
                    $offerId = $item['offer_id'] ?? null;
                    if (!$offerId) continue;
                    
                    $commissions = [];
                    foreach ($item['commissions'] ?? [] as $comm) {
                        $schema = strtolower($comm['sale_schema'] ?? '');
                        if ($schema) {
                            $commissions[$schema] = [
                                'percent' => (float) ($comm['percent'] ?? 15),
                                'delivery_amount' => $comm['delivery_amount'] ?? 0,
                                'return_amount' => $comm['return_amount'] ?? 0,
                                'value' => $comm['value'] ?? 0,
                            ];
                        }
                    }
                    
                    $result[$offerId] = $commissions;
                }
            } catch (\Exception $e) {
                \Log::warning('Ozon getProductsWithCommissions error', [
                    'error' => $e->getMessage(),
                    'chunk_size' => count($chunk),
                ]);
            }
        }
        
        \Log::info('Ozon getProductsWithCommissions loaded', [
            'requested' => count($offerIds),
            'loaded' => count($result),
        ]);
        
        return $result;
    }

    /**
     * Получить описание одного товара
     * 
     * POST /v1/product/info/description
     * 
     * @param string $offerId offer_id товара
     * @return array|null Описание товара
     */
    public function getProductDescription(string $offerId): ?array
    {
        $response = $this->client->post('/v1/product/info/description', [
            'offer_id' => $offerId,
        ]);

        return $response['result'] ?? null;
    }

    /**
     * Получить описания товаров
     * 
     * POST /v1/product/info/description — принимает только один offer_id за раз
     * 
     * @param array $offerIds Массив offer_id
     * @return array Ассоциативный массив [offer_id => description]
     */
    public function getProductDescriptions(array $offerIds): array
    {
        if (empty($offerIds)) {
            return [];
        }
        
        $descriptions = [];
        
        foreach ($offerIds as $offerId) {
            $result = $this->getProductDescription($offerId);
            if ($result && isset($result['description'])) {
                $descriptions[$offerId] = $result['description'];
            }
        }
        
        return $descriptions;
    }
    
    /**
     * Получить изображения товаров
     * 
     * POST /v2/product/pictures/info
     */
    public function getProductImages(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $response = $this->client->post('/v2/product/pictures/info', [
            'product_id' => $productIds,
        ]);

        return $response['result']['items'] ?? $response['items'] ?? [];
    }

    /**
     * Получить цены товаров
     * Ozon API v5 (обновлено с v4)
     */
    public function getPrices(?Integration $integration = null, array $skus = []): array
    {
        $allPrices = [];
        $cursor = '';

        // С 12.11.2025 Ozon отключил marketing_price, и участие в акции больше
        // не отражается в /v5/product/info/prices — акционные цены доступны
        // только через API акций (/v1/actions*). product_id => акционная цена.
        $actionPrices = $this->getActionPrices();

        do {
            $response = $this->client->post('/v5/product/info/prices', [
                'filter' => [
                    'visibility' => 'ALL',
                ],
                'limit' => 1000,
                'cursor' => $cursor,
            ]);

            if (!$response) {
                break;
            }

            $observedAt = now()->utc()->toIso8601String();
            $commissionsObservedAt = now()->utc()->format('Y-m-d\TH:i:s.uP');

            // v5 API возвращает items напрямую, cursor вместо last_id
            $items = $response['items'] ?? [];
            $cursor = $response['cursor'] ?? '';

            foreach ($items as $item) {
                $sku = $item['offer_id'] ?? null;
                if (!is_string($sku) || $sku === '') continue;

                if (!empty($skus) && !in_array($sku, $skus)) continue;

                // v5 API: price данные в item['price']
                $priceData = $item['price'] ?? [];
                
                $price = (float) ($priceData['price'] ?? 0);
                $oldPrice = (float) ($priceData['old_price'] ?? 0);
                $marketingSellerPrice = (float) ($priceData['marketing_seller_price'] ?? 0);
                $priceIndexes = $this->normalizePriceIndexes($item['price_indexes'] ?? []);
                
                // Актуальная цена на витрине: приоритет marketing_seller_price — если
                // Ozon его заполнил, это фактическая цена продажи (совпадает с «Ваша
                // цена» в кабинете). Цена из API акций (/v1/actions) — ТОЛЬКО фолбэк,
                // когда marketing_seller_price = 0: она отражает цену УЧАСТИЯ в акции,
                // а не обязательно текущую витринную (будущая/неактивная акция). Раньше
                // брали min(marketing, action) и занижали цену (напр. A65: витрина 668,
                // action 420 → показывали 420, экономика уходила в ложный минус).
                $actionPrice = (float) ($actionPrices[(int) ($item['product_id'] ?? 0)] ?? 0);
                if ($marketingSellerPrice > 0) {
                    // Когда поле заполнено, это действующая цена продавца на витрине.
                    // Не подменяем её ценой участия из другой/будущей акции.
                    $actualPrice = $marketingSellerPrice;
                    $priceSource = 'marketing_seller_price';
                } elseif ($actionPrice > 0 && $actionPrice < $price) {
                    $actualPrice = $actionPrice;
                    $priceSource = 'action_price';
                } else {
                    $actualPrice = $price;
                    $priceSource = 'seller_price';
                }

                // Определяем, участвует ли товар в акции
                $isInPromotion = $actualPrice < $price;
                $promotionDiscount = $isInPromotion && $price > 0
                    ? round((1 - $actualPrice / $price) * 100, 1)
                    : 0;
                
                $allPrices[$sku] = [
                    'product_id' => $item['product_id'] ?? null,
                    'offer_id' => (string) $sku,
                    'price' => $price,                           // Базовая цена без скидок
                    'old_price' => $oldPrice,                    // Зачёркнутая цена (маркетинговая)
                    'min_price' => (float) ($priceData['min_price'] ?? 0),
                    'marketing_seller_price' => $marketingSellerPrice, // Цена с акцией
                    'actual_price' => $actualPrice,              // Действующая цена (с учётом акций)
                    'price_source' => $priceSource,
                    'is_in_promotion' => $isInPromotion,         // Участвует в акции
                    'promotion_discount' => $promotionDiscount,  // Процент скидки
                    'price_indexes' => $priceIndexes,
                    'price_index_color' => $priceIndexes['color_index'] ?? null,
                    'price_index_label' => $this->priceIndexLabel($priceIndexes['color_index'] ?? null),
                    'price_index_value' => $priceIndexes['selected_index_data']['price_index_value'] ?? null,
                    'competitor_price' => $priceIndexes['selected_index_data']['min_price'] ?? null,
                    'competitor_price_source' => $priceIndexes['selected_index_source'] ?? null,
                    // Дополнительные данные из v5 API
                    'commissions' => $item['commissions'] ?? [],
                    'price_observed_at' => $observedAt,
                    'commissions_observed_at' => $commissionsObservedAt,
                    'volume_weight' => (float) ($item['volume_weight'] ?? 0),
                ];
            }

        } while (!empty($items) && !empty($cursor));

        return $allPrices;
    }

    /** Authoritative prices with explicit completeness; never wait in a worker on 429. */
    public function getPriceSnapshot(?Integration $integration = null, array $skus = []): array
    {
        $snapshot = ['prices' => [], 'complete' => false, 'http_status' => null,
            'retry_after' => null, 'request_started_at' => now()->utc()->toIso8601String()];
        $cursor = '';
        $seen = [];
        $pending = [];
        $pagesComplete = false;
        $deadline = microtime(true) + 90;
        for ($page = 0; $page < 200; $page++) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $response = $this->client->post('/v5/product/info/prices', [
                'filter' => ['visibility' => 'ALL'], 'limit' => 1000, 'cursor' => $cursor,
            ], retryRateLimit: false);
            $snapshot['http_status'] = $this->client->getLastResponseStatus();
            $snapshot['retry_after'] = $this->client->getLastRateLimitRetryAfter();
            if (! is_array($response) || ($response['_error'] ?? false)
                || ! is_array($response['items'] ?? null) || ! array_key_exists('cursor', $response)) {
                break;
            }
            $this->recordSnapshotPrices($response['items'], $snapshot, $pending, $skus);
            $next = (string) $response['cursor'];
            if ($next === '') {
                $pagesComplete = true;
                break;
            }
            if ($response['items'] === [] || isset($seen[$next])) {
                break;
            }
            $seen[$next] = true;
            $cursor = $next;
        }
        // Ozon can omit archived offers from an unfiltered ALL listing while
        // still returning their authoritative prices for an explicit offer_id.
        // Recover only known missing offers, never replace them with catalog prices.
        if ($pagesComplete && $skus !== []) {
            $missing = array_values(array_diff($skus, array_keys($snapshot['prices']), array_keys($pending)));
            foreach (array_chunk($missing, 1000) as $batch) {
                $cursor = '';
                $seen = [];
                $batchComplete = false;
                for ($page = 0; $page < 200 && microtime(true) < $deadline; $page++) {
                    $response = $this->client->post('/v5/product/info/prices', [
                        'filter' => ['offer_id' => $batch, 'visibility' => 'ALL'], 'limit' => 1000, 'cursor' => $cursor,
                    ], retryRateLimit: false);
                    $snapshot['http_status'] = $this->client->getLastResponseStatus();
                    $snapshot['retry_after'] = $this->client->getLastRateLimitRetryAfter();
                    if (! is_array($response) || ($response['_error'] ?? false)
                        || ! is_array($response['items'] ?? null) || ! array_key_exists('cursor', $response)) {
                        break;
                    }
                    $this->recordSnapshotPrices($response['items'], $snapshot, $pending, $batch);
                    $next = (string) $response['cursor'];
                    if ($next === '') {
                        $batchComplete = true;
                        break;
                    }
                    if ($response['items'] === [] || isset($seen[$next])) {
                        break;
                    }
                    $seen[$next] = true;
                    $cursor = $next;
                }
                if (! $batchComplete) {
                    $pagesComplete = false;
                    break;
                }
            }
        }
        // An incomplete primary snapshot must keep its failure/status. Fetching
        // optional data now would hide the rate limit that stopped pagination.
        if ($pending !== [] && $pagesComplete) {
            $actions = $this->getActionPriceSnapshot($deadline);
            if (! $actions['complete']) {
                $snapshot['http_status'] = $actions['http_status'];
                $snapshot['retry_after'] = $actions['retry_after'];

                return $snapshot;
            }
            foreach ($pending as $sku => $item) {
                $snapshot['prices'][$sku] = $this->snapshotPrice($item, $actions['prices']);
            }
        }
        $snapshot['complete'] = $pagesComplete;

        return $snapshot;
    }

    private function recordSnapshotPrices(array $items, array &$snapshot, array &$pending, array $skus): void
    {
        $observedAt = now()->utc()->toIso8601String();
        $commissionsObservedAt = now()->utc()->format('Y-m-d\TH:i:s.uP');
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['offer_id'] ?? null) || $item['offer_id'] === '' || ! is_array($item['price'] ?? null)) {
                continue;
            }
            $sku = (string) $item['offer_id'];
            if ($skus !== [] && ! in_array($sku, $skus, true)) {
                continue;
            }
            $item['price_observed_at'] = $observedAt;
            $item['commissions_observed_at'] = $commissionsObservedAt;
            if ((float) ($item['price']['marketing_seller_price'] ?? 0) > 0) {
                $snapshot['prices'][$sku] = $this->snapshotPrice($item, []);
                unset($pending[$sku]);
            } else {
                $pending[$sku] = $item;
            }
        }
    }

    private function snapshotPrice(array $item, array $actions): array
    {
        $data = $item['price'];
        $base = (float) ($data['price'] ?? 0);
        $marketing = (float) ($data['marketing_seller_price'] ?? 0);
        $action = (float) ($actions[(int) ($item['product_id'] ?? 0)] ?? 0);
        $actual = $marketing > 0 ? $marketing : ($action > 0 && $action < $base ? $action : $base);
        $indexes = $this->normalizePriceIndexes($item['price_indexes'] ?? []);

        return ['product_id' => $item['product_id'] ?? null, 'offer_id' => (string) $item['offer_id'], 'price' => $base,
            'old_price' => (float) ($data['old_price'] ?? 0), 'min_price' => (float) ($data['min_price'] ?? 0),
            'marketing_seller_price' => $marketing, 'actual_price' => $actual,
            'price_source' => $marketing > 0 ? 'marketing_seller_price' : ($actual < $base ? 'action_price' : 'seller_price'),
            'is_in_promotion' => $actual < $base,
            'promotion_discount' => $actual < $base && $base > 0 ? round((1 - $actual / $base) * 100, 1) : 0,
            'price_indexes' => $indexes, 'price_index_color' => $indexes['color_index'] ?? null,
            'commissions' => $item['commissions'] ?? [], 'volume_weight' => (float) ($item['volume_weight'] ?? 0),
            'price_observed_at' => $item['price_observed_at'], 'commissions_observed_at' => $item['commissions_observed_at']];
    }

    private function getActionPriceSnapshot(float $deadline): array
    {
        $result = ['prices' => [], 'complete' => false, 'http_status' => null, 'retry_after' => null];
        $response = $this->client->get('/v1/actions');
        $result['http_status'] = $this->client->getLastResponseStatus();
        $result['retry_after'] = $this->client->getLastRateLimitRetryAfter();
        if (! is_array($response['result'] ?? null)) {
            return $result;
        }
        foreach ($response['result'] as $action) {
            if (microtime(true) >= $deadline) {
                return $result;
            }
            if (empty($action['id']) || ((int) ($action['participating_products_count'] ?? 0) === 0 && empty($action['is_participating']))) {
                continue;
            }
            $cursor = '';
            $seen = [];
            $complete = false;
            $received = 0;
            for ($page = 0; $page < 200; $page++) {
                if (microtime(true) >= $deadline) {
                    return $result;
                }
                $response = $this->client->post('/v2/actions/products', [
                    'action_id' => (int) $action['id'], 'limit' => 100, 'last_id' => $cursor,
                ], retryRateLimit: false);
                $result['http_status'] = $this->client->getLastResponseStatus();
                $result['retry_after'] = $this->client->getLastRateLimitRetryAfter();
                if (! is_array($response['products'] ?? null) || ($response['_error'] ?? false)) {
                    return $result;
                }
                foreach ($response['products'] as $product) {
                    $id = (int) ($product['id'] ?? 0);
                    $price = (float) ($product['action_price']['amount'] ?? 0);
                    if ($id && $price > 0) {
                        $result['prices'][$id] = min($result['prices'][$id] ?? $price, $price);
                    }
                }
                $received += count($response['products']);
                $total = isset($response['total']) && is_numeric($response['total']) ? max(0, (int) $response['total']) : null;
                $next = (string) ($response['last_id'] ?? '');
                // v2 retains the last product cursor on the final page. A
                // fulfilled total (or an empty page without a total) is terminal.
                if ($next === '' || ($total !== null && $received >= $total)
                    || ($response['products'] === [] && $total === null)) {
                    $complete = true;
                    break;
                }
                if ($response['products'] === [] || isset($seen[$next])) {
                    break;
                }
                $seen[$next] = true;
                $cursor = $next;
            }
            if (! $complete) {
                return $result;
            }
        }
        $result['complete'] = true;

        return $result;
    }

    /**
     * Акционные цены товаров из API акций.
     * GET /v1/actions — доступные акции; POST /v2/actions/products — товары,
     * уже участвующие в акции (/v1/actions/products отключается 13.10.2026).
     * Hot Sale эндпоинты (/v1/actions/hotsales/*) Ozon удалил — не запрашиваем.
     *
     * @return array<int, float> product_id => минимальная акционная цена
     */
    public function getActionPrices(): array
    {
        $prices = [];

        try {
            $actions = ($this->client->get('/v1/actions') ?? [])['result'] ?? [];

            foreach ($actions as $action) {
                $actionId = $action['id'] ?? null;
                $participating = (int) ($action['participating_products_count'] ?? 0) > 0
                    || ! empty($action['is_participating']);

                if (! $actionId || ! $participating) {
                    continue;
                }

                $this->collectActionProducts($prices, (int) $actionId);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Ozon getActionPrices failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $prices;
    }

    /**
     * /v2/actions/products: курсор last_id (offset v1 устарел — читались только
     * первые 100 товаров акции), ответ без обёртки result, цены — {amount, currency}.
     *
     * @param  array<int, float>  $prices  аккумулятор product_id => цена
     */
    private function collectActionProducts(array &$prices, int $actionId): void
    {
        $lastId = '';
        $maxPages = 1000; // защита от зацикливания курсора

        do {
            $response = $this->client->post('/v2/actions/products', [
                'action_id' => $actionId,
                'limit' => 100,
                'last_id' => $lastId,
            ]) ?? [];

            if (! empty($response['_error'])) {
                \Illuminate\Support\Facades\Log::warning('Ozon /v2/actions/products error', [
                    'action_id' => $actionId,
                    'status' => $response['_http_status'] ?? null,
                    'message' => $response['message'] ?? null,
                ]);

                return;
            }

            $products = $response['products'] ?? [];

            foreach ($products as $product) {
                $productId = (int) ($product['id'] ?? 0);
                $actionPrice = (float) ($product['action_price']['amount'] ?? 0);

                if ($productId && $actionPrice > 0) {
                    $prices[$productId] = isset($prices[$productId])
                        ? min($prices[$productId], $actionPrice)
                        : $actionPrice;
                }
            }

            $nextLastId = (string) ($response['last_id'] ?? '');
            $moved = $nextLastId !== '' && $nextLastId !== $lastId;
            $lastId = $nextLastId;
        } while ($products !== [] && $moved && --$maxPages > 0);
    }

    private function normalizePriceIndexes(mixed $priceIndexes): array
    {
        if (! is_array($priceIndexes) || empty($priceIndexes)) {
            return [];
        }

        $normalized = [
            'color_index' => $this->normalizePriceIndexColor($priceIndexes['color_index'] ?? $priceIndexes['price_index'] ?? null),
            'ozon_index_data' => $this->normalizePriceIndexData($priceIndexes['ozon_index_data'] ?? $priceIndexes['ozon'] ?? []),
            'external_index_data' => $this->normalizePriceIndexData($priceIndexes['external_index_data'] ?? $priceIndexes['external'] ?? []),
            'self_marketplaces_index_data' => $this->normalizePriceIndexData($priceIndexes['self_marketplaces_index_data'] ?? $priceIndexes['self_marketplaces'] ?? []),
        ];

        // Ozon «индекс цены» = ваша цена ÷ МИНИМАЛЬНАЯ цена на тот же товар среди
        // всех источников (Ozon / внешние площадки / другие МП). Биндинговый —
        // источник с самым дешёвым конкурентом, т.е. с НАИБОЛЬШИМ price_index_value;
        // он же определяет color_index в кабинете. Раньше брали первый ненулевой
        // (всегда ozon_index_data) → число расходилось с цветом и с кабинетом.
        foreach (['ozon_index_data', 'external_index_data', 'self_marketplaces_index_data'] as $source) {
            $candidate = $normalized[$source];
            if (($candidate['price_index_value'] ?? 0) <= 0 && ($candidate['min_price'] ?? 0) <= 0) {
                continue;
            }
            if (! isset($normalized['selected_index_data'])
                || ($candidate['price_index_value'] ?? 0) > ($normalized['selected_index_data']['price_index_value'] ?? 0)) {
                $normalized['selected_index_source'] = $source;
                $normalized['selected_index_data'] = $candidate;
            }
        }

        return $normalized;
    }

    private function normalizePriceIndexData(mixed $data): array
    {
        if (! is_array($data)) {
            $data = [];
        }

        return [
            'min_price' => $this->normalizeMoney($data['min_price'] ?? $data['minimal_price'] ?? null) ?? 0.0,
            'min_price_currency' => $data['min_price_currency'] ?? $data['minimal_price_currency'] ?? null,
            'price_index_value' => is_numeric($data['price_index_value'] ?? null)
                ? round((float) $data['price_index_value'], 4)
                : 0.0,
        ];
    }

    private function normalizePriceIndexColor(mixed $color): ?string
    {
        if (! is_string($color) || trim($color) === '') {
            return null;
        }

        $color = strtoupper(trim($color));
        return match ($color) {
            'COLOR_INDEX_GREEN' => 'GREEN',
            'COLOR_INDEX_YELLOW' => 'YELLOW',
            'COLOR_INDEX_RED' => 'RED',
            'COLOR_INDEX_WITHOUT_INDEX' => 'WITHOUT_INDEX',
            default => $color,
        };
    }

    private function priceIndexLabel(?string $color): ?string
    {
        return match ($this->normalizePriceIndexColor($color)) {
            'GREEN' => 'Выгодный',
            'YELLOW' => 'Средний индекс',
            'RED' => 'Невыгодный',
            'SUPER' => 'Супервыгодный',
            'WITHOUT_INDEX' => 'Нет индекса',
            default => null,
        };
    }

    /**
     * Получить цену товара у конкурента из стратегии ценообразования Ozon.
     *
     * Ozon отдаёт эти данные только по одному product_id, поэтому метод намеренно
     * ограничивает количество запросов за запуск и не бросает исключения наружу:
     * отсутствие этих данных не должно ломать основной расчёт юнит-экономики.
     *
     * @param array<int|string> $productIds
     * @return array<string,array<string,mixed>> product_id => normalized pricing strategy info
     */
    public function getPricingStrategyProductInfo(array $productIds, int $maxRequests = 500, int $sleepMicros = 120000): array
    {
        $ids = collect($productIds)
            ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn ($id) => $id !== null && $id > 0)
            ->unique()
            ->take($maxRequests)
            ->values()
            ->all();

        if (empty($ids)) {
            return [];
        }

        $result = [];

        foreach ($ids as $index => $productId) {
            try {
                $response = $this->client->post('/v1/pricing-strategy/product/info', [
                    'product_id' => $productId,
                ]);

                if (($response['_error'] ?? false) || ! is_array($response)) {
                    \Log::warning('Ozon pricing strategy product info unavailable', [
                        'product_id' => $productId,
                        'status' => $response['_http_status'] ?? null,
                    ]);
                    continue;
                }

                $info = $response['result'] ?? null;
                if (! is_array($info)) {
                    continue;
                }

                $price = $this->normalizeMoney($info['strategy_product_price'] ?? null);
                $isEnabled = array_key_exists('is_enabled', $info) ? (bool) $info['is_enabled'] : null;

                $result[(string) $productId] = [
                    'product_id' => $productId,
                    'strategy_id' => $info['strategy_id'] ?? null,
                    'is_enabled' => $isEnabled,
                    'competitor_price' => $price,
                    'strategy_product_price' => $price,
                    'price_downloaded_at' => $info['price_downloaded_at'] ?? null,
                    // strategy_competitor_id устарел (19.06.2025) — конкурента
                    // определяем только по ссылке на его товар.
                    'strategy_competitor_product_url' => $info['strategy_competitor_product_url'] ?? null,
                    'source' => 'pricing_strategy_product_info',
                    'status' => $price !== null && $price > 0
                        ? 'available'
                        : ($isEnabled === false ? 'disabled' : 'no_price'),
                ];
            } catch (\Throwable $e) {
                \Log::warning('Ozon pricing strategy product info error', [
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($sleepMicros > 0 && $index < count($ids) - 1) {
                usleep($sleepMicros);
            }
        }

        return $result;
    }

    private function normalizeMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * Получить все товары с пагинацией
     */
    public function getAllProducts(Integration $integration, int $batchSize = 100): \Generator
    {
        $lastId = '';

        do {
            $result = $this->getProducts($integration, [
                'limit' => $batchSize,
                'last_id' => $lastId,
            ]);

            $items = $result['items'] ?? [];
            $lastId = $result['last_id'] ?? '';

            foreach ($items as $item) {
                yield $item;
            }

        } while (!empty($items) && !empty($lastId));
    }

    /**
     * Получить атрибуты товаров (включая категории)
     * 
     * POST /v4/product/info/attributes — актуальный эндпоинт
     * Возвращает: name, images, description, attributes, stocks, prices и т.д.
     */
    public function getProductAttributes(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        $response = $this->client->post('/v4/product/info/attributes', [
            'filter' => [
                'product_id' => $productIds,
            ],
            'limit' => 1000,
        ]);

        if (!is_array($response) || !empty($response['_error'])) {
            return [];
        }

        return $response['result']['items'] ?? $response['items'] ?? $response['result'] ?? [];
    }

    // ========================================
    // МЕТОДЫ ВЫГРУЗКИ НА МАРКЕТПЛЕЙС
    // ========================================

    /**
     * Создать или обновить товары на Ozon
     * 
     * POST /v3/product/import
     * 
     * @param array $products Массив товаров для импорта
     * @return array Результат импорта с task_id
     * 
     * Структура товара:
     * [
     *   'offer_id' => 'SKU-001',           // Артикул продавца (обязательно)
     *   'name' => 'Название товара',       // Название (обязательно)
     *   'description_category_id' => 123,  // ID категории Ozon (обязательно)
     *   'price' => '1299',                 // Цена (обязательно)
     *   'old_price' => '1599',             // Старая цена (опционально)
     *   'vat' => '0',                      // НДС: 0, 0.1, 0.2 (обязательно)
     *   'currency_code' => 'RUB',          // Валюта (обязательно)
     *   'barcode' => '4600000000001',      // Штрихкод (опционально)
     *   'description' => 'Описание...',    // Описание товара
     *   'images' => ['https://...'],       // Массив URL изображений
     *   'primary_image' => 'https://...',  // Главное изображение
     *   'depth' => 100,                    // Глубина в мм
     *   'width' => 200,                    // Ширина в мм
     *   'height' => 50,                    // Высота в мм
     *   'weight' => 500,                   // Вес в граммах
     *   'dimension_unit' => 'mm',          // Единица измерения размеров
     *   'weight_unit' => 'g',              // Единица измерения веса
     *   'attributes' => [                  // Характеристики товара
     *     ['complex_id' => 0, 'id' => 85, 'values' => [['value' => 'Бренд']]]
     *   ]
     * ]
     * 
     * @see https://docs.ozon.ru/api/seller/#operation/ProductAPI_ImportProductsV3
     */
    public function importProducts(array $products): array
    {
        $response = $this->client->post('/v3/product/import', ['items' => $products]);
        $failure = $this->nativeWriteFailure($response, 'Failed to import products to Ozon');
        if ($failure !== null) return $failure;
        $task = $response['result']['task_id'] ?? null;
        if ((! is_int($task) && ! is_string($task)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $task)) {
            return ['success' => false, 'status' => 'unknown', 'error' => 'Ozon import task receipt is not confirmed', 'completion_verified' => false];
        }
        return ['success' => true, 'status' => 'accepted', 'completion_verified' => false,
            'task_id' => $task, 'result' => $response['result'], 'dispatch' => $response['_sellico_price_dispatch'] ?? null];
    }

    /**
     * Проверить статус импорта товаров
     * 
     * POST /v1/product/import/info
     * 
     * @param int $taskId ID задачи импорта
     * @return array Статус импорта
     */
    public function getImportStatus(int $taskId): array
    {
        $response = $this->client->post('/v1/product/import/info', [
            'task_id' => $taskId,
        ]);

        return $response['result'] ?? [];
    }

    /**
     * Обновить цены товаров
     * 
     * POST /v1/product/import/prices
     * 
     * @param array $prices Массив цен
     * @return array Результат обновления
     * 
     * Структура:
     * [
     *   'offer_id' => 'SKU-001',
     *   'price' => '1299',
     *   'old_price' => '1599',
     *   'min_price' => '999',
     *   'currency_code' => 'RUB'
     * ]
     */
    public function updatePrices(array $prices): array
    {
        if ($prices === [] || ! array_is_list($prices)) return ['success' => false, 'status' => 'not_dispatched', 'error' => 'Explicit price rows are required'];
        $response = $this->client->post('/v1/product/import/prices', ['prices' => $prices]);
        return $this->nativeItemWriteResult($response, $prices, 'prices');
    }

    /**
     * Обновить остатки товаров (FBS)
     * 
     * POST /v2/products/stocks
     * 
     * @param array $stocks Массив остатков
     * @return array Результат обновления
     * 
     * Структура:
     * [
     *   'offer_id' => 'SKU-001',
     *   'stock' => 100,
     *   'warehouse_id' => 123456  // ID склада FBS
     * ]
     */
    public function updateStocks(array $stocks): array
    {
        if ($stocks === [] || ! array_is_list($stocks)) return ['success' => false, 'status' => 'not_dispatched', 'error' => 'Explicit stock rows are required'];
        $response = $this->client->post('/v2/products/stocks', ['stocks' => $stocks]);
        return $this->nativeItemWriteResult($response, $stocks, 'stocks');
    }

    /**
     * Обновить атрибуты (характеристики) товара
     * 
     * POST /v1/product/attributes/update
     * 
     * @param array $items Массив товаров с атрибутами
     * @return array Результат обновления
     * 
     * Структура:
     * [
     *   'offer_id' => 'SKU-001',
     *   'attributes' => [
     *     ['id' => 85, 'complex_id' => 0, 'values' => [['value' => 'Бренд']]]
     *   ]
     * ]
     */
    public function updateAttributes(array $items): array
    {
        $response = $this->client->post('/v1/product/attributes/update', [
            'items' => $items,
        ]);

        if (!$response || !empty($response['_error']) || !isset($response['task_id'])) {
            return [
                'success' => false,
                'error' => 'Failed to update attributes on Ozon',
            ];
        }

        return [
            'success' => true,
            'task_id' => $response['task_id'],
            'result' => $response['result'] ?? ['task_id' => $response['task_id']],
        ];
    }

    /**
     * Получить список категорий Ozon
     * 
     * POST /v1/description-category/tree
     * 
     * @return array Дерево категорий
     */
    public function getCategoryTree(): array
    {
        $response = $this->client->post('/v1/description-category/tree', []);
        return $response['result'] ?? [];
    }

    /**
     * Получить атрибуты категории (обязательные и опциональные)
     * 
     * POST /v1/description-category/attribute
     * 
     * @param int $categoryId ID категории
     * @param string $language Язык (DEFAULT, RU, EN, ZH_HANS, TR)
     * @return array Атрибуты категории
     */
    public function getCategoryAttributes(int $categoryId, string $language = 'DEFAULT'): array
    {
        $response = $this->client->post('/v1/description-category/attribute', [
            'description_category_id' => $categoryId,
            'language' => $language,
            'type_id' => 0,
        ]);

        return $response['result'] ?? [];
    }

    /**
     * Получить значения атрибута (для выпадающих списков)
     * 
     * POST /v1/description-category/attribute/values
     * 
     * @param int $categoryId ID категории
     * @param int $attributeId ID атрибута
     * @param int $limit Лимит
     * @param string $lastValueId Последний ID для пагинации
     * @return array Значения атрибута
     */
    public function getAttributeValues(int $categoryId, int $attributeId, int $limit = 100, string $lastValueId = ''): array
    {
        $response = $this->client->post('/v1/description-category/attribute/values', [
            'description_category_id' => $categoryId,
            'attribute_id' => $attributeId,
            'limit' => $limit,
            'last_value_id' => $lastValueId,
            'language' => 'DEFAULT',
        ]);

        return $response['result'] ?? [];
    }

    /**
     * Экспортировать товар на Ozon (полный цикл)
     * 
     * Создаёт/обновляет товар со всеми данными:
     * - Основная информация (название, описание, цена)
     * - Изображения
     * - Характеристики
     * - Остатки (если указан warehouse_id)
     * 
     * @param array $product Данные товара
     * @param int|null $warehouseId ID склада FBS для остатков
     * @return array Результат экспорта
     */
    public function exportProduct(array $product, ?int $warehouseId = null): array
    {
        $result = $this->exportProducts([$product], $warehouseId);
        foreach (['import', 'prices', 'stocks'] as $step) {
            if (isset($result['steps'][$step][0])) $result['steps'][$step] = $result['steps'][$step][0];
        }
        return $result;
    }

    /**
     * Массовый экспорт товаров на Ozon
     * 
     * @param array $products Массив товаров
     * @param int|null $warehouseId ID склада FBS
     * @return array Результат экспорта
     */
    public function exportProducts(array $products, ?int $warehouseId = null): array
    {
        $result = ['success' => false, 'status' => 'not_dispatched', 'completion_verified' => false,
            'total' => count($products), 'accepted_for_import' => 0, 'imported' => null, 'failed' => null,
            'steps' => [], 'task_ids' => [], 'errors' => []];
        try {
            $products = $this->explicitExportInputs($products, $warehouseId);
        } catch (\InvalidArgumentException $error) {
            $result['errors'][] = $error->getMessage();
            return $result;
        }
        foreach (array_chunk($products, 100) as $batch) {
            $import = $this->importProducts($batch);
            $result['steps']['import'][] = $import;
            if (! $import['success']) return $this->unfinishedExport($result, $import);
            $result['accepted_for_import'] += count($batch);
            $result['task_ids'][] = $import['task_id'];
            // The import is asynchronous. These results are request receipts,
            // not a claim that imported cards or their prices were observed.
            $prices = array_map(function (array $product): array {
                $row = ['offer_id' => $product['offer_id'], 'price' => $product['price'], 'currency_code' => $product['currency_code']];
                if (array_key_exists('old_price', $product)) $row['old_price'] = $product['old_price'];
                return $row;
            }, $batch);
            $price = $this->updatePrices($prices);
            $result['steps']['prices'][] = $price;
            if (! $price['success']) return $this->unfinishedExport($result, $price);
            $stocks = [];
            if ($warehouseId !== null) foreach ($batch as $product) {
                if (array_key_exists('stock', $product)) $stocks[] = ['offer_id' => $product['offer_id'], 'stock' => $product['stock'], 'warehouse_id' => $warehouseId];
            }
            if ($stocks !== []) {
                $stock = $this->updateStocks($stocks);
                $result['steps']['stocks'][] = $stock;
                if (! $stock['success']) return $this->unfinishedExport($result, $stock);
            }
        }
        $result['success'] = true;
        $result['status'] = 'accepted';
        return $result;
    }

    private function nativeWriteFailure(?array $response, string $message): ?array
    {
        if ($response !== null && empty($response['_error'])) return null;
        $dispatch = $response['_sellico_price_dispatch'] ?? null;
        $status = match ($dispatch['status'] ?? null) {
            'not_dispatched' => 'not_dispatched',
            'rejected' => 'failed',
            default => 'unknown',
        };
        if ($status === 'not_dispatched') {
            $denial = \App\Services\Marketplace\PriceDispatchDenied::fromServiceError($dispatch['denial']['code'] ?? null);
            if ($denial !== null) $message = $denial->getMessage();
        }
        return ['success' => false, 'status' => $status, 'completion_verified' => false, 'error' => $message, 'dispatch' => $dispatch];
    }

    private function nativeItemWriteResult(?array $response, array $requested, string $kind): array
    {
        $failure = $this->nativeWriteFailure($response, 'Ozon '.$kind.' request was not confirmed');
        if ($failure !== null) return $failure;
        $rows = $response['result'] ?? null;
        $base = ['success' => false, 'status' => 'unknown', 'completion_verified' => false,
            'result' => is_array($rows) ? $rows : null, 'dispatch' => $response['_sellico_price_dispatch'] ?? null];
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== count($requested)) {
            return $base + ['error' => 'Ozon result does not cover the exact requested rows'];
        }
        $used = []; $accepted = 0; $rejected = 0; $unknown = false;
        foreach ($requested as $input) {
            $matches = [];
            if (! is_array($input) || (! isset($input['offer_id']) && ! isset($input['product_id']))) $unknown = true;
            else foreach ($rows as $index => $row) {
                if (! is_array($row) || isset($used[$index])) continue;
                $same = true;
                foreach (['offer_id', 'product_id', ...($kind === 'stocks' ? ['warehouse_id'] : [])] as $key) {
                    if (array_key_exists($key, $input) && (! array_key_exists($key, $row) || ! (is_string($row[$key]) || $key !== 'offer_id' && is_int($row[$key])) || ! (is_string($input[$key]) || $key !== 'offer_id' && is_int($input[$key])) || (string) $row[$key] !== (string) $input[$key])) $same = false;
                }
                if ($same) $matches[] = $index;
            }
            if (count($matches) !== 1) { $unknown = true; continue; }
            $index = $matches[0]; $used[$index] = true; $row = $rows[$index];
            if (($row['updated'] ?? null) === true && ($row['errors'] ?? null) === []) $accepted++;
            elseif (($row['updated'] ?? null) === false && is_array($row['errors'] ?? null) && $row['errors'] !== []) $rejected++;
            else $unknown = true;
        }
        if ($unknown || count($used) !== count($rows)) return $base + ['error' => 'Exact Ozon item receipts are incomplete or inconsistent'];
        if ($rejected > 0) return array_replace($base, ['status' => $accepted > 0 ? 'partial' : 'failed', 'error' => 'Ozon rejected one or more requested rows']);
        return array_replace($base, ['success' => true, 'status' => 'accepted']);
    }

    private function explicitExportInputs(array $products, ?int $warehouseId): array
    {
        if ($products === [] || ! array_is_list($products) || $warehouseId !== null && $warehouseId <= 0) throw new \InvalidArgumentException('Нужны товары и корректный явно выбранный склад.');
        $seen = [];
        foreach ($products as &$product) {
            if (! is_array($product) || ! is_string($product['offer_id'] ?? null) || trim($product['offer_id']) === '' || isset($seen[$product['offer_id']])) throw new \InvalidArgumentException('Нужны непустые уникальные артикулы товаров.');
            $seen[$product['offer_id']] = true;
            foreach (['price', 'old_price'] as $key) {
                if ($key === 'old_price' && ! array_key_exists($key, $product)) continue;
                $value = $product[$key] ?? null;
                $text = is_string($value) || is_int($value) ? (string) $value : (is_float($value) && is_finite($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null);
                if (! is_string($text) || strlen($text) > 64 || ! preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $text) || $key === 'price' && ! preg_match('/[1-9]/', $text)) throw new \InvalidArgumentException('Нужна явная положительная цена товара; старая цена при наличии должна быть точной неотрицательной суммой.');
                $product[$key] = $text;
            }
            if (! is_string($product['currency_code'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $product['currency_code'])) throw new \InvalidArgumentException('Нужна явная валюта цены товара.');
            if (array_key_exists('stock', $product)) {
                if ($warehouseId === null) throw new \InvalidArgumentException('Для явно заданного остатка нужно явно выбрать склад.');
                $value = $product['stock'];
                if (! is_int($value) || $value < 0) throw new \InvalidArgumentException('Остаток при наличии должен быть явным неотрицательным целым числом.');
            }
        }
        unset($product);
        return $products;
    }

    private function unfinishedExport(array $result, array $step): array
    {
        $result['status'] = ($step['status'] ?? 'unknown') === 'unknown' ? 'unknown'
            : ($result['accepted_for_import'] > 0 ? 'partial' : ($step['status'] ?? 'failed'));
        $result['errors'][] = $step['error'] ?? 'Ozon did not confirm a requested export step';
        return $result;
    }
}
