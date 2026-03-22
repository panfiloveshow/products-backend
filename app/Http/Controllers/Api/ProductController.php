<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Integration;
use App\Models\Product;
use App\Services\ProductService;
use App\Services\SellicoApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
    ) {}

    public function index(IndexProductRequest $request): JsonResponse
    {
        $validated = $request->validated();
        
        $query = Product::query();

        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (!empty($validated['marketplace'])) {
            $query->marketplace($validated['marketplace']);
        }

        if (!empty($validated['integration_id'])) {
            $integrationId = (int) $validated['integration_id'];

            $workspace = $request->header('X-Sellico-Workspace')
                ?? $request->header('X-Workspace-Id')
                ?? $request->input('workspace');

            if ($workspace) {
                $integration = \App\Models\Integration::where('id', $integrationId)->first();

                if ($integration && $integration->work_space_id !== null && $integration->work_space_id !== (int) $workspace) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Интеграция не принадлежит текущему workspace',
                    ], 403);
                }

                // Заполняем work_space_id если ещё не заполнен
                if ($integration && $integration->work_space_id === null) {
                    $integration->update(['work_space_id' => (int) $workspace]);
                }
            }

            $integrationLog = config('logging.verbose_product_index') ? 'info' : 'debug';
            \Log::log($integrationLog, 'ProductController: Фильтр по integration_id', [
                'raw_value' => $validated['integration_id'],
                'casted_value' => $integrationId,
                'workspace' => $workspace ?? null,
            ]);
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

        // Подгружаем остатки из inventory_warehouses
        $productSkus = $products->pluck('sku')->toArray();
        $inventoryQuery = \DB::table('inventory_warehouses')
            ->whereIn('sku', $productSkus);
        
        // Фильтруем по integration_id если он указан
        if (!empty($validated['integration_id'])) {
            $inventoryQuery->where('integration_id', (int) $validated['integration_id']);
        }
        
        $inventoryStocks = $inventoryQuery
            ->select('sku', \DB::raw('SUM(quantity) as total_stock'))
            ->groupBy('sku')
            ->pluck('total_stock', 'sku');

        $skusWithStock = $inventoryStocks->filter(fn ($qty) => (int) $qty > 0)->count();
        $stocksLog = config('logging.verbose_product_index') ? 'info' : 'debug';
        \Log::log($stocksLog, 'ProductController: Остатки загружены', [
            'skus_on_page' => count($productSkus),
            'aggregate_rows' => $inventoryStocks->count(),
            'skus_with_positive_stock' => $skusWithStock,
            'sample' => $inventoryStocks->take(3)->toArray(),
        ]);

        // Обновляем stock в товарах
        $productsWithStock = $products->getCollection()->map(function ($product) use ($inventoryStocks) {
            $productArray = $product->toArray();
            $stock = (int) ($inventoryStocks[$product->sku] ?? 0);
            $productArray['stock'] = $stock;

            return $productArray;
        });

        $stats = $this->productService->getProductsStats($validated);

        return response()->json([
            'data' => [
                'products' => $productsWithStock,
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
        $marketplace = $this->normalizeMarketplace($marketplace);
        $integrationId = $request->integer('integration_id') ?: null;

        if (!$integrationId && $request->bearerToken()) {
            $syncLogs = $this->startMarketplaceSyncAcrossWorkspaces($request, $marketplace);

            if (!empty($syncLogs)) {
                return response()->json([
                    'data' => [
                        'status' => 'pending',
                        'marketplace' => $marketplace,
                        'started' => count($syncLogs),
                        'syncs' => $syncLogs,
                        'message' => "Sync started for {$marketplace} in " . count($syncLogs) . " integrations",
                    ],
                ]);
            }
        }
        
        // Если передан integration_id, берём credentials из интеграции
        if ($integrationId) {
            $integration = Integration::find($integrationId);
            if ($integration) {
                $credentials = $integration->credentials ?? [];
            } else {
                // Если локально нет, пробуем получить из Sellico API
                $token = $request->bearerToken();
                if ($token) {
                    $sellicoApi = app(\App\Services\SellicoApiService::class);
                    $sellicoApi->setAccessToken($token);
                    $result = $sellicoApi->getIntegrationById($integrationId);
                    
                    if ($result['success'] && !empty($result['credentials'])) {
                        $credentials = $result['credentials'];
                    } else {
                        \Log::error('ProductController::sync - Sellico API integration fetch failed', [
                            'integration_id' => $integrationId,
                            'result' => $result
                        ]);
                        return response()->json(['error' => 'Integration not found locally or in Sellico API', 'details' => $result], 404);
                    }
                } else {
                    \Log::error('ProductController::sync - No local integration and no token provided', [
                        'integration_id' => $integrationId
                    ]);
                    return response()->json(['error' => 'Integration not found locally and no token provided'], 404);
                }
            }
        } else {
            // Валидация credentials в зависимости от маркетплейса
            $rules = match ($marketplace) {
                'wildberries' => ['api_key' => 'required|string'],
                'ozon' => ['client_id' => 'required|string', 'api_key' => 'required|string'],
                'yandex', 'yandex_market' => ['token' => 'required|string', 'campaign_id' => 'required|string'],
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
                'yandex', 'yandex_market' => [
                    'token' => $request->input('token'),
                    'campaign_id' => $request->input('campaign_id'),
                ],
                default => [],
            };
        }

        // Прокидываем токен авторизации для Sellico API в фоновые очереди
        if ($request->bearerToken()) {
            $credentials['_sellico_token'] = $request->bearerToken();
        }

        $syncLog = $this->productService->startSync(
            $marketplace, 
            $credentials,
            $integrationId
        );

        return response()->json([
            'data' => [
                'sync_id' => $syncLog->id,
                'status' => $syncLog->status,
                'message' => "Sync started for {$marketplace}",
            ],
        ]);
    }

    public function syncStatus(Request $request): JsonResponse
    {
        $integrationId = $request->input('integration_id');
        $statuses = $this->productService->getSyncStatuses($integrationId ? (int) $integrationId : null);

        return response()->json([
            'data' => $statuses,
        ]);
    }

    private function startMarketplaceSyncAcrossWorkspaces(Request $request, string $marketplace): array
    {
        $token = $request->bearerToken();
        if (!$token) {
            return [];
        }

        /** @var SellicoApiService $sellicoApi */
        $sellicoApi = app(SellicoApiService::class);
        $sellicoApi->setAccessToken($token);

        $workspacesResult = $sellicoApi->getWorkspaces();
        if (!($workspacesResult['success'] ?? false)) {
            \Log::warning('ProductController::sync - Failed to load workspaces for bulk sync', [
                'marketplace' => $marketplace,
                'error' => $workspacesResult['error'] ?? null,
            ]);
            return [];
        }

        $workspacesRaw = $workspacesResult['workspaces'] ?? [];
        $workspaces = $workspacesRaw['data'] ?? $workspacesRaw;
        if (!is_array($workspaces)) {
            return [];
        }

        $started = [];
        $processedIntegrationIds = [];

        foreach ($workspaces as $workspace) {
            $workspaceId = (int) ($workspace['id'] ?? 0);
            if (!$workspaceId) {
                continue;
            }

            $integrationsResult = $sellicoApi->getMarketplaceCredentials($workspaceId);
            if (!($integrationsResult['success'] ?? false)) {
                \Log::warning('ProductController::sync - Failed to load workspace integrations', [
                    'workspace_id' => $workspaceId,
                    'marketplace' => $marketplace,
                    'error' => $integrationsResult['error'] ?? null,
                ]);
                continue;
            }

            $integrations = $integrationsResult['all'] ?? [];
            foreach ($integrations as $integrationData) {
                $integrationMarketplace = $this->normalizeMarketplace(
                    strtolower((string) ($integrationData['type'] ?? ''))
                );

                if ($integrationMarketplace !== $marketplace) {
                    continue;
                }

                $remoteIntegrationId = (int) ($integrationData['id'] ?? 0);
                if (!$remoteIntegrationId || isset($processedIntegrationIds[$remoteIntegrationId])) {
                    continue;
                }

                $credentials = $this->extractIntegrationCredentials($integrationData, $token);
                if (!$this->hasMarketplaceCredentials($credentials)) {
                    continue;
                }

                $this->upsertLocalIntegration($integrationData, $workspaceId, $marketplace, $credentials);

                $syncLog = $this->productService->startSync(
                    $marketplace,
                    $credentials,
                    $remoteIntegrationId
                );

                $started[] = [
                    'integration_id' => $remoteIntegrationId,
                    'workspace_id' => $workspaceId,
                    'sync_id' => $syncLog->id,
                    'status' => $syncLog->status,
                ];
                $processedIntegrationIds[$remoteIntegrationId] = true;
            }
        }

        return $started;
    }

    private function normalizeMarketplace(string $marketplace): string
    {
        return match (strtolower($marketplace)) {
            'yandexmarket', 'yandex_market', 'yandex' => 'yandex_market',
            default => strtolower($marketplace),
        };
    }

    private function extractIntegrationCredentials(array $integrationData, string $token): array
    {
        $credentials = $integrationData['credentials'] ?? [
            'api_key' => $integrationData['api_key'] ?? null,
            'client_id' => $integrationData['client_id'] ?? null,
            'token' => $integrationData['token'] ?? null,
            'campaign_id' => $integrationData['campaign_id'] ?? null,
            'business_id' => $integrationData['business_id'] ?? null,
        ];

        if (!is_array($credentials)) {
            $credentials = [];
        }

        $credentials = array_filter($credentials, static fn ($value) => $value !== null && $value !== '');
        $credentials['_sellico_token'] = $token;

        return $credentials;
    }

    private function hasMarketplaceCredentials(array $credentials): bool
    {
        foreach (['api_key', 'client_id', 'token', 'campaign_id', 'business_id'] as $key) {
            if (!empty($credentials[$key])) {
                return true;
            }
        }

        return false;
    }

    private function upsertLocalIntegration(
        array $integrationData,
        int $workspaceId,
        string $marketplace,
        array $credentials
    ): void {
        $integrationId = (int) ($integrationData['id'] ?? 0);
        if (!$integrationId) {
            return;
        }

        $localIntegration = Integration::find($integrationId) ?? new Integration(['id' => $integrationId]);
        $localIntegration->fill([
            'work_space_id' => $workspaceId,
            'name' => $integrationData['name'] ?? $localIntegration->name ?? "{$marketplace} {$integrationId}",
            'marketplace' => $marketplace,
            'credentials' => $this->sanitizeStoredCredentials($credentials),
            'is_active' => (bool) ($integrationData['is_active'] ?? true),
        ]);

        if (!$localIntegration->exists) {
            $localIntegration->auto_sync_enabled = true;
            $localIntegration->sync_interval_hours = 6;
        }

        $localIntegration->save();
    }

    private function sanitizeStoredCredentials(array $credentials): array
    {
        return array_filter(
            $credentials,
            static fn ($value, $key) => $value !== null && $value !== '' && !str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
