<?php

namespace App\Domains\Ozon;

use App\Domains\Marketplace\Contracts\MarketplaceInterface;
use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\ProductsApi;
use App\Domains\Ozon\Api\InventoryApi;
use App\Domains\Ozon\Api\SalesApi;
use App\Domains\Ozon\Api\AnalyticsApi;
use App\Domains\Ozon\Api\WarehousesApi;
use App\Domains\Ozon\Api\CategoriesApi;
use App\Domains\Ozon\Api\StorageApi;
use App\Domains\Ozon\Api\SuppliesApi;
use App\Domains\Ozon\Api\FboSupplyOrdersApi;
use App\Domains\Ozon\Api\FboCargoesApi;
use App\Domains\Ozon\Api\FboPostingsApi;
use App\Domains\Ozon\Api\FbsPostingsApi;
use App\Domains\Ozon\Api\FbsReturnsApi;
use App\Models\Integration;

/**
 * Фасад для работы с Ozon API
 * 
 * Объединяет все компоненты:
 * - ProductsApi — товары
 * - InventoryApi — остатки
 * - SalesApi — продажи
 * - AnalyticsApi — аналитика, Premium
 * - WarehousesApi — склады
 * - CategoriesApi — категории
 * - StorageApi — хранение, тарифы
 */
class OzonMarketplace implements MarketplaceInterface
{
    private OzonClient $client;
    private ProductsApi $products;
    private InventoryApi $inventory;
    private SalesApi $sales;
    private AnalyticsApi $analytics;
    private WarehousesApi $warehouses;
    private CategoriesApi $categories;
    private StorageApi $storage;
    private SuppliesApi $supplies;
    private FboSupplyOrdersApi $fboSupplyOrders;
    private FboCargoesApi $fboCargoes;
    private FboPostingsApi $fboPostings;
    private FbsPostingsApi $fbsPostings;
    private FbsReturnsApi $fbsReturns;
    private ?Integration $integration;

    public function __construct(array $credentials = [], ?Integration $integration = null)
    {
        $clientId = $credentials['client_id'] ?? config('services.ozon.client_id');
        $apiKey = $credentials['api_key'] ?? config('services.ozon.api_key');

        $this->client = new OzonClient($clientId, $apiKey);
        $this->products = new ProductsApi($this->client);
        $this->inventory = new InventoryApi($this->client);
        $this->sales = new SalesApi($this->client);
        $this->analytics = new AnalyticsApi($this->client);
        $this->warehouses = new WarehousesApi($this->client);
        $this->categories = new CategoriesApi($this->client);
        $this->storage = new StorageApi($this->client);
        $this->supplies = new SuppliesApi($this->client);
        $this->fboSupplyOrders = new FboSupplyOrdersApi($this->client);
        $this->fboCargoes = new FboCargoesApi($this->client);
        $this->fboPostings = new FboPostingsApi($this->client);
        $this->fbsPostings = new FbsPostingsApi($this->client);
        $this->fbsReturns = new FbsReturnsApi($this->client);
        $this->integration = $integration;
    }

    /**
     * Создать экземпляр из Integration модели
     */
    public static function fromIntegration(Integration $integration): self
    {
        return new self($integration->getDecryptedCredentials(), $integration);
    }

    // === MarketplaceInterface ===

    public function getName(): string
    {
        return 'Ozon';
    }

    public function getCode(): string
    {
        return 'ozon';
    }

    public function testConnection(Integration $integration): bool
    {
        try {
            $products = $this->products->getProducts(1);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getSupportedSchemes(): array
    {
        return ['FBO', 'FBS', 'RFBS', 'EXPRESS'];
    }

    // === Products ===

    /**
     * Получить все товары в формате Product модели
     * Аналогично WildberriesMarketplace: пагинация + маппинг
     */
    public function getProducts(): array
    {
        $allProducts = [];
        $allOfferIds = [];
        $lastId = '';
        
        // Получаем комиссии для обогащения данных
        $commissions = $this->categories->getCommissions();
        
        // Получаем цены для всех товаров
        $prices = $this->products->getPrices();
        
        \Log::debug('Ozon getProducts: prices loaded', ['count' => count($prices)]);
        
        // Получаем остатки для всех товаров и преобразуем в ассоциативный массив по offer_id
        // Передаем Integration для определения схемы работы (FBO/FBS/RFBS/EXPRESS)
        $stocksRaw = $this->inventory->getStocks($this->integration);
        $stocks = [];
        foreach ($stocksRaw as $stockItem) {
            // getStocks возвращает массив с ключами: sku, product_id, warehouses, total, reserved, fulfillment_type
            $sku = $stockItem['sku'] ?? $stockItem['offer_id'] ?? null;
            if ($sku) {
                $stocks[$sku] = (int) ($stockItem['total'] ?? 0);
            }
        }
        
        \Log::debug('Ozon getProducts: stocks loaded', ['count' => count($stocks)]);
        
        do {
            $result = $this->products->getProducts(null, [
                'limit' => 100,
                'last_id' => $lastId,
            ]);
            
            $items = $result['items'] ?? [];
            $lastId = $result['last_id'] ?? '';
            
            \Log::debug('Ozon getProducts: batch loaded', ['items_count' => count($items), 'last_id' => $lastId]);
            
            if (empty($items)) {
                break;
            }
            
            // Получаем подробную информацию о товарах через v4 API
            $productIds = array_column($items, 'product_id');
            $detailedProducts = $this->products->getProductsByIds($productIds);
            
            \Log::debug('Ozon getProducts: detailed loaded', [
                'product_ids_count' => count($productIds),
                'detailed_count' => count($detailedProducts),
                'first_detailed' => !empty($detailedProducts) ? array_keys($detailedProducts[0] ?? []) : [],
            ]);
            
            // Индексируем по product_id для быстрого доступа
            $detailedByProductId = [];
            foreach ($detailedProducts as $product) {
                $pid = $product['id'] ?? null;
                if ($pid) {
                    $detailedByProductId[$pid] = $product;
                }
            }
            
            // Маппим к формату Product модели (описания загрузим позже)
            foreach ($items as $item) {
                $productId = $item['product_id'] ?? null;
                if (!$productId) continue;
                
                $detailed = $detailedByProductId[$productId] ?? [];
                $offerId = $detailed['offer_id'] ?? $item['offer_id'] ?? '';
                
                // Собираем offer_ids для загрузки описаний
                if ($offerId) {
                    $allOfferIds[] = $offerId;
                }
                
                // Описание и рейтинг пока пустые — загрузим после основного цикла
                $mapped = $this->mapProductToModel($item, $detailed, $prices, $commissions, $stocks, '');
                
                $allProducts[] = $mapped;
            }
            
        } while (!empty($items) && !empty($lastId));
        
        // Загружаем рейтинги карточек для всех товаров
        // API /v1/product/rating-by-sku ожидает числовые SKU из ozon_data.sku
        // Рейтинг возвращается в поле rating (0-100), groups не используем
        $ratings = [];
        if (!empty($allProducts)) {
            // Собираем SKU из ozon_data.sku (правильный числовой SKU Ozon)
            $skuMap = []; // ozon_sku => index в allProducts
            $ozonSkus = [];
            foreach ($allProducts as $index => $product) {
                $ozonSku = $product['ozon_data']['sku'] ?? null;
                if ($ozonSku) {
                    $ozonSkus[] = $ozonSku;
                    $skuMap[$ozonSku] = $index;
                }
            }
            
            if (!empty($ozonSkus)) {
                $ratings = $this->analytics->getProductRatingsBySku($ozonSkus);
                \Log::info('Ozon getProducts: ratings loaded', [
                    'count' => count($ratings),
                    'total_products' => count($ozonSkus),
                    'first_3_skus' => array_slice($ozonSkus, 0, 3),
                ]);
            }
            
            // Обновляем товары с рейтингами (сопоставляем по ozon_data.sku)
            foreach ($allProducts as &$product) {
                $ozonSku = $product['ozon_data']['sku'] ?? null;
                if ($ozonSku && isset($ratings[$ozonSku])) {
                    // Рейтинг уже в диапазоне 0-100, сохраняем как есть
                    $product['rating'] = $ratings[$ozonSku];
                }
            }
            unset($product);
        }
        
        // Загружаем комиссии через v3 API (там реальные комиссии для каждой схемы)
        if (!empty($allOfferIds) && !empty($allProducts)) {
            \Log::debug('Ozon getProducts: loading commissions', ['offer_ids_count' => count($allOfferIds)]);
            
            $allCommissions = $this->products->getProductsWithCommissions($allOfferIds);
            
            // Обновляем товары с реальными комиссиями
            foreach ($allProducts as &$product) {
                $offerId = $product['sku'] ?? '';
                if (isset($allCommissions[$offerId])) {
                    $productCommissions = $allCommissions[$offerId];
                    $product['ozon_data']['commissions'] = [
                        'fbo' => [
                            'percent' => $productCommissions['fbo']['percent'] ?? 15,
                            'category_id' => $product['ozon_data']['category_id'] ?? 0,
                            'delivery_amount' => $productCommissions['fbo']['delivery_amount'] ?? 0,
                            'return_amount' => $productCommissions['fbo']['return_amount'] ?? 0,
                        ],
                        'fbs' => [
                            'percent' => $productCommissions['fbs']['percent'] ?? 15,
                            'category_id' => $product['ozon_data']['category_id'] ?? 0,
                            'delivery_amount' => $productCommissions['fbs']['delivery_amount'] ?? 0,
                            'return_amount' => $productCommissions['fbs']['return_amount'] ?? 0,
                        ],
                        'rfbs' => [
                            'percent' => $productCommissions['rfbs']['percent'] ?? 15,
                            'category_id' => $product['ozon_data']['category_id'] ?? 0,
                        ],
                        'express' => [
                            'percent' => $productCommissions['fbp']['percent'] ?? $productCommissions['express']['percent'] ?? 15,
                            'category_id' => $product['ozon_data']['category_id'] ?? 0,
                        ],
                    ];
                }
            }
            unset($product);
            
            \Log::info('Ozon getProducts: commissions updated', ['count' => count($allCommissions)]);
        }
        
        // Загружаем описания для всех товаров (после основного цикла для оптимизации)
        // API Ozon требует отдельный запрос на каждый offer_id, поэтому делаем это батчами
        if (!empty($allOfferIds) && !empty($allProducts)) {
            \Log::debug('Ozon getProducts: loading descriptions', ['offer_ids_count' => count($allOfferIds)]);
            
            // Загружаем описания батчами по 50 для ограничения нагрузки
            $descriptionBatches = array_chunk($allOfferIds, 50);
            $allDescriptions = [];
            
            foreach ($descriptionBatches as $batch) {
                $batchDescriptions = $this->products->getProductDescriptions($batch);
                $allDescriptions = array_merge($allDescriptions, $batchDescriptions);
            }
            
            \Log::debug('Ozon getProducts: descriptions loaded', ['count' => count($allDescriptions)]);
            
            // Обновляем товары с описаниями
            foreach ($allProducts as &$product) {
                $offerId = $product['sku'] ?? '';
                if (isset($allDescriptions[$offerId])) {
                    $product['description'] = $allDescriptions[$offerId];
                }
            }
            unset($product);
            
            // Логируем первый товар после загрузки описаний
            if (!empty($allProducts)) {
                $firstProduct = $allProducts[0];
                \Log::debug('Ozon getProducts: first product mapped', [
                    'name' => $firstProduct['name'] ?? 'EMPTY',
                    'images_count' => count($firstProduct['images'] ?? []),
                    'description_length' => strlen($firstProduct['description'] ?? ''),
                    'has_detailed' => true,
                    'detailed_name' => $firstProduct['name'] ?? 'N/A',
                    'detailed_images' => $firstProduct['images'] ?? [],
                ]);
            }
        }
        
        return $allProducts;
    }
    
    /**
     * Маппинг Ozon товара к формату Product модели
     * 
     * Данные из v4 API содержат:
     * @param array $stocks Остатки
     * @param string $description Описание из /v1/product/info/description
     */
    private function mapProductToModel(array $item, array $detailed, array $prices, array $commissions, array $stocks = [], string $description = ''): array
    {
        $offerId = $detailed['offer_id'] ?? $item['offer_id'] ?? '';
        $productId = $detailed['id'] ?? $item['product_id'] ?? 0;
        
        // Получаем остатки — сначала из detailed (v4 API), потом из переданного массива
        $stock = 0;
        if (!empty($detailed['stocks']['stocks'])) {
            foreach ($detailed['stocks']['stocks'] as $stockItem) {
                $stock += (int) ($stockItem['present'] ?? 0);
            }
            // Логируем первый товар для отладки структуры остатков
            static $stocksLogged = false;
            if (!$stocksLogged) {
                \Log::debug('Ozon mapProductToModel: stocks from v4 API', [
                    'offer_id' => $offerId,
                    'stocks_structure' => $detailed['stocks'],
                    'calculated_stock' => $stock,
                ]);
                $stocksLogged = true;
            }
        } elseif (isset($stocks[$offerId])) {
            $stock = (int) $stocks[$offerId];
        }
        
        // Цены — сначала из detailed (v4 API), потом из prices API
        $priceData = $prices[$offerId] ?? [];
        $basePrice = (float) ($detailed['price'] ?? $priceData['price'] ?? 0);
        $oldPrice = (float) ($detailed['old_price'] ?? $priceData['old_price'] ?? 0);
        
        // Актуальная цена с учётом акций (marketing_seller_price)
        // actual_price уже рассчитан в getPrices() — содержит marketing_seller_price если акция
        $actualPrice = (float) ($priceData['actual_price'] ?? $basePrice);
        $marketingSellerPrice = (float) ($priceData['marketing_seller_price'] ?? 0);
        $isInPromotion = $priceData['is_in_promotion'] ?? false;
        $promotionDiscount = $priceData['promotion_discount'] ?? 0;
        
        // Для отображения используем актуальную цену (с учётом акций)
        $price = $actualPrice;
        
        // Категория — получаем название по ID
        $categoryId = $detailed['description_category_id'] ?? 0;
        $category = '';
        if ($categoryId) {
            $category = $this->categories->getCategoryName($categoryId) ?? '';
        }
        
        // Комиссии из v3/v4 API — сохраняем для каждой схемы отдельно
        $commissionsData = [
            'fbo' => ['percent' => 15, 'category_id' => $categoryId],
            'fbs' => ['percent' => 15, 'category_id' => $categoryId],
            'rfbs' => ['percent' => 15, 'category_id' => $categoryId],
            'express' => ['percent' => 15, 'category_id' => $categoryId],
        ];
        
        if (!empty($detailed['commissions'])) {
            foreach ($detailed['commissions'] as $comm) {
                $schema = strtolower($comm['sale_schema'] ?? '');
                $percent = (float) ($comm['percent'] ?? 15);
                
                if ($schema === 'fbo') {
                    $commissionsData['fbo']['percent'] = $percent;
                    $commissionsData['fbo']['delivery_amount'] = $comm['delivery_amount'] ?? 0;
                    $commissionsData['fbo']['return_amount'] = $comm['return_amount'] ?? 0;
                } elseif ($schema === 'fbs') {
                    $commissionsData['fbs']['percent'] = $percent;
                    $commissionsData['fbs']['delivery_amount'] = $comm['delivery_amount'] ?? 0;
                    $commissionsData['fbs']['return_amount'] = $comm['return_amount'] ?? 0;
                } elseif ($schema === 'rfbs') {
                    $commissionsData['rfbs']['percent'] = $percent;
                } elseif ($schema === 'fbp' || $schema === 'express') {
                    $commissionsData['express']['percent'] = $percent;
                }
            }
        } else {
            // Fallback на переданные комиссии из дерева категорий
            foreach ($commissions as $commData) {
                if (isset($commData['category_id']) && $commData['category_id'] == $categoryId) {
                    $fallbackPercent = (float) ($commData['commission_percent'] ?? 15);
                    $commissionsData['fbo']['percent'] = $fallbackPercent;
                    $commissionsData['fbs']['percent'] = $fallbackPercent;
                    $commissionsData['rfbs']['percent'] = $fallbackPercent;
                    $commissionsData['express']['percent'] = $fallbackPercent;
                    break;
                }
            }
        }
        
        // Изображения из v4 API
        // primary_image - главное фото карточки (вертикальное, основное)
        // images[] - массив дополнительных фото
        // color_image - цветовое изображение
        $images = [];
        
        // 1. Главное изображение карточки (primary_image) - ОБЯЗАТЕЛЬНО первым
        if (!empty($detailed['primary_image'])) {
            $primaryImage = $detailed['primary_image'];
            if (is_string($primaryImage)) {
                $images[] = $primaryImage;
            }
        }
        
        // 2. Цветовое изображение (color_image) - если есть и отличается от primary
        if (!empty($detailed['color_image'])) {
            $colorImage = $detailed['color_image'];
            if (is_string($colorImage) && !in_array($colorImage, $images)) {
                $images[] = $colorImage;
            }
        }
        
        // 3. Дополнительные изображения из массива images (второстепенные)
        if (!empty($detailed['images'])) {
            $additionalImages = is_array($detailed['images']) ? $detailed['images'] : [$detailed['images']];
            foreach ($additionalImages as $img) {
                if (is_string($img) && !in_array($img, $images)) {
                    $images[] = $img;
                }
            }
        }
        
        // Логируем структуру изображений для первого товара
        static $imagesLogged = false;
        if (!$imagesLogged && !empty($images)) {
            \Log::info('Ozon images structure', [
                'offer_id' => $offerId,
                'has_primary_image' => !empty($detailed['primary_image']),
                'has_color_image' => !empty($detailed['color_image']),
                'has_images_array' => !empty($detailed['images']),
                'images_count' => count($images),
                'first_image' => $images[0] ?? null,
            ]);
            $imagesLogged = true;
        }
        
        // Название
        $name = $detailed['name'] ?? $item['name'] ?? '';
        
        // Штрихкоды
        $barcodes = $detailed['barcodes'] ?? [];
        $barcode = !empty($barcodes) ? $barcodes[0] : null;
        
        // Атрибуты (характеристики) из v4 API — сырые данные для ozon_data
        $rawAttributes = $detailed['attributes'] ?? [];
        $complexAttributes = $detailed['complex_attributes'] ?? [];
        
        // type_id нужен для получения названий атрибутов
        $typeId = $detailed['type_id'] ?? 0;
        
        // Форматируем характеристики в читаемый вид [{name, value}]
        $formattedCharacteristics = [];
        if ($categoryId && $typeId && !empty($rawAttributes)) {
            $formattedCharacteristics = $this->categories->formatAttributes($rawAttributes, $categoryId, $typeId);
        }

        $dimensions = $this->extractDimensions($detailed, $formattedCharacteristics);
        $depth = $dimensions['length_mm'];
        $width = $dimensions['width_mm'];
        $height = $dimensions['height_mm'];
        $weight = $dimensions['weight_g'];

        // Объёмный вес
        $volumeWeight = $detailed['volume_weight'] ?? $priceData['volume_weight'] ?? null;
        $volumeLiters = null;
        if ($depth && $width && $height) {
            $volumeLiters = ($depth * $width * $height) / 1000000; // мм³ → л
        }
        
        // Извлекаем бренд из атрибутов (attribute_id = 85 или 31 для бренда)
        $brand = '';
        foreach ($rawAttributes as $attr) {
            $attrId = $attr['attribute_id'] ?? $attr['id'] ?? 0;
            if (in_array($attrId, [85, 31, 8229])) { // ID атрибутов бренда
                $values = $attr['values'] ?? [];
                if (!empty($values[0]['value'])) {
                    $brand = $values[0]['value'];
                    break;
                }
            }
        }
        
        return [
            'marketplace_id' => (string) $productId,
            'sku' => $offerId,
            'vendor_code' => $offerId,
            'name' => $name,
            'description' => $description,
            'brand' => $brand,
            'category' => $category,
            'price' => $price,
            'old_price' => $oldPrice > $price ? $oldPrice : null,
            'stock' => $stock,
            'rating' => null, // Будет обновлен после загрузки рейтингов
            'reviews_count' => 0, // Ozon API не предоставляет количество отзывов в Seller API
            'images' => $images,
            'barcode' => $barcode,
            'characteristics' => $formattedCharacteristics,
            // Габариты
            'depth' => $depth,
            'width' => $width,
            'height' => $height,
            'weight' => $weight,
            'volume_weight' => $volumeWeight,
            // Ozon-специфичные данные (сырые атрибуты для экспорта)
            'ozon_data' => [
                'product_id' => $productId,
                'offer_id' => $offerId,
                'sku' => $detailed['sku'] ?? null,
                'fbo_sku' => $item['fbo_sku'] ?? null,
                'fbs_sku' => $item['fbs_sku'] ?? null,
                'category_id' => $categoryId,
                'type_id' => $detailed['type_id'] ?? null,
                'attributes' => $rawAttributes,
                'complex_attributes' => $complexAttributes,
                'barcodes' => $barcodes,
                'dimensions' => [
                    'depth' => $depth,
                    'width' => $width,
                    'height' => $height,
                    'weight' => $weight,
                    'dimension_unit' => 'mm',
                    'weight_unit' => 'g',
                ],
                'length_mm' => $depth,
                'width_mm' => $width,
                'height_mm' => $height,
                'weight_g' => $weight,
                'volume_liters' => $volumeLiters,
                'volume_weight' => $volumeWeight,
                'commissions' => $commissionsData,
                'price' => $basePrice,                           // Базовая цена без скидок
                'actual_price' => $actualPrice,                  // Действующая цена (с учётом акций)
                'marketing_seller_price' => $marketingSellerPrice, // Цена с акцией из API
                'old_price' => $oldPrice,                        // Зачёркнутая цена
                'is_in_promotion' => $isInPromotion,             // Участвует в акции
                'promotion_discount' => $promotionDiscount,      // Процент скидки
                'visibility' => $item['is_discounted'] ?? false,
                'primary_image' => $detailed['primary_image'] ?? null,
                'color_image' => $detailed['color_image'] ?? null,
            ],
        ];
    }

    private function extractDimensions(array $detailed, array $formattedCharacteristics): array
    {
        $dimensionUnit = $detailed['dimension_unit'] ?? $detailed['dimensions']['dimension_unit'] ?? 'mm';
        $weightUnit = $detailed['weight_unit'] ?? $detailed['dimensions']['weight_unit'] ?? 'g';

        $length = $this->normalizeDimension(
            $detailed['depth']
                ?? $detailed['length']
                ?? $detailed['dimensions']['depth']
                ?? $detailed['dimensions']['length']
                ?? null,
            $dimensionUnit
        );
        $width = $this->normalizeDimension(
            $detailed['width'] ?? $detailed['dimensions']['width'] ?? null,
            $dimensionUnit
        );
        $height = $this->normalizeDimension(
            $detailed['height'] ?? $detailed['dimensions']['height'] ?? null,
            $dimensionUnit
        );
        $weight = $this->normalizeWeight(
            $detailed['weight'] ?? $detailed['dimensions']['weight'] ?? null,
            $weightUnit
        );

        if (! $length) {
            $length = $this->normalizeDimension(
                $this->characteristicValue($formattedCharacteristics, ['Глубина упаковки', 'Длина упаковки', 'Длина']),
                'mm'
            );
        }
        if (! $width) {
            $width = $this->normalizeDimension(
                $this->characteristicValue($formattedCharacteristics, ['Ширина упаковки', 'Ширина']),
                'mm'
            );
        }
        if (! $height) {
            $height = $this->normalizeDimension(
                $this->characteristicValue($formattedCharacteristics, ['Высота упаковки', 'Высота']),
                'mm'
            );
        }
        if (! $weight) {
            $weight = $this->normalizeWeight(
                $this->characteristicValue($formattedCharacteristics, ['Вес', 'Вес товара, г', 'Вес товара', 'Вес с упаковкой']),
                'g'
            );
        }

        return [
            'length_mm' => $length,
            'width_mm' => $width,
            'height_mm' => $height,
            'weight_g' => $weight,
        ];
    }

    private function characteristicValue(array $characteristics, array $names): mixed
    {
        foreach ($characteristics as $key => $characteristic) {
            if (is_array($characteristic)) {
                $name = (string) ($characteristic['name'] ?? $key);
                if (in_array($name, $names, true)) {
                    return $characteristic['value'] ?? null;
                }

                continue;
            }

            if (in_array((string) $key, $names, true)) {
                return $characteristic;
            }
        }

        return null;
    }

    private function normalizeDimension(mixed $value, ?string $unit): ?float
    {
        $value = $this->numericValue($value);
        if ($value === null || $value <= 0) {
            return null;
        }

        return match (strtolower((string) $unit)) {
            'cm', 'centimeter', 'centimeters', 'сm', 'см' => round($value * 10, 2),
            'm', 'meter', 'meters', 'м' => round($value * 1000, 2),
            default => round($value, 2),
        };
    }

    private function normalizeWeight(mixed $value, ?string $unit): ?float
    {
        $value = $this->numericValue($value);
        if ($value === null || $value <= 0) {
            return null;
        }

        return match (strtolower((string) $unit)) {
            'kg', 'kilogram', 'kilograms', 'кг' => round($value * 1000, 2),
            default => round($value, 2),
        };
    }

    private function numericValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            $value = $value['value'] ?? reset($value);
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $numeric = preg_replace('/[^\d.,]/', '', (string) $value);
        $numeric = str_replace(',', '.', $numeric);

        return $numeric !== '' ? (float) $numeric : null;
    }

    public function getProductPrices(): array
    {
        return $this->products->getPrices();
    }

    public function getPricingStrategyProductInfo(array $productIds, int $maxRequests = 500, int $sleepMicros = 120000): array
    {
        return $this->products->getPricingStrategyProductInfo($productIds, $maxRequests, $sleepMicros);
    }

    // === Export to Marketplace ===

    /**
     * Экспортировать товары на Ozon
     * 
     * @param array $products Массив товаров для экспорта
     * @param int|null $warehouseId ID склада FBS для остатков
     * @return array Результат экспорта
     */
    public function exportProducts(array $products, ?int $warehouseId = null): array
    {
        return $this->products->exportProducts($products, $warehouseId);
    }

    /**
     * Экспортировать один товар на Ozon
     */
    public function exportProduct(array $product, ?int $warehouseId = null): array
    {
        return $this->products->exportProduct($product, $warehouseId);
    }

    /**
     * Обновить цены товаров на Ozon
     */
    public function updatePrices(array $prices): array
    {
        return $this->products->updatePrices($prices);
    }

    /**
     * Обновить остатки товаров на Ozon (FBS)
     */
    public function updateStocks(array $stocks): array
    {
        return $this->products->updateStocks($stocks);
    }

    /**
     * Загрузить изображения товара на Ozon
     */
    public function importImages(string $productId, array $images): array
    {
        return $this->products->importImages($productId, $images);
    }

    /**
     * Обновить атрибуты товаров на Ozon
     */
    public function updateAttributes(array $items): array
    {
        return $this->products->updateAttributes($items);
    }

    /**
     * Получить дерево категорий Ozon
     */
    public function getProductCategoryTree(): array
    {
        return $this->products->getCategoryTree();
    }

    /**
     * Получить атрибуты категории Ozon
     */
    public function getCategoryAttributes(int $categoryId): array
    {
        return $this->products->getCategoryAttributes($categoryId);
    }

    /**
     * Получить статус импорта товаров
     */
    public function getImportStatus(int $taskId): array
    {
        return $this->products->getImportStatus($taskId);
    }

    // === Inventory ===

    public function getInventory(): array
    {
        return $this->inventory->getStocks($this->integration);
    }

    /**
     * Остатки по каждому реальному FBO-складу Ozon через /v2/analytics/stock_on_warehouses
     */
    public function getInventoryPerWarehouse(): array
    {
        return $this->inventory->getStocksPerWarehouse();
    }

    /**
     * Остатки по FBS-складам продавца через /v1/product/info/stocks-by-warehouse/fbs
     * Возвращает формат, совместимый с SyncInventoryJob:
     * [['sku' => offer_id, 'warehouses' => [...], 'fulfillment_type' => 'FBS']]
     */
    public function getInventoryFbsPerWarehouse(): array
    {
        $items = $this->inventory->getStocksForFbsSchemes();

        // Устанавливаем fulfillment_type и правильный warehouse_id для каждого склада
        foreach ($items as &$item) {
            $item['fulfillment_type'] = 'FBS';
            foreach ($item['warehouses'] as &$wh) {
                $whId = $wh['warehouse_id'] ?? null;
                $whName = $wh['warehouse_name'] ?? '';
                // warehouse_id из API — числовой; формируем строковый ключ
                if ($whId) {
                    $wh['warehouse_id']       = 'ozonfbs_' . $whId;
                } else {
                    $wh['warehouse_id']       = 'ozonfbs_' . substr(md5($whName), 0, 12);
                }
                $wh['fulfillment_type']   = 'fbs';
                $wh['warehouse_type']     = 'fbs';
            }
            unset($wh);
        }
        unset($item);

        \Log::info('Ozon getInventoryFbsPerWarehouse: загружено FBS остатков', [
            'count' => count($items),
        ]);

        return $items;
    }

    public function getDetailedInventory(): array
    {
        return $this->warehouses->getDetailedInventory();
    }

    public function getWarehouses(): array
    {
        return $this->warehouses->getWarehouses();
    }

    // === Sales ===

    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        return $this->sales->getSalesStats($dateFrom, $dateTo);
    }

    public function getSalesBySku(int $days = 28): array
    {
        // Получаем маппинг product_id -> offer_id из БД
        $productIdToOfferId = $this->getProductIdToOfferIdMap();
        return $this->sales->getSalesBySku($days, $productIdToOfferId);
    }

    /**
     * Получить продажи по SKU и складу через /v1/analytics/data (dimension: sku + warehouse_id)
     * Возвращает [offer_id => [warehouse_id => [sales_7_days, sales_30_days, avg_daily_sales, ...]]]
     */
    public function getSalesBySkuAndWarehouse(int $days = 28): array
    {
        $productIdToOfferId = $this->getProductIdToOfferIdMap();
        return $this->sales->getSalesBySkuAndWarehouse($days, $productIdToOfferId);
    }

    public function getSalesBySkuAndWarehouseFbs(int $days = 28): array
    {
        return $this->sales->getSalesBySkuAndWarehouseFbs($days);
    }
    
    /**
     * Получить маппинг ozon_sku -> offer_id из БД
     * API аналитики может использовать разные ID: sku, fbs_sku, fbo_sku, product_id
     */
    private function getProductIdToOfferIdMap(): array
    {
        $map = [];
        
        // Получаем из БД товары с ozon_data
        $products = \App\Models\Product::where('marketplace', 'ozon')
            ->whereNotNull('ozon_data')
            ->when($this->integration, fn($q) => $q->where('integration_id', $this->integration->id))
            ->get(['sku', 'ozon_data']);
        
        foreach ($products as $product) {
            $ozonData = $product->ozon_data;
            $offerId = $product->sku;
            
            // Добавляем все возможные ключи для маппинга
            $ozonSku = $ozonData['sku'] ?? null;
            $fbsSku = $ozonData['fbs_sku'] ?? null;
            $fboSku = $ozonData['fbo_sku'] ?? null;
            $productId = $ozonData['product_id'] ?? null;
            
            if ($ozonSku) $map[(string)$ozonSku] = $offerId;
            if ($fbsSku) $map[(string)$fbsSku] = $offerId;
            if ($fboSku) $map[(string)$fboSku] = $offerId;
            if ($productId) $map[(string)$productId] = $offerId;
        }
        
        return $map;
    }

    public function getOrdersStatsBySku(string $dateFrom, string $dateTo): array
    {
        return $this->sales->getOrdersStatsBySku($dateFrom, $dateTo);
    }

    public function getReturnsStatsBySku(string $dateFrom, string $dateTo): array
    {
        return $this->sales->getReturnsStatsBySku($dateFrom, $dateTo);
    }

    public function getCancellationsStatsBySku(string $dateFrom, string $dateTo): array
    {
        return $this->sales->getCancellationsStatsBySku($dateFrom, $dateTo);
    }

    // === Analytics ===

    public function checkPremiumStatus(): array
    {
        return $this->analytics->checkPremiumStatus();
    }

    public function getRedemptionRateFromAnalytics(?string $dateFrom = null, ?string $dateTo = null, array $productIdToSkuMap = []): array
    {
        return $this->analytics->getRedemptionRateFromAnalytics($dateFrom, $dateTo, $productIdToSkuMap);
    }

    public function getAcquiringBySku(?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->analytics->getAcquiringBySku($dateFrom, $dateTo);
    }

    public function getLocalizationIndex(): array
    {
        return $this->analytics->getLocalizationIndex();
    }

    // === Warehouses ===

    public function getInTransitBySku(): array
    {
        return $this->warehouses->getInTransitBySku();
    }

    public function getReturnsBySku(): array
    {
        return $this->warehouses->getReturnsBySku();
    }

    // === Categories ===

    public function getCategoryTree(): array
    {
        return $this->categories->getCategoryTree();
    }

    public function getCategoryName(int $categoryId): ?string
    {
        return $this->categories->getCategoryName($categoryId);
    }

    public function getCommissions(): array
    {
        return $this->categories->getCommissions();
    }

    // === Storage ===

    public function getStorageCost(): array
    {
        return $this->storage->getStorageCost();
    }

    public function getStorageCostBySku(): array
    {
        return $this->storage->getStorageCostBySku();
    }

    public function getProductTariffs(array $productIds = []): array
    {
        return $this->storage->getProductTariffs($productIds);
    }

    public function getActualCostsBySku(?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->storage->getActualCostsBySku($dateFrom, $dateTo);
    }

    /**
     * Получить стоимость размещения (хранения) по товарам за период
     * Использует новый API /v1/report/placement/by-products/create
     */
    public function getPlacementCostByProducts(string $dateFrom, string $dateTo, int $maxWaitSeconds = 60): array
    {
        return $this->storage->getPlacementCostByProducts($dateFrom, $dateTo, $maxWaitSeconds);
    }

    /**
     * Получить общую сумму хранения из cash-flow-statement (без привязки к SKU)
     * Использует /v1/finance/cash-flow-statement/list — совпадает с ЛК Ozon
     */
    public function getStorageTotalFromCashFlow(string $dateFrom, string $dateTo): array
    {
        return $this->storage->getStorageTotalFromCashFlow($dateFrom, $dateTo);
    }

    /**
     * Получить финансовые транзакции по хранению за период
     * Использует /v3/finance/transaction/list с фильтром по типу операции
     * Автоматически конвертирует product_id в offer_id (SKU продавца)
     */
    public function getStorageTransactions(string $dateFrom, string $dateTo): array
    {
        // Создаём маппинг ozon_sku → offer_id из Product
        // API /v3/finance/transaction/list возвращает числовой SKU Ozon (ozon_data.sku), не product_id
        $ozonSkuToOfferId = [];
        if ($this->integration) {
            $products = \App\Models\Product::where('integration_id', $this->integration->id)
                ->whereNotNull('ozon_data')
                ->get(['sku', 'ozon_data']);
            
            foreach ($products as $product) {
                $ozonSku = $product->ozon_data['sku'] ?? null;
                if ($ozonSku) {
                    $ozonSkuToOfferId[(string)$ozonSku] = $product->sku;
                }
            }
        }
        
        return $this->storage->getStorageTransactions($dateFrom, $dateTo, $ozonSkuToOfferId);
    }

    /**
     * Создать отчёт о стоимости размещения по товарам (асинхронно)
     */
    public function createPlacementReportByProducts(string $dateFrom, string $dateTo): ?string
    {
        return $this->storage->createPlacementReportByProducts($dateFrom, $dateTo);
    }

    /**
     * Создать отчёт о стоимости размещения по поставкам (асинхронно)
     */
    public function createPlacementReportBySupplies(string $dateFrom, string $dateTo): ?string
    {
        return $this->storage->createPlacementReportBySupplies($dateFrom, $dateTo);
    }

    /**
     * Получить информацию об отчёте по его ID
     */
    public function getReportInfo(string $reportId): array
    {
        return $this->storage->getReportInfo($reportId);
    }

    // === Direct API access ===

    public function getClient(): OzonClient
    {
        return $this->client;
    }

    public function api(): OzonClient
    {
        return $this->client;
    }

    /**
     * Получить API поставок (legacy)
     */
    public function supplies(): SuppliesApi
    {
        return $this->supplies;
    }

    /**
     * FBO: Заявки на поставку
     */
    public function fboSupplyOrders(): FboSupplyOrdersApi
    {
        return $this->fboSupplyOrders;
    }

    /**
     * FBO: Грузоместа и этикетки
     */
    public function fboCargoes(): FboCargoesApi
    {
        return $this->fboCargoes;
    }

    /**
     * FBO: Отправления (аналитика)
     */
    public function fboPostings(): FboPostingsApi
    {
        return $this->fboPostings;
    }

    /**
     * FBS: Отправления
     */
    public function fbsPostings(): FbsPostingsApi
    {
        return $this->fbsPostings;
    }

    /**
     * FBS: Возвраты
     */
    public function fbsReturns(): FbsReturnsApi
    {
        return $this->fbsReturns;
    }
    
    /**
     * Получить комиссии для товаров через v3 API
     * Используется для обновления комиссий при синхронизации остатков
     */
    public function getProductsWithCommissions(array $offerIds): array
    {
        return $this->products->getProductsWithCommissions($offerIds);
    }
}
