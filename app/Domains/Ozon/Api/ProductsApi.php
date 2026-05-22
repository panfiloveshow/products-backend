<?php

namespace App\Domains\Ozon\Api;

use App\Domains\Marketplace\Contracts\ProductsApiInterface;
use App\Models\Integration;

/**
 * API для работы с товарами Ozon
 * 
 * Endpoints для чтения:
 * - POST /v3/product/list - список товаров
 * - POST /v2/product/info - информация о товаре
 * - POST /v5/product/info/prices - цены товаров
 * 
 * Endpoints для записи (выгрузка на маркетплейс):
 * - POST /v3/product/import - создание/обновление товаров
 * - POST /v1/product/import/stocks - обновление остатков FBS
 * - POST /v1/product/pictures/import - загрузка изображений
 * - POST /v4/product/info/prices - обновление цен
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
            'total' => $response['result']['total'] ?? 0,
            'last_id' => $response['result']['last_id'] ?? '',
        ];
    }

    /**
     * Получить товар по SKU (offer_id)
     */
    public function getProductBySku(string $sku, ?Integration $integration = null): ?array
    {
        $response = $this->client->post('/v2/product/info', [
            'offer_id' => $sku,
        ]);

        return $response['result'] ?? null;
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

            // v5 API возвращает items напрямую, cursor вместо last_id
            $items = $response['items'] ?? [];
            $cursor = $response['cursor'] ?? '';

            foreach ($items as $item) {
                $sku = $item['offer_id'] ?? null;
                if (!$sku) continue;

                if (!empty($skus) && !in_array($sku, $skus)) continue;

                // v5 API: price данные в item['price']
                $priceData = $item['price'] ?? [];
                
                $price = (float) ($priceData['price'] ?? 0);
                $oldPrice = (float) ($priceData['old_price'] ?? 0);
                $marketingSellerPrice = (float) ($priceData['marketing_seller_price'] ?? 0);
                
                // Актуальная цена = marketing_seller_price если есть акция, иначе price
                // marketing_seller_price — это цена с учётом всех скидок и акций
                $actualPrice = ($marketingSellerPrice > 0 && $marketingSellerPrice < $price) 
                    ? $marketingSellerPrice 
                    : $price;
                
                // Определяем, участвует ли товар в акции
                $isInPromotion = $marketingSellerPrice > 0 && $marketingSellerPrice < $price;
                $promotionDiscount = $isInPromotion 
                    ? round((1 - $marketingSellerPrice / $price) * 100, 1) 
                    : 0;
                
                $allPrices[$sku] = [
                    'product_id' => $item['product_id'] ?? null,
                    'price' => $price,                           // Базовая цена без скидок
                    'old_price' => $oldPrice,                    // Зачёркнутая цена (маркетинговая)
                    'min_price' => (float) ($priceData['min_price'] ?? 0),
                    'marketing_seller_price' => $marketingSellerPrice, // Цена с акцией
                    'actual_price' => $actualPrice,              // Действующая цена (с учётом акций)
                    'is_in_promotion' => $isInPromotion,         // Участвует в акции
                    'promotion_discount' => $promotionDiscount,  // Процент скидки
                    // Дополнительные данные из v5 API
                    'commissions' => $item['commissions'] ?? [],
                    'volume_weight' => (float) ($item['volume_weight'] ?? 0),
                ];
            }

        } while (!empty($items) && !empty($cursor));

        return $allPrices;
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
                    'strategy_competitor_id' => $info['strategy_competitor_id'] ?? null,
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

        return $response['result']['items'] ?? $response['items'] ?? [];
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
        $response = $this->client->post('/v3/product/import', [
            'items' => $products,
        ]);

        if (!$response) {
            return [
                'success' => false,
                'error' => 'Failed to import products to Ozon',
            ];
        }

        return [
            'success' => true,
            'task_id' => $response['result']['task_id'] ?? null,
            'result' => $response['result'] ?? [],
        ];
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
        $response = $this->client->post('/v1/product/import/prices', [
            'prices' => $prices,
        ]);

        if (!$response) {
            return [
                'success' => false,
                'error' => 'Failed to update prices on Ozon',
            ];
        }

        return [
            'success' => true,
            'result' => $response['result'] ?? [],
        ];
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
        $response = $this->client->post('/v2/products/stocks', [
            'stocks' => $stocks,
        ]);

        if (!$response) {
            return [
                'success' => false,
                'error' => 'Failed to update stocks on Ozon',
            ];
        }

        return [
            'success' => true,
            'result' => $response['result'] ?? [],
        ];
    }

    /**
     * Загрузить изображения товара
     * 
     * POST /v1/product/pictures/import
     * 
     * @param string $productId ID товара на Ozon
     * @param array $images Массив URL изображений
     * @return array Результат загрузки
     */
    public function importImages(string $productId, array $images): array
    {
        $response = $this->client->post('/v1/product/pictures/import', [
            'product_id' => (int) $productId,
            'images' => $images,
        ]);

        if (!$response) {
            return [
                'success' => false,
                'error' => 'Failed to import images to Ozon',
            ];
        }

        return [
            'success' => true,
            'result' => $response['result'] ?? [],
        ];
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

        if (!$response) {
            return [
                'success' => false,
                'error' => 'Failed to update attributes on Ozon',
            ];
        }

        return [
            'success' => true,
            'result' => $response['result'] ?? [],
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
        $results = [
            'success' => true,
            'steps' => [],
        ];

        // 1. Импорт основных данных товара
        $importResult = $this->importProducts([$product]);
        $results['steps']['import'] = $importResult;
        
        if (!$importResult['success']) {
            $results['success'] = false;
            return $results;
        }

        // 2. Обновление цен (если указаны)
        if (isset($product['price'])) {
            $priceData = [
                'offer_id' => $product['offer_id'],
                'price' => (string) $product['price'],
                'currency_code' => $product['currency_code'] ?? 'RUB',
            ];
            
            if (isset($product['old_price'])) {
                $priceData['old_price'] = (string) $product['old_price'];
            }
            
            $priceResult = $this->updatePrices([$priceData]);
            $results['steps']['prices'] = $priceResult;
        }

        // 3. Обновление остатков (если указан склад)
        if ($warehouseId && isset($product['stock'])) {
            $stockResult = $this->updateStocks([
                [
                    'offer_id' => $product['offer_id'],
                    'stock' => (int) $product['stock'],
                    'warehouse_id' => $warehouseId,
                ]
            ]);
            $results['steps']['stocks'] = $stockResult;
        }

        return $results;
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
        $results = [
            'success' => true,
            'total' => count($products),
            'imported' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        // Импортируем товары батчами по 100 штук (лимит Ozon API)
        $batches = array_chunk($products, 100);
        
        foreach ($batches as $batch) {
            $importResult = $this->importProducts($batch);
            
            if ($importResult['success']) {
                $results['imported'] += count($batch);
            } else {
                $results['failed'] += count($batch);
                $results['errors'][] = $importResult['error'] ?? 'Unknown error';
            }
        }

        // Обновляем цены
        $prices = array_map(function ($product) {
            return [
                'offer_id' => $product['offer_id'],
                'price' => (string) ($product['price'] ?? 0),
                'old_price' => (string) ($product['old_price'] ?? 0),
                'currency_code' => $product['currency_code'] ?? 'RUB',
            ];
        }, $products);

        $priceBatches = array_chunk($prices, 1000);
        foreach ($priceBatches as $batch) {
            $this->updatePrices($batch);
        }

        // Обновляем остатки если указан склад
        if ($warehouseId) {
            $stocks = array_map(function ($product) use ($warehouseId) {
                return [
                    'offer_id' => $product['offer_id'],
                    'stock' => (int) ($product['stock'] ?? 0),
                    'warehouse_id' => $warehouseId,
                ];
            }, $products);

            $stockBatches = array_chunk($stocks, 100);
            foreach ($stockBatches as $batch) {
                $this->updateStocks($batch);
            }
        }

        $results['success'] = $results['failed'] === 0;
        return $results;
    }
}
