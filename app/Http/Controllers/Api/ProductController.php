<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use App\Services\CostPriceParserService;
use App\Services\ProductService;
use App\Services\SellicoApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private SellicoApiService $sellicoApi,
        private CostPriceParserService $costPriceParser
    ) {}

    public function index(IndexProductRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $integrationId = $validated['integration_id'] ?? null;
        
        // Загружаем unitEconomics с фильтрацией по integration_id если он указан
        $query = Product::with(['unitEconomics' => function($q) use ($integrationId) {
            if ($integrationId) {
                $q->where('integration_id', $integrationId);
            }
            $q->latest()->limit(1);
        }]);

        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (!empty($validated['marketplace'])) {
            $query->marketplace($validated['marketplace']);
        }

        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }

        if (!empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (!empty($validated['brand'])) {
            $query->where('brand', $validated['brand']);
        }

        if (isset($validated['price_from'])) {
            $query->where('price', '>=', $validated['price_from']);
        }

        if (isset($validated['price_to'])) {
            $query->where('price', '<=', $validated['price_to']);
        }

        if (isset($validated['in_stock']) && $validated['in_stock']) {
            $query->inStock();
        }

        $sortField = $validated['sort'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        $query->orderBy($sortField, $sortOrder);

        $limit = min($validated['limit'] ?? 50, 200);
        $page = $validated['page'] ?? 1;

        $products = $query->paginate($limit, ['*'], 'page', $page);

        // Добавляем cost_price из unitEconomics к каждому товару
        $productsWithCostPrice = collect($products->items())->map(function ($product) {
            $productArray = $product->toArray();
            $productArray['cost_price'] = $product->unitEconomics->first()?->cost_price ?? null;
            unset($productArray['unit_economics']); // Убираем лишние данные
            return $productArray;
        });

        $stats = $this->productService->getProductsStats($validated);

        return response()->json([
            'data' => [
                'products' => $productsWithCostPrice->values(),
                'total' => $products->total(),
                'page' => $products->currentPage(),
                'limit' => $products->perPage(),
                'has_more' => $products->hasMorePages(),
            ],
            'stats' => $stats,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $product = Product::with(['inventoryWarehouses', 'unitEconomics', 'alerts'])
            ->findOrFail($id);

        return response()->json([
            'data' => $product,
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return response()->json([
            'data' => $product,
            'message' => 'Product created successfully',
        ], 201);
    }

    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->update($request->validated());

        return response()->json([
            'data' => $product->fresh(),
            'message' => 'Product updated successfully',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }

    /**
     * Запуск синхронизации товаров с маркетплейса
     * POST /api/products/sync/{marketplace}
     * 
     * Body:
     * - api_key: string (обязательно для WB)
     * - client_id: string (обязательно для Ozon)
     * - token: string (обязательно для Yandex)
     * - campaign_id: string (обязательно для Yandex)
     * - integration_id: int (опционально, ID интеграции из Sellico)
     */
    public function sync(Request $request, string $marketplace): JsonResponse
    {
        try {
            $integrationId = $request->input('integration_id');
            $credentials = [];
            $integrationName = null;

            // Если передан integration_id, получаем credentials из Sellico API
            if ($integrationId) {
                $token = $request->input('sellico_token') ?? $request->bearerToken();
                
                \Log::info('Sync request', [
                    'marketplace' => $marketplace,
                    'integration_id' => $integrationId,
                    'has_token' => !empty($token),
                ]);
                
                if ($token) {
                    $this->sellicoApi->setAccessToken($token);
                }
                
                $result = $this->sellicoApi->getIntegrationById((int)$integrationId);
                
                \Log::info('Sellico getIntegrationById result', [
                    'success' => $result['success'] ?? false,
                    'error' => $result['error'] ?? null,
                    'has_credentials' => !empty($result['credentials']),
                ]);
                
                if (!$result['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => $result['error'] ?? 'Не удалось получить данные интеграции',
                    ], 400);
                }
                
                $credentials = $result['credentials'];
                $integrationName = $result['name'] ?? null;
            } else {
                // Валидация credentials в зависимости от маркетплейса (если переданы напрямую)
                $rules = match ($marketplace) {
                    'wildberries' => ['api_key' => 'required|string'],
                    'ozon' => ['client_id' => 'required|string', 'api_key' => 'required|string'],
                    'yandex' => ['token' => 'required|string', 'campaign_id' => 'required|string'],
                    default => [],
                };

                $request->validate($rules);

                // Собираем credentials из запроса
                $credentials = match ($marketplace) {
                    'wildberries' => ['api_key' => $request->input('api_key')],
                    'ozon' => [
                        'client_id' => $request->input('client_id'),
                        'api_key' => $request->input('api_key'),
                    ],
                    'yandex' => [
                        'token' => $request->input('token'),
                        'campaign_id' => $request->input('campaign_id'),
                        'business_id' => $request->input('business_id'),
                    ],
                    default => [],
                };
            }

            // Проверяем что credentials не пустые
            if (empty($credentials) || empty(array_filter($credentials))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось получить credentials для маркетплейса',
                ], 400);
            }

            $syncLog = $this->productService->startSync(
                $marketplace, 
                $credentials,
                $integrationId,
                'products',
                $integrationName ?? null
            );

            return response()->json([
                'data' => [
                    'sync_id' => $syncLog->id,
                    'status' => $syncLog->status,
                    'message' => "Sync started for {$marketplace}",
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Product sync failed', [
                'marketplace' => $marketplace,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка запуска синхронизации: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function syncStatus(): JsonResponse
    {
        $statuses = $this->productService->getSyncStatuses();

        return response()->json([
            'data' => $statuses,
        ]);
    }

    /**
     * Массовая загрузка себестоимости товаров
     * POST /api/products/cost-price/bulk
     * 
     * Body:
     * - integration_id: int (ОБЯЗАТЕЛЬНО — себестоимость привязана к магазину)
     * - items: array [{ sku: string, cost_price: number }]
     */
    public function updateCostPriceBulk(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.cost_price' => 'required|numeric|min:0',
        ]);

        $items = $request->input('items');
        $integrationId = (int) $request->input('integration_id');
        
        $success = 0;
        $failed = 0;
        $notFound = [];

        foreach ($items as $item) {
            $vendorCode = $item['sku']; // Артикул продавца
            $costPrice = $item['cost_price'];

            // Ищем товар по артикулу продавца (vendorCode для WB, offer_id для Ozon, shopSku для Yandex)
            $product = $this->findProductByVendorCode($vendorCode, $integrationId);

            if (!$product) {
                \Log::debug('Cost price: product not found', [
                    'vendor_code' => $vendorCode,
                    'integration_id' => $integrationId,
                ]);
                $notFound[] = $vendorCode;
                $failed++;
                continue;
            }

            try {
                // Обновляем или создаём запись UnitEconomics
                // Уникальный ключ: sku + integration_id (себестоимость привязана к магазину)
                $unitEconomics = \App\Models\UnitEconomics::updateOrCreate(
                    [
                        'sku' => $product->sku,
                        'integration_id' => $integrationId,
                    ],
                    [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'marketplace' => $product->marketplace,
                        'cost_price' => $costPrice,
                        'price' => $product->price ?? 0,
                    ]
                );
                
                \Log::debug('Cost price saved', [
                    'vendor_code' => $vendorCode,
                    'product_sku' => $product->sku,
                    'integration_id' => $integrationId,
                    'cost_price' => $costPrice,
                    'unit_economics_id' => $unitEconomics->id,
                ]);
                
                $success++;
            } catch (\Exception $e) {
                \Log::error('Cost price update failed', [
                    'sku' => $vendorCode,
                    'integration_id' => $integrationId,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return response()->json([
            'success' => $success,
            'failed' => $failed,
            'not_found' => $notFound,
        ]);
    }

    /**
     * Экспорт артикулов продавца в CSV для загрузки себестоимости
     * GET /api/products/cost-price/template
     * 
     * Query params:
     * - marketplace: string (опционально)
     * - integration_id: string (опционально)
     */
    public function exportCostPriceTemplate(Request $request): StreamedResponse
    {
        $marketplace = $request->query('marketplace');
        $integrationId = $request->query('integration_id');

        $query = Product::query();
        
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }
        
        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }

        $products = $query->orderBy('sku')->get(['sku', 'name', 'marketplace', 'wb_data', 'ozon_data', 'yandex_data']);

        $filename = 'cost_price_template_' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($products) {
            $handle = fopen('php://output', 'w');
            
            // BOM для корректного отображения UTF-8 в Excel
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            
            // Заголовки
            fputcsv($handle, ['Артикул продавца', 'Себестоимость', 'Название товара', 'Маркетплейс'], ';');
            
            // Данные
            foreach ($products as $product) {
                // Получаем артикул продавца в зависимости от маркетплейса
                $vendorCode = $this->getVendorCode($product);
                
                fputcsv($handle, [
                    $vendorCode,
                    '', // Пустая колонка для себестоимости
                    $product->name,
                    $product->marketplace,
                ], ';');
            }
            
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Получение артикула продавца из данных маркетплейса
     */
    private function getVendorCode(Product $product): string
    {
        return match ($product->marketplace) {
            'wildberries' => $product->wb_data['vendorCode'] ?? $product->sku,
            'ozon' => $product->ozon_data['offer_id'] ?? $product->sku,
            'yandex' => $product->yandex_data['shopSku'] ?? $product->yandex_data['offerId'] ?? $product->sku,
            default => $product->sku,
        };
    }

    /**
     * Загрузка и парсинг файла с себестоимостью
     * POST /api/products/cost-price/upload
     * 
     * Body (multipart/form-data):
     * - file: File (Excel .xlsx, .xls или CSV)
     * - integration_id: int (обязательно)
     * - marketplace: string (опционально: wildberries, ozon, yandex)
     * 
     * @return JsonResponse
     */
    public function uploadCostPrice(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240', // max 10MB
            'integration_id' => 'required|integer',
            'marketplace' => 'nullable|string|in:wildberries,ozon,yandex',
        ]);

        $file = $request->file('file');
        $integrationId = $request->input('integration_id');
        $marketplace = $request->input('marketplace');

        \Log::info('Cost price upload started', [
            'file' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'integration_id' => $integrationId,
            'marketplace' => $marketplace,
        ]);

        // Парсим файл
        $result = $this->costPriceParser->parse($file);

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        \Log::info('Cost price upload completed', [
            'file' => $file->getClientOriginalName(),
            'total' => $result['data']['summary']['total'],
            'valid' => $result['data']['summary']['valid'],
            'invalid' => $result['data']['summary']['invalid'],
        ]);

        return response()->json($result);
    }

    /**
     * Поиск товара по артикулу продавца
     */
    private function findProductByVendorCode(string $vendorCode, ?int $integrationId = null): ?Product
    {
        $query = Product::query();
        
        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }

        // Сначала ищем по SKU (точное совпадение)
        $product = (clone $query)->where('sku', $vendorCode)->first();
        if ($product) {
            return $product;
        }

        // Ищем по vendorCode в wb_data (Wildberries)
        // JSON_UNQUOTE нужен чтобы убрать кавычки из JSON значения
        $product = (clone $query)
            ->where('marketplace', 'wildberries')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(wb_data, '$.vendorCode')) = ?", [$vendorCode])
            ->first();
        if ($product) {
            return $product;
        }

        // Ищем по offer_id в ozon_data (Ozon)
        $product = (clone $query)
            ->where('marketplace', 'ozon')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(ozon_data, '$.offer_id')) = ?", [$vendorCode])
            ->first();
        if ($product) {
            return $product;
        }

        // Ищем по shopSku в yandex_data (Yandex)
        $product = (clone $query)
            ->where('marketplace', 'yandex')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(yandex_data, '$.shopSku')) = ?", [$vendorCode])
            ->first();
        if ($product) {
            return $product;
        }

        return null;
    }

    /**
     * Экспорт товаров на маркетплейс
     * POST /api/products/export/{marketplace}
     * 
     * Body:
     * - integration_id: int (обязательно)
     * - product_ids: array (опционально, если не указано — экспортируются все товары интеграции)
     * - warehouse_id: int (опционально, для FBS остатков на Ozon)
     * - include_images: bool (по умолчанию true)
     * - include_stocks: bool (по умолчанию true)
     * - include_description: bool (по умолчанию true)
     * - include_attributes: bool (по умолчанию true)
     */
    public function export(Request $request, string $marketplace): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer',
            'warehouse_id' => 'nullable|integer',
            'include_images' => 'nullable|boolean',
            'include_stocks' => 'nullable|boolean',
            'include_description' => 'nullable|boolean',
            'include_attributes' => 'nullable|boolean',
        ]);

        $integrationId = $request->input('integration_id');
        $productIds = $request->input('product_ids');
        $warehouseId = $request->input('warehouse_id');
        $includeImages = $request->input('include_images', true);
        $includeStocks = $request->input('include_stocks', true);
        $includeDescription = $request->input('include_description', true);
        $includeAttributes = $request->input('include_attributes', true);

        try {
            // Получаем интеграцию
            $integration = \App\Models\Integration::find($integrationId);
            
            if (!$integration) {
                return response()->json([
                    'success' => false,
                    'message' => 'Интеграция не найдена',
                ], 404);
            }

            if ($integration->marketplace !== $marketplace) {
                return response()->json([
                    'success' => false,
                    'message' => "Интеграция не соответствует маркетплейсу {$marketplace}",
                ], 400);
            }

            // Получаем товары для экспорта
            $query = Product::where('integration_id', $integrationId);
            
            if ($productIds) {
                $query->whereIn('id', $productIds);
            }
            
            $products = $query->get();

            if ($products->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет товаров для экспорта',
                ], 400);
            }

            // Создаём сервис маркетплейса
            $marketplaceService = \App\Domains\Marketplace\MarketplaceFactory::create(
                $marketplace,
                $integration->getDecryptedCredentials(),
                $integration
            );

            // Проверяем поддержку экспорта
            if (!method_exists($marketplaceService, 'exportProducts')) {
                return response()->json([
                    'success' => false,
                    'message' => "Экспорт товаров не поддерживается для {$marketplace}",
                ], 400);
            }

            // Подготавливаем данные для экспорта
            $exportData = $products->map(function ($product) use ($marketplace, $includeImages, $includeDescription, $includeAttributes, $includeStocks) {
                return $this->prepareProductForExport($product, $marketplace, [
                    'include_images' => $includeImages,
                    'include_description' => $includeDescription,
                    'include_attributes' => $includeAttributes,
                    'include_stocks' => $includeStocks,
                ]);
            })->toArray();

            // Экспортируем
            $result = $marketplaceService->exportProducts($exportData, $warehouseId);

            \Log::info('Products export completed', [
                'marketplace' => $marketplace,
                'integration_id' => $integrationId,
                'total' => count($products),
                'result' => $result,
            ]);

            return response()->json([
                'success' => $result['success'] ?? false,
                'message' => $result['success'] ? 'Экспорт завершён' : 'Экспорт завершён с ошибками',
                'data' => [
                    'total' => $result['total'] ?? count($products),
                    'imported' => $result['imported'] ?? 0,
                    'failed' => $result['failed'] ?? 0,
                    'errors' => $result['errors'] ?? [],
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Products export failed', [
                'marketplace' => $marketplace,
                'integration_id' => $integrationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка экспорта: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Подготовка товара для экспорта на маркетплейс
     */
    private function prepareProductForExport(Product $product, string $marketplace, array $options): array
    {
        $data = [];

        switch ($marketplace) {
            case 'ozon':
                $ozonData = $product->ozon_data ?? [];
                
                $data = [
                    'offer_id' => $ozonData['offer_id'] ?? $product->sku,
                    'name' => $product->name,
                    'price' => (string) $product->price,
                    'old_price' => $product->old_price ? (string) $product->old_price : '0',
                    'vat' => '0',
                    'currency_code' => 'RUB',
                    'barcode' => $product->barcode,
                    'depth' => $product->depth ?? $ozonData['dimensions']['depth'] ?? 0,
                    'width' => $product->width ?? $ozonData['dimensions']['width'] ?? 0,
                    'height' => $product->height ?? $ozonData['dimensions']['height'] ?? 0,
                    'weight' => $product->weight ?? $ozonData['dimensions']['weight'] ?? 0,
                    'dimension_unit' => 'mm',
                    'weight_unit' => 'g',
                ];

                // Категория
                if (isset($ozonData['category_id'])) {
                    $data['description_category_id'] = $ozonData['category_id'];
                }

                // Описание
                if ($options['include_description'] && $product->description) {
                    $data['description'] = $product->description;
                }

                // Изображения
                if ($options['include_images'] && !empty($product->images)) {
                    $data['images'] = $product->images;
                    $data['primary_image'] = $product->images[0] ?? null;
                }

                // Характеристики (атрибуты)
                if ($options['include_attributes'] && !empty($ozonData['attributes'])) {
                    $data['attributes'] = $ozonData['attributes'];
                }

                // Остатки
                if ($options['include_stocks']) {
                    $data['stock'] = $product->stock ?? 0;
                }
                break;

            case 'wildberries':
                // TODO: Реализовать для Wildberries
                $data = [
                    'vendorCode' => $product->wb_data['vendorCode'] ?? $product->sku,
                    'title' => $product->name,
                    'price' => $product->price,
                ];
                break;

            case 'yandex':
                // TODO: Реализовать для Yandex Market
                $data = [
                    'shopSku' => $product->yandex_data['shopSku'] ?? $product->sku,
                    'name' => $product->name,
                    'price' => $product->price,
                ];
                break;
        }

        return $data;
    }
}
