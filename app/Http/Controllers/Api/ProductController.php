<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Integration;
use App\Models\Product;
use App\Services\IntegrationAccessService;
use App\Services\LimitsSyncService;
use App\Services\ProductService;
use App\Services\SellicoApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private IntegrationAccessService $integrationAccessService,
        private LimitsSyncService $limitsSync
    ) {}

    public function index(IndexProductRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = Product::query();
        $this->productService->applyComputedStock($query);

        if (! empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (! empty($validated['marketplace'])) {
            $mp = $validated['marketplace'];
            if (in_array($mp, ['yandex', 'yandex_market'], true)) {
                $query->whereIn('marketplace', ['yandex', 'yandex_market']);
            } else {
                $query->marketplace($mp);
            }
        }

        if (! empty($validated['integration_id'])) {
            $integrationId = (int) $validated['integration_id'];
            $workspace = $request->header('X-Sellico-Workspace')
                ?? $request->header('X-Workspace-Id')
                ?? $request->input('workspace');
            $marketplace = $validated['marketplace'] ?? null;

            $resolution = $this->integrationAccessService->ensureAccessibleIntegration(
                $request,
                $integrationId,
                $marketplace
            );

            if (! ($resolution['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $resolution['message'] ?? 'Интеграция недоступна',
                ], $resolution['status'] ?? 403);
            }

            $integrationLog = config('logging.verbose_product_index') ? 'info' : 'debug';
            \Log::log($integrationLog, 'ProductController: Фильтр по integration_id', [
                'raw_value' => $validated['integration_id'],
                'casted_value' => $integrationId,
                'workspace' => $workspace ?? null,
            ]);
            $query->where('integration_id', $integrationId);
        }

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (! empty($validated['brand'])) {
            $query->where('brand', $validated['brand']);
        }

        if (isset($validated['price_from'])) {
            $query->where('price', '>=', $validated['price_from']);
        }

        if (isset($validated['price_to'])) {
            $query->where('price', '<=', $validated['price_to']);
        }

        if (isset($validated['in_stock']) && $validated['in_stock']) {
            $query->whereRaw($this->productService->computedStockExpression().' > 0');
        }

        $sortField = $validated['sort'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        if ($sortField === 'stock') {
            $query->orderByRaw($this->productService->computedStockExpression().' '.$sortOrder);
        } else {
            $query->orderBy($sortField, $sortOrder);
        }

        $limit = min($validated['limit'] ?? 50, 200);
        $page = $validated['page'] ?? 1;

        $products = $query->paginate($limit, ['*'], 'page', $page);

        $stocksLog = config('logging.verbose_product_index') ? 'info' : 'debug';
        \Log::log($stocksLog, 'ProductController: Остатки загружены', [
            'skus_on_page' => $products->count(),
            'skus_with_positive_stock' => $products->getCollection()->filter(
                fn ($product) => (int) ($product->computed_stock ?? 0) > 0
            )->count(),
            'sample' => $products->getCollection()->take(3)->mapWithKeys(
                fn ($product) => [$product->sku => (int) ($product->computed_stock ?? 0)]
            )->toArray(),
        ]);

        // Обновляем stock в товарах
        $productsWithStock = $products->getCollection()->map(function ($product) {
            $productArray = $product->toArray();
            $productArray['stock'] = (int) ($product->computed_stock ?? $product->stock ?? 0);

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

        if (! $integrationId && $this->requestAccessToken($request)) {
            $syncLogs = $this->startMarketplaceSyncAcrossWorkspaces($request, $marketplace);

            if (! empty($syncLogs)) {
                $startedCount = count(array_filter(
                    $syncLogs,
                    static fn (array $sync) => ($sync['status'] ?? null) !== 'skipped'
                ));

                return response()->json([
                    'data' => [
                        'status' => $startedCount > 0 ? 'pending' : 'skipped',
                        'marketplace' => $marketplace,
                        'started' => $startedCount,
                        'syncs' => $syncLogs,
                        'message' => $startedCount > 0
                            ? "Sync started for {$marketplace} in {$startedCount} integrations"
                            : 'Синхронизация не запущена: лимиты тарифа исчерпаны',
                    ],
                ], $startedCount > 0 ? 200 : 403);
            }
        }

        // Если передан integration_id, берём credentials из интеграции
        if ($integrationId) {
            $resolution = $this->integrationAccessService->ensureAccessibleIntegration(
                $request,
                $integrationId,
                $marketplace
            );

            if (! ($resolution['success'] ?? false)) {
                \Log::error('ProductController::sync - integration access failed', [
                    'integration_id' => $integrationId,
                    'marketplace' => $marketplace,
                    'message' => $resolution['message'] ?? null,
                ]);

                return response()->json([
                    'error' => $resolution['message'] ?? 'Integration not found locally or in Sellico API',
                ], $resolution['status'] ?? 404);
            }

            /** @var Integration $integration */
            $integration = $resolution['integration'];
            $limitResponse = $this->ensureProductsLimitAvailable($integration);
            if ($limitResponse !== null) {
                return $limitResponse;
            }

            $credentials = $integration->credentials ?? [];
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
        $accessToken = $this->requestAccessToken($request);
        if ($accessToken) {
            $credentials['_sellico_token'] = $accessToken;
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
        $token = $this->requestAccessToken($request);
        if (! $token) {
            return [];
        }

        /** @var SellicoApiService $sellicoApi */
        $sellicoApi = app(SellicoApiService::class);
        $sellicoApi->setAccessToken($token);

        $workspacesResult = $sellicoApi->getWorkspaces();
        if (! ($workspacesResult['success'] ?? false)) {
            \Log::warning('ProductController::sync - Failed to load workspaces for bulk sync', [
                'marketplace' => $marketplace,
                'error' => $workspacesResult['error'] ?? null,
            ]);

            return [];
        }

        $workspacesRaw = $workspacesResult['workspaces'] ?? [];
        $workspaces = $workspacesRaw['data'] ?? $workspacesRaw;
        if (! is_array($workspaces)) {
            return [];
        }

        $started = [];
        $processedIntegrationIds = [];

        foreach ($workspaces as $workspace) {
            $workspaceId = (int) ($workspace['id'] ?? 0);
            if (! $workspaceId) {
                continue;
            }

            $integrationsResult = $sellicoApi->getMarketplaceCredentials($workspaceId);
            if (! ($integrationsResult['success'] ?? false)) {
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
                if (! $remoteIntegrationId || isset($processedIntegrationIds[$remoteIntegrationId])) {
                    continue;
                }

                $credentials = $this->extractIntegrationCredentials($integrationData, $token);
                if (! $this->hasMarketplaceCredentials($credentials)) {
                    continue;
                }

                $this->upsertLocalIntegration($integrationData, $workspaceId, $marketplace, $credentials);

                $localIntegration = Integration::find($remoteIntegrationId);
                if ($localIntegration instanceof Integration) {
                    $limitCheck = $this->limitsSync->ensureLimitAvailable((int) $localIntegration->work_space_id, 'products', 1);
                    if (! ($limitCheck['success'] ?? false)) {
                        \Log::info('ProductController::sync - workspace products limit exhausted, sync skipped', [
                            'workspace_id' => $workspaceId,
                            'integration_id' => $remoteIntegrationId,
                            'current_value' => $limitCheck['current_value'] ?? null,
                            'limit' => $limitCheck['limit'] ?? null,
                        ]);

                        $started[] = array_merge(
                            [
                                'integration_id' => $remoteIntegrationId,
                                'workspace_id' => $workspaceId,
                                'status' => 'skipped',
                            ],
                            $this->limitsSync->limitResponsePayload($limitCheck)
                        );
                        continue;
                    }
                }

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

    private function ensureProductsLimitAvailable(Integration $integration): ?JsonResponse
    {
        $workspaceId = (int) ($integration->work_space_id ?? 0);
        if ($workspaceId <= 0) {
            return null;
        }

        $limitCheck = $this->limitsSync->ensureLimitAvailable($workspaceId, 'products', 1);
        if ($limitCheck['success'] ?? false) {
            return null;
        }

        return response()->json(
            $this->limitsSync->limitResponsePayload($limitCheck),
            (int) ($limitCheck['status'] ?? 403)
        );
    }

    /**
     * Токен Sellico: фронт шлёт X-Sellico-Token; часть клиентов — Authorization Bearer.
     */
    private function requestAccessToken(Request $request): ?string
    {
        $token = $request->bearerToken()
            ?? $request->header('X-Sellico-Token')
            ?? $request->header('X-Token');

        if ($token === null || $token === '') {
            return null;
        }

        return $token;
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

        if (! is_array($credentials)) {
            $credentials = [];
        }

        $credentials = array_filter($credentials, static fn ($value) => $value !== null && $value !== '');
        $credentials['_sellico_token'] = $token;

        return $credentials;
    }

    private function hasMarketplaceCredentials(array $credentials): bool
    {
        foreach (['api_key', 'client_id', 'token', 'campaign_id', 'business_id'] as $key) {
            if (! empty($credentials[$key])) {
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
        if (! $integrationId) {
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

        if (! $localIntegration->exists) {
            $localIntegration->auto_sync_enabled = true;
            $localIntegration->sync_interval_hours = 6;
        }

        $localIntegration->save();
    }

    private function sanitizeStoredCredentials(array $credentials): array
    {
        return array_filter(
            $credentials,
            static fn ($value, $key) => $value !== null && $value !== '' && ! str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
