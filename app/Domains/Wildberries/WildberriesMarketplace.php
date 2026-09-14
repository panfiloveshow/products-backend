<?php

namespace App\Domains\Wildberries;

use App\Domains\Marketplace\Contracts\MarketplaceInterface;
use App\Domains\Wildberries\Api\CardApi;
use App\Domains\Wildberries\Api\FbsSuppliesApi;
use App\Domains\Wildberries\Api\InventoryApi;
use App\Domains\Wildberries\Api\ProductsApi;
use App\Domains\Wildberries\Api\RealizationReportApi;
use App\Domains\Wildberries\Api\SalesApi;
use App\Domains\Wildberries\Api\StorageApi;
use App\Domains\Wildberries\Api\SuppliesApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Domains\Wildberries\Tariffs\FbsOfficeGeoMatcher;
use App\Jobs\SyncInventoryJob;
use App\Models\Integration;
use App\Services\Marketplace\MarketplaceInterface as LegacyMarketplaceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Фасад для работы с Wildberries API
 *
 * Объединяет все компоненты:
 * - ProductsApi — товары
 * - InventoryApi — остатки
 * - SalesApi — продажи
 * - StorageApi — хранение, тарифы
 */
class WildberriesMarketplace implements LegacyMarketplaceInterface, MarketplaceInterface
{
    private WildberriesClient $client;

    private ProductsApi $products;

    private InventoryApi $inventory;

    private SalesApi $sales;

    private StorageApi $storage;

    private RealizationReportApi $realizationReport;

    private SuppliesApi $supplies;

    private FbsSuppliesApi $fbsSupplies;

    private CardApi $card;

    private ?Integration $integration = null;

    public function __construct(array $credentials = [], ?Integration $integration = null)
    {
        $apiKey = $credentials['api_key'] ?? config('services.wildberries.api_key');

        $this->client = new WildberriesClient($apiKey);
        $this->products = new ProductsApi($this->client);
        $this->inventory = new InventoryApi($this->client);
        $this->sales = new SalesApi($this->client);
        $this->storage = new StorageApi($this->client);
        $this->realizationReport = new RealizationReportApi($this->client);
        $this->supplies = new SuppliesApi($this->client);
        $this->fbsSupplies = new FbsSuppliesApi($this->client);
        $this->card = new CardApi;
        $this->integration = $integration;
    }

    /**
     * Создать экземпляр из Integration модели
     */
    public static function fromIntegration(Integration $integration): self
    {
        // resolveCredentials, а не getDecryptedCredentials: у интеграций без
        // локального ключа (создание через Sellico) api_key приходит фолбэком,
        // иначе Statistics API отвечает 401 «empty Authorization header».
        return new self($integration->resolveCredentials(), $integration);
    }

    /**
     * Получить связанную интеграцию
     */
    public function getIntegration(): ?Integration
    {
        return $this->integration;
    }

    // === MarketplaceInterface ===

    public function getName(): string
    {
        return 'Wildberries';
    }

    public function getCode(): string
    {
        return 'wildberries';
    }

    public function testConnection(Integration $integration): bool
    {
        try {
            $this->products->getProducts($integration, ['limit' => 1]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getSupportedSchemes(): array
    {
        return ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'];
    }

    // === Products ===

    public function getProducts(): array
    {
        $cards = [];
        $cursor = null;
        $pages = 0;

        do {
            $result = $this->products->getProducts($this->getIntegration(), [
                'limit' => 100,
                'cursor' => $cursor,
            ]);
            $pageCards = $result['cards'] ?? $result;
            $cursor = $result['cursor'] ?? null;

            if (empty($pageCards)) {
                break;
            }

            $cards = array_merge($cards, $pageCards);
            $pages++;

            $hasMore = $cursor && isset($cursor['nmID']) && (int) $cursor['nmID'] > 0;
        } while ($hasMore && $pages < 500);

        if (empty($cards)) {
            Log::warning('WB Marketplace: No cards returned from Products API');

            return [];
        }

        Log::info('WB Marketplace: Got cards from Products API', [
            'count' => count($cards),
        ]);

        // Получаем комиссии по категориям для обогащения данных товаров
        $commissionsByCategory = $this->storage->getCommissions();

        // Собираем nmID для запроса цен
        $nmIds = array_filter(array_column($cards, 'nmID'));

        Log::info('WB Marketplace: Fetching prices for nmIds', [
            'count' => count($nmIds),
        ]);

        // Получаем цены через Prices API (актуальный эндпоинт!)
        $prices = ! empty($nmIds) ? $this->products->getPrices(null, $nmIds) : [];

        Log::info('WB Marketplace: Prices loaded', [
            'count' => count($prices),
            'sample_keys' => array_slice(array_keys($prices), 0, 5),
        ]);

        // Получаем рейтинги карточек через официальный WB Analytics API
        // productRating = рейтинг карточки (качество заполнения, 0-10)
        // feedbackRating = рейтинг по отзывам (0-5)
        $cardRatings = $this->products->getCardRatings(array_values($nmIds));

        Log::info('WB Marketplace: Card ratings loaded', [
            'count' => count($cardRatings),
        ]);

        // СПП (Скидка Постоянного Покупателя) — редактируемое поле
        // Автоматическая загрузка из отчётов продаж отключена (слишком тяжёлый запрос)
        // Пользователь может заполнить СПП вручную на фронтенде
        $sppByNmId = [];

        // Получаем остатки через Statistics API (возвращает ВСЕ остатки сразу с ценами!)
        $stocksRaw = $this->inventory->getStocks();

        Log::info('WB Marketplace: Stocks loaded from Statistics API', [
            'count' => count($stocksRaw),
        ]);

        $stocks = [];
        foreach ($stocksRaw as $stockItem) {
            // Statistics API возвращает barcode, nmId, supplierArticle
            $barcode = $stockItem['barcode'] ?? $stockItem['sku'] ?? null;
            $nmId = $stockItem['nmId'] ?? null;
            $supplierArticle = $stockItem['supplierArticle'] ?? null;

            $stockData = [
                'quantity' => $stockItem['total'] ?? 0,
                'warehouses' => $stockItem['warehouses'] ?? [],
                'inWayToClient' => $stockItem['inWayToClient'] ?? 0,
                'inWayFromClient' => $stockItem['inWayFromClient'] ?? 0,
                // Цена и скидка из Statistics API (fallback если Prices API не вернул)
                'price' => $stockItem['price'] ?? 0,
                'discount' => $stockItem['discount'] ?? 0,
            ];

            // Индексируем по всем возможным ключам (приводим к строкам для единообразия)
            if ($barcode) {
                $stocks[(string) $barcode] = $stockData;
            }
            if ($nmId) {
                $stocks[(string) $nmId] = $stockData;
            }
            if ($supplierArticle) {
                $stocks[(string) $supplierArticle] = $stockData;
            }
        }

        Log::info('WB Marketplace: Stocks indexed', [
            'keys_count' => count($stocks),
            'sample_keys' => array_slice(array_keys($stocks), 0, 5),
        ]);

        // Маппинг WB cards к формату Product модели с обогащением ценами и остатками.
        // Для WB SKU в проекте — barcode, поэтому одна карточка может дать несколько Product.
        return collect($cards)
            ->flatMap(fn ($card) => $this->mapCardToProducts($card, $commissionsByCategory, $prices, $stocks, $cardRatings, $sppByNmId))
            ->values()
            ->all();
    }

    private function mapCardToProducts(array $card, array $commissionsByCategory = [], array $prices = [], array $stocks = [], array $ratings = [], array $sppByNmId = []): array
    {
        $sizes = $card['sizes'] ?? [];
        $sizeEntries = [];

        foreach ($sizes as $size) {
            foreach ($size['skus'] ?? [] as $barcode) {
                if ($barcode) {
                    $sizeEntries[] = [
                        'barcode' => $barcode,
                        'size' => $size,
                    ];
                }
            }
        }

        if ($sizeEntries === []) {
            $sizeEntries[] = [
                'barcode' => $card['vendorCode'] ?? (string) ($card['nmID'] ?? ''),
                'size' => $sizes[0] ?? [],
            ];
        }

        return array_map(
            fn (array $sizeEntry) => $this->mapCardToProduct($card, $commissionsByCategory, $prices, $stocks, $ratings, $sppByNmId, $sizeEntry),
            $sizeEntries
        );
    }

    /**
     * Маппинг WB карточки к формату Product модели
     * Аналогично Ozon сохраняем все данные API в wb_data
     */
    private function mapCardToProduct(array $card, array $commissionsByCategory = [], array $prices = [], array $stocks = [], array $ratings = [], array $sppByNmId = [], ?array $sizeEntry = null): array
    {
        // Извлекаем габариты из sizes[0].dimensions или characteristics
        $dimensions = $this->extractDimensions($card);

        // Получаем категорию товара
        $category = $card['subjectName'] ?? '';
        $subjectId = $card['subjectID'] ?? null;

        // Получаем комиссию по subjectID (приоритет) или по умолчанию
        $commissionData = $commissionsByCategory[$subjectId] ?? $commissionsByCategory['default'] ?? null;
        $commissionPercent = $commissionData['fbo'] ?? 15.0;

        $firstSize = $sizeEntry['size'] ?? ($card['sizes'][0] ?? []);
        $barcode = $sizeEntry['barcode'] ?? ($firstSize['skus'][0] ?? null);
        $nmId = $card['nmID'] ?? null;
        $vendorCode = $card['vendorCode'] ?? null;
        $sizeId = $firstSize['chrtID'] ?? $firstSize['sizeID'] ?? null;
        $marketplaceId = $nmId
            ? ((string) $nmId.':'.(string) ($barcode ?: $sizeId ?: 'default'))
            : (string) ($barcode ?: $vendorCode ?: '');

        // Получаем цены из Prices API (приоритет)
        $priceData = $this->resolvePriceData($prices, $vendorCode, $nmId, $barcode, $firstSize);

        // Получаем остатки из Statistics API (ищем по barcode, nmId, vendorCode)
        $stockData = $stocks[$barcode] ?? $stocks[(string) $nmId] ?? $stocks[$vendorCode] ?? null;

        $price = null;
        $oldPrice = null;
        if ($priceData) {
            // Prices API возвращает цены в sizes
            $finalPrice = (float) ($priceData['final_price'] ?? $priceData['discounted_price'] ?? $priceData['price'] ?? 0);
            $basePrice = (float) ($priceData['price'] ?? 0);
            if ($finalPrice > 0) {
                $price = $finalPrice;
                $oldPrice = $basePrice > $finalPrice ? $basePrice : null;
            }
        } elseif ($stockData && ! empty($stockData['price'])) {
            // Fallback: цены из Statistics API
            $oldPrice = (float) ($stockData['price'] ?? 0);
            $discount = (int) ($stockData['discount'] ?? 0);
            $price = $oldPrice > 0 ? round($oldPrice * (1 - $discount / 100), 2) : null;
            $oldPrice = ($oldPrice > 0 && $discount > 0) ? $oldPrice : null;
        } elseif (! empty($firstSize['discountedPrice']) || ! empty($firstSize['price'])) {
            // Fallback: цены из sizes карточки (могут быть устаревшими)
            $fp = (float) ($firstSize['discountedPrice'] ?? 0);
            $bp = (float) ($firstSize['price'] ?? 0);
            $price = $fp > 0 ? $fp : ($bp > 0 ? $bp : null);
            $oldPrice = ($bp > 0 && $fp > 0 && $bp > $fp) ? $bp : null;
        }

        // Получаем остатки
        $stock = $stockData ? (int) ($stockData['quantity'] ?? 0) : 0;

        // Конвертация в единицы БД:
        // products.depth/width/height — мм
        // products.weight — г
        // products.volume_weight — объёмный вес (кг) (используется в расчётах), если можем посчитать
        $depthMm = $dimensions['length'] !== null ? $dimensions['length'] * 10 : null; // см → мм
        $widthMm = $dimensions['width'] !== null ? $dimensions['width'] * 10 : null;  // см → мм
        $heightMm = $dimensions['height'] !== null ? $dimensions['height'] * 10 : null; // см → мм
        $weightG = $dimensions['weight']; // г или null

        $volumeLiters = null;
        if ($depthMm !== null && $widthMm !== null && $heightMm !== null) {
            $volumeLiters = ($depthMm * $widthMm * $heightMm) / 1000000; // мм^3 → л
        }

        $volumeWeight = null;
        if ($volumeLiters !== null) {
            $volumeWeight = $volumeLiters / 5; // базовый делитель 5л/кг
        }

        // Извлекаем описание из характеристик
        $description = $card['description'] ?? null;
        if (! $description) {
            // Ищем описание в characteristics
            $chars = collect($card['characteristics'] ?? []);
            $descChar = $chars->first(fn ($c) => in_array($c['name'] ?? '', ['Описание', 'Description', 'Комплектация']));
            if ($descChar) {
                $value = $descChar['value'] ?? null;
                $description = is_array($value) ? implode(', ', $value) : $value;
            }
        }

        // Рейтинги из официального WB Analytics API
        // productRating = рейтинг карточки (качество заполнения, 0-10)
        // feedbackRating = рейтинг по отзывам (0-5)
        $ratingData = $ratings[(string) $nmId] ?? null;
        $rating = $ratingData['feedbackRating'] ?? $card['rating'] ?? null;
        $reviewsCount = $card['feedbackCount'] ?? $card['reviewsCount'] ?? 0;

        // Рейтинг карточки (качество заполнения) из официального WB API
        // Если API не вернул — используем расчёт как fallback
        $productRating = $ratingData['productRating'] ?? null;
        if ($productRating !== null) {
            $cardRating = [
                'score' => (float) $productRating,
                'max_score' => 10,
                'details' => null, // WB API не возвращает детализацию
            ];
        } else {
            // Fallback: расчёт на основе данных карточки
            $cardRating = $this->calculateCardRating($card, $description);
        }

        return [
            'marketplace_id' => $marketplaceId,
            // В проекте sku используется как штрихкод (EAN) для WB, чтобы совпадало с текущими данными/кэшем
            'sku' => $barcode ?? (string) $card['nmID'],
            'vendor_code' => $card['vendorCode'] ?? null,
            'name' => $card['title'] ?? $card['subjectName'] ?? '',
            'description' => $description,
            'brand' => $card['brand'] ?? '',
            'category' => $card['subjectName'] ?? '',
            'price' => $price,
            'old_price' => ($oldPrice !== null && $price !== null && $oldPrice > $price) ? $oldPrice : null,
            'stock' => $stock,
            'rating' => $rating,
            'reviews_count' => $reviewsCount,
            'card_rating' => $cardRating['score'],
            'card_rating_details' => $cardRating['details'],
            'commission' => $commissionPercent,
            'spp' => $sppByNmId[$nmId] ?? null, // Средний СПП из отчётов о продажах за 30 дней
            'subject_id' => $subjectId,
            'images' => array_column($card['photos'] ?? [], 'big'),
            'barcode' => $barcode,
            // Габариты (в единицах БД)
            'depth' => $depthMm,
            'width' => $widthMm,
            'height' => $heightMm,
            'weight' => $weightG,
            'volume_weight' => $volumeWeight,
            // WB-специфичные данные (аналогично ozon_data)
            'wb_data' => [
                'nmID' => $card['nmID'],
                // % выкупа из воронки продаж WB (как в ЛК), полученный вместе с рейтингами.
                // Приоритетный источник выкупа для юнит-экономики (см. SyncUnitEconomicsCommand).
                'redemption_rate' => $ratingData['redemption_rate'] ?? null,
                'redemption_orders_count' => $ratingData['redemption_orders_count'] ?? null,
                'redemption_buyouts_count' => $ratingData['redemption_buyouts_count'] ?? null,
                'redemption_source' => $ratingData['redemption_source'] ?? null,
                'redemption_observed_at' => $ratingData['redemption_observed_at'] ?? null,
                'imtID' => $card['imtID'] ?? null,
                'subjectID' => $card['subjectID'] ?? null,
                'vendorCode' => $card['vendorCode'] ?? null,
                'dimensions' => $dimensions,
                'dimensions_observed_at' => $card['_dimensions_observed_at'] ?? null,
                'characteristics' => $card['characteristics'] ?? [],
                // Комиссии по схемам (аналогично ozon_data.commissions)
                'commissions' => $this->normalizeCommissionSchemes($commissionData, $category),
                'commissions_by_scheme' => $this->normalizeCommissionSchemes($commissionData, $category),
                // Актуальная цена (аналогично ozon_data.actual_price)
                'actual_price' => $price,
                'old_price' => $oldPrice,
                'chrtID' => $firstSize['chrtID'] ?? null,
                'sizeID' => $sizeId,
                'size' => trim(($firstSize['wbSize'] ?? '') ?: ($firstSize['techSize'] ?? '') ?: ($firstSize['techSizeName'] ?? '')),
                'prices_by_size' => $priceData['sizes'] ?? [],
                'price_source' => isset($priceData['sizeID']) ? 'prices_api_size' : ($priceData ? 'prices_api_nm' : 'content_card'),
                // Данные об остатках
                'stock_warehouses' => $stockData['warehouses'] ?? [],
                'inWayToClient' => $stockData['inWayToClient'] ?? 0,
                'inWayFromClient' => $stockData['inWayFromClient'] ?? 0,
                // Габариты в мм и г для расчётов
                'length_mm' => $depthMm,
                'width_mm' => $widthMm,
                'height_mm' => $heightMm,
                'weight_g' => $weightG,
                'volume_liters' => $volumeLiters,
            ],
        ];
    }

    private function resolvePriceData(array $prices, ?string $vendorCode, mixed $nmId, ?string $barcode, array $size): ?array
    {
        $sizeId = $size['chrtID'] ?? $size['sizeID'] ?? null;
        if ($nmId && $sizeId && isset($prices[(string) $nmId.':'.(string) $sizeId])) {
            return $prices[(string) $nmId.':'.(string) $sizeId];
        }

        return ($barcode && isset($prices[$barcode]))
            ? $prices[$barcode]
            : ($prices[$vendorCode] ?? $prices[(string) $nmId] ?? null);
    }

    private function normalizeCommissionSchemes(?array $commissionData, string $category): array
    {
        $commissionData = $commissionData ?: [];
        $fbo = (float) ($commissionData['fbo'] ?? 15.0);
        $fbs = (float) ($commissionData['fbs'] ?? $fbo);
        // У WB «Витрина (DBS)» и «Курьер WB (DBW)» — одна колонка комиссии.
        $dbs = (float) ($commissionData['dbs'] ?? $fbs);

        return [
            'fbo' => ['percent' => $fbo, 'category' => $category],
            'fbs' => ['percent' => $fbs, 'category' => $category],
            'edbs' => ['percent' => (float) ($commissionData['fbs_express'] ?? $fbs), 'category' => $category],
            'dbs' => ['percent' => $dbs, 'category' => $category],
            'dbw' => ['percent' => $dbs, 'category' => $category],
            'paid_storage' => ['percent' => (float) ($commissionData['paid_storage'] ?? 0.0), 'category' => $category],
        ];
    }

    /**
     * Рассчитать рейтинг карточки (качество заполнения)
     * Аналог WB "Рейтинг карточки 10/10"
     *
     * Критерии оценки (по 2.5 балла каждый):
     * - Наименование: есть title длиной >= 10 символов
     * - Описание: есть description длиной >= 50 символов
     * - Фото: есть минимум 3 фото
     * - Характеристики: заполнено минимум 5 характеристик
     *
     * @return array ['score' => float, 'details' => array]
     */
    private function calculateCardRating(array $card, ?string $description): array
    {
        $score = 0;
        $details = [
            'title' => 'не заполнено',
            'description' => 'не заполнено',
            'photos' => 'не заполнено',
            'characteristics' => 'не заполнено',
        ];

        // Наименование (2.5 балла)
        $title = $card['title'] ?? '';
        if (mb_strlen($title) >= 10) {
            $score += 2.5;
            $details['title'] = 'идеально';
        } elseif (mb_strlen($title) >= 5) {
            $score += 1.5;
            $details['title'] = 'можно улучшить';
        } elseif (! empty($title)) {
            $score += 0.5;
            $details['title'] = 'слишком короткое';
        }

        // Описание (2.5 балла)
        $descLength = mb_strlen($description ?? '');
        if ($descLength >= 100) {
            $score += 2.5;
            $details['description'] = 'идеально';
        } elseif ($descLength >= 50) {
            $score += 1.5;
            $details['description'] = 'можно улучшить';
        } elseif ($descLength > 0) {
            $score += 0.5;
            $details['description'] = 'слишком короткое';
        }

        // Фото (2.5 балла)
        $photosCount = count($card['photos'] ?? []);
        if ($photosCount >= 5) {
            $score += 2.5;
            $details['photos'] = 'идеально';
        } elseif ($photosCount >= 3) {
            $score += 2.0;
            $details['photos'] = 'хорошо';
        } elseif ($photosCount >= 1) {
            $score += 1.0;
            $details['photos'] = 'мало фото';
        }

        // Характеристики (2.5 балла)
        $charsCount = count($card['characteristics'] ?? []);
        if ($charsCount >= 10) {
            $score += 2.5;
            $details['characteristics'] = 'идеально';
        } elseif ($charsCount >= 5) {
            $score += 2.0;
            $details['characteristics'] = 'хорошо';
        } elseif ($charsCount >= 3) {
            $score += 1.0;
            $details['characteristics'] = 'мало характеристик';
        } elseif ($charsCount > 0) {
            $score += 0.5;
            $details['characteristics'] = 'очень мало';
        }

        return [
            'score' => round($score, 1),
            'max_score' => 10,
            'details' => $details,
        ];
    }

    /**
     * Извлечь габариты из WB карточки
     * По документации WB API: dimensions на уровне карточки, размеры в см, вес в кг
     * Возвращает null если данных нет (без дефолтов — только реальные данные)
     *
     * @see https://dev.wildberries.ru/openapi/work-with-products
     */
    private function extractDimensions(array $card): array
    {
        // 1. Приоритет: dimensions на уровне карточки (документация WB API)
        $dims = $card['dimensions'] ?? null;
        if ($dims && (isset($dims['length']) || isset($dims['width']) || isset($dims['height']))) {
            return [
                'length' => isset($dims['length']) ? (float) $dims['length'] : null,  // см
                'width' => isset($dims['width']) ? (float) $dims['width'] : null,     // см
                'height' => isset($dims['height']) ? (float) $dims['height'] : null,  // см
                'weight' => isset($dims['weightBrutto']) ? (float) $dims['weightBrutto'] * 1000 : null, // кг → г
            ];
        }

        // 2. Fallback: characteristics (для старых карточек)
        $chars = collect($card['characteristics'] ?? []);
        $findValue = function ($names) use ($chars) {
            $char = $chars->first(fn ($c) => in_array($c['name'] ?? '', $names));
            if (! $char) {
                return null;
            }
            $value = $char['value'] ?? null;

            // value может быть массивом или скаляром
            return is_array($value) ? ($value[0] ?? null) : $value;
        };

        $length = $findValue(['Глубина упаковки', 'Глубина', 'Длина упаковки', 'Длина']);
        $width = $findValue(['Ширина упаковки', 'Ширина']);
        $height = $findValue(['Высота упаковки', 'Высота']);
        $weight = $findValue(['Вес товара с упаковкой (г)', 'Вес с упаковкова', 'Вес товара', 'Вес']);

        return [
            'length' => $length !== null ? (float) $length : null,
            'width' => $width !== null ? (float) $width : null,
            'height' => $height !== null ? (float) $height : null,
            'weight' => $weight !== null ? (float) $weight : null,  // г
        ];
    }

    public function getProductPrices(): array
    {
        return $this->products->getPrices($this->getIntegration());
    }

    // === Inventory ===

    public function getInventory(): array
    {
        return $this->inventory->getStocks($this->getIntegration());
    }

    /**
     * Получить остатки только с FBS складов продавца
     * Используется в SyncInventoryJob для явной синхронизации FBS
     */
    public function getFbsStocks(): array
    {
        return $this->inventory->getStocksFromFbsWarehousesDirect($this->getIntegration());
    }

    public function getWarehouses(): array
    {
        return $this->inventory->getWarehouses($this->getIntegration());
    }

    // === Sales ===

    public function getSalesStats(string $dateFrom, string $dateTo): array
    {
        return $this->sales->getSalesStats($dateFrom, $dateTo);
    }

    public function getSalesReport(int $days = 30): ?array
    {
        return $this->sales->getSalesReport($days);
    }

    public function getOrdersReport(int $days = 30): ?array
    {
        return $this->sales->getOrdersReport($days);
    }

    public function buildSalesBySku(array $salesReport, int $days = 30): array
    {
        return $this->sales->buildSalesBySku($salesReport, $days);
    }

    public function getSalesBySku(): array
    {
        return $this->sales->getSalesBySku();
    }

    /**
     * Продажи по складам для {@see SyncInventoryJob} (остатки × sales_* / avg_daily_sales).
     */
    public function getSalesByWarehouse(int $days = 30): array
    {
        return $this->sales->getSalesByWarehouse($days);
    }

    /**
     * Получить продажи по регионам (федеральным округам) для расчёта индекса локализации
     */
    public function getSalesByRegion(int $days = 31): array
    {
        return $this->sales->getSalesByRegion($days);
    }

    public function getRedemptionStatsByNmId(int $days = 30, ?array $salesReport = null): array
    {
        return $this->sales->getRedemptionStatsByNmId($days, $salesReport);
    }

    public function getSppFromSales(int $days = 30): array
    {
        return $this->sales->getSppFromSales($days);
    }

    public function buildSppFromSales(array $salesReport): array
    {
        return $this->sales->buildSppFromSales($salesReport);
    }

    public function buildSppMapsFromReport(array $report): array
    {
        return $this->sales->buildSppMapsFromReport($report);
    }

    /**
     * Витринный СПП из публичных карточек (card.wb.ru) по nmId.
     * Используется как фолбэк для товаров без продаж, где СПП из статистики
     * продаж недоступен.
     *
     * @param  array<int|string>  $nmIds
     * @return array<string,float> map [nmId => spp%]
     */
    public function getDisplayedSppByNmIds(array $nmIds, array $sellerPricesByNmId = []): array
    {
        return $this->card->getSppByNmIds($nmIds, $sellerPricesByNmId);
    }

    // === Storage ===

    public function getPaidStorage(?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->storage->getPaidStorage($dateFrom, $dateTo);
    }

    public function getStorageCostBySku(): array
    {
        return $this->storage->getStorageCostBySku();
    }

    public function getSupplyTariffs(): array
    {
        return $this->storage->getSupplyTariffs();
    }

    public function getStorageTariffs(): array
    {
        return $this->storage->getStorageTariffs();
    }

    public function getTariffSnapshots(?string $date = null): array
    {
        return $this->storage->getTariffSnapshots($date);
    }

    public function getCommissions(): array
    {
        return $this->storage->getCommissions();
    }

    // === Realization Report (еженедельные отчёты) ===

    /**
     * Получить фактические начисления за хранение из еженедельных отчётов реализации
     *
     * Это РЕАЛЬНЫЕ суммы (storage_fee), которые WB начислил к оплате.
     *
     * @param  int  $weeks  Количество недель для анализа
     * @return array [barcode => ['storage_fee_total' => float, 'storage_fee_last_week' => float, ...]]
     */
    public function getStorageFeesBySku(int $weeks = 4): array
    {
        return $this->realizationReport->getStorageFeesBySku($weeks);
    }

    /**
     * Единый снимок хранения и эквайринга из одного Finance-отчёта.
     */
    public function getStorageAndAcquiringBySku(int $weeks = 4): array
    {
        return $this->realizationReport->getStorageAndAcquiringBySku($weeks);
    }

    /**
     * Фактический эквайринг по SKU из отчёта реализации.
     *
     * @return array{by_sku: array<string,float>, avg: float}
     */
    public function getAcquiringBySku(int $weeks = 4): array
    {
        return $this->realizationReport->getAcquiringBySku($weeks);
    }

    /**
     * Получить детализацию отчёта реализации за период
     */
    public function getRealizationReport(string $dateFrom, string $dateTo, string $periodicity = 'weekly'): array
    {
        return $this->realizationReport->getReportDetailByPeriod($dateFrom, $dateTo, $periodicity);
    }

    /**
     * Получить коэффициенты складов (КС) для WB
     *
     * Возвращает коэффициенты логистики и хранения по каждому складу WB.
     * Используется для расчёта юнит-экономики.
     *
     * @return array [warehouseId => ['delivery_coef' => float, 'storage_coef' => float, 'warehouse_name' => string, ...]]
     */
    public function getWarehouseCoefficients(): array
    {
        return $this->storage->getWarehouseCoefficients();
    }

    /**
     * Получить коэффициенты для FBS складов продавца
     *
     * @return array [warehouseId => ['delivery_coef' => float, 'warehouse_name' => string, 'office_name' => string, ...]]
     */
    public function getFbsWarehouseCoefficients(): array
    {
        return $this->storage->getFbsWarehouseCoefficients();
    }

    // === Direct API access ===

    public function getClient(): WildberriesClient
    {
        return $this->client;
    }

    public function api(): WildberriesClient
    {
        return $this->client;
    }

    /**
     * Получить API поставок (FBW)
     */
    public function supplies(): SuppliesApi
    {
        return $this->supplies;
    }

    /**
     * Получить API FBS поставок
     */
    public function fbsSupplies(): FbsSuppliesApi
    {
        return $this->fbsSupplies;
    }

    /**
     * Тарифная география FBS: офис привязки склада продавца → федеральный округ.
     *
     * WB тарифицирует FBS-логистику строкой «Маркетплейс: {ФО}» по округу СЦ
     * привязки (в кабинете «Склад WB: Москва (СК Обухово)» → ЦФО → КС 165%).
     * Округ берём из geo_name склада-тёзки в box-тарифах.
     *
     * @return array{office_name:string, geo_name:string}|null
     */
    /** @param array<string, array{warehouse_name?:string, geo_name?:?string}>|null $tariffs уже полученные box-тарифы — второй запрос к /tariffs/box в одном синке ловит лимит WB и молча отдаёт пусто */
    public function resolveFbsOfficeGeo(?array $tariffs = null): ?array
    {
        $officeIds = [];
        foreach ($this->fbsSupplies->getSellerWarehouses() as $warehouse) {
            if (! empty($warehouse['office_id'])) {
                $officeIds[] = (int) $warehouse['office_id'];
            }
        }
        $officeIds = array_values(array_unique($officeIds));
        if ($officeIds === []) {
            return null;
        }

        $officesById = [];
        foreach ($this->fbsSupplies->getOffices() as $office) {
            $officesById[(int) ($office['id'] ?? 0)] = trim((string) ($office['name'] ?? ''));
        }

        // Детерминированная политика: гео резолвится по каждому складу продавца;
        // расхождение округов между складами — предупреждение, применяется офис
        // ПЕРВОГО склада из ответа WB (интеграция несёт один wb_fbs_marketplace_geo).
        $tariffs ??= $this->getSupplyTariffs();
        if ($tariffs === []) {
            return null;
        }
        $resolved = [];
        foreach ($officeIds as $officeId) {
            $officeName = $officesById[$officeId] ?? '';
            if ($officeName === '') {
                continue;
            }
            $match = FbsOfficeGeoMatcher::match($officeName, $tariffs);
            if ($match !== null) {
                $resolved[] = ['office_name' => $officeName, 'geo_name' => $match['geo_name']];
            }
        }
        if ($resolved === []) {
            return null;
        }

        $geoNames = array_unique(array_column($resolved, 'geo_name'));
        if (count($geoNames) > 1) {
            Log::warning('WB FBS-склады привязаны к разным округам — применяется первый', [
                'integration_id' => $this->integration?->id,
                'resolved' => $resolved,
            ]);
        }

        return $resolved[0];
    }
}
