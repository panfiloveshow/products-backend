<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\StoreIntegrationRequest;
use App\Http\Requests\Integration\UpdateIntegrationRequest;
use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\IntegrationAccessService;
use App\Services\LimitsSyncService;
use App\Services\Ozon\OzonPerformanceApiService;
use App\Services\ProductService;
use App\Services\SellicoApiService;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class IntegrationController extends Controller
{
    public function __construct(
        private readonly IntegrationAccessService $integrationAccess,
    ) {
    }

    /**
     * Получить интеграцию с проверкой принадлежности к workspace.
     * Возвращает либо валидную Integration, либо JsonResponse с 403/404.
     */
    private function authorizedIntegration(Request $request, int $id): Integration|JsonResponse
    {
        $access = $this->integrationAccess->ensureAccessibleIntegration($request, $id);
        if (! ($access['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $access['message'] ?? 'Нет доступа к интеграции',
            ], $access['status'] ?? 403);
        }

        return $access['integration'];
    }

    /**
     * Список всех интеграций
     */
    public function index(Request $request): JsonResponse
    {
        $workspace = $request->header('X-Sellico-Workspace')
            ?? $request->header('X-Workspace-Id')
            ?? $request->input('workspace');

        if (! $workspace) {
            return response()->json([
                'success' => false,
                'message' => 'workspace_id обязателен',
            ], 422);
        }

        $query = Integration::query()->forWorkspace((int) $workspace);

        if ($request->has('marketplace')) {
            $query->where('marketplace', $request->marketplace);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $integrations = $query->get()->map(function ($integration) {
            return $this->formatIntegration($integration);
        });

        return response()->json([
            'success' => true,
            'data' => $integrations,
        ]);
    }

    /**
     * Проверка статуса интеграции (валидность токена, принадлежность workspace)
     */
    public function checkStatus(int $id, Request $request, IntegrationAccessService $integrationAccessService): JsonResponse
    {
        $resolution = $integrationAccessService->ensureAccessibleIntegration($request, $id);
        if (! ($resolution['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $resolution['message'] ?? 'Интеграция не найдена',
            ], $resolution['status'] ?? 404);
        }

        $integration = $resolution['integration'];

        $validationStatus = 'valid';
        $validationError = null;
        $lastCheckedAt = now();

        $workspaceId = $request->header('X-Sellico-Workspace')
            ?? $request->header('X-Workspace-Id')
            ?? $request->input('workspace');

        // Проверка принадлежности workspace
        if ($integration->work_space_id !== null && $workspaceId && (int) $integration->work_space_id !== (int) $workspaceId) {
            $validationStatus = 'workspace_mismatch';
            $validationError = 'Интеграция принадлежит другому workspace';
        }

        $integration->update([
            'last_validation_at' => $lastCheckedAt,
            'last_validation_status' => $validationStatus,
            'last_validation_error' => $validationError,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'integration_id' => $id,
                'validation_status' => $validationStatus,
                'validation_error' => $validationError,
                'last_checked_at' => $lastCheckedAt->toIso8601String(),
                'work_space_id' => $integration->work_space_id,
                'marketplace' => $integration->marketplace,
            ],
        ]);
    }

    /**
     * Получить одну интеграцию
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $integration = $this->authorizedIntegration($request, $id);
        if ($integration instanceof JsonResponse) {
            return $integration;
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatIntegration($integration, true),
        ]);
    }

    /**
     * Создать новую интеграцию
     */
    public function store(StoreIntegrationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $integration = Integration::create([
            'name' => $validated['name'],
            'marketplace' => $validated['marketplace'],
            'credentials' => $validated['credentials'],
            'is_active' => true,
            'auto_sync_enabled' => $validated['auto_sync_enabled'] ?? true,
            'sync_interval_hours' => $validated['sync_interval_hours'] ?? 6,
        ]);

        Log::info('Integration created', [
            'id' => $integration->id,
            'marketplace' => $integration->marketplace,
        ]);

        ActivityLogger::forRequest(
            $request,
            action: 'integration_created',
            title: 'Интеграция создана',
            description: "Создана интеграция «{$integration->name}» ({$integration->marketplace})",
            meta: [
                'entity_type' => 'integration',
                'entity_id' => $integration->id,
                'marketplace' => $integration->marketplace,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Интеграция создана',
            'data' => $this->formatIntegration($integration),
        ], 201);
    }

    /**
     * Обновить интеграцию
     */
    public function update(UpdateIntegrationRequest $request, int $id): JsonResponse
    {
        $integration = $this->authorizedIntegration($request, $id);
        if ($integration instanceof JsonResponse) {
            return $integration;
        }

        $validated = $request->validated();
        // Защита от mass-assignment: не даём переписать integration_id / work_space_id через update.
        unset($validated['id'], $validated['work_space_id'], $validated['integration_id']);

        $integration->update($validated);

        ActivityLogger::forRequest(
            $request,
            action: 'integration_updated',
            title: 'Интеграция обновлена',
            description: "Обновлены данные интеграции «{$integration->name}»",
            meta: [
                'entity_type' => 'integration',
                'entity_id' => $integration->id,
                'marketplace' => $integration->marketplace,
                'changed_fields' => array_keys($validated),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Интеграция обновлена',
            'data' => $this->formatIntegration($integration),
        ]);
    }

    /**
     * Удалить интеграцию
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $integration = $this->authorizedIntegration($request, $id);
        if ($integration instanceof JsonResponse) {
            return $integration;
        }

        // Проверяем, есть ли связанные товары
        $productsCount = $integration->products()->count();

        if ($productsCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Невозможно удалить интеграцию с {$productsCount} товарами. Сначала удалите товары.",
            ], 400);
        }

        $integrationSnapshot = [
            'id' => $integration->id,
            'name' => $integration->name,
            'marketplace' => $integration->marketplace,
            'work_space_id' => $integration->work_space_id,
        ];

        $integration->delete();

        Log::info('Integration deleted', ['id' => $id]);

        ActivityLogger::forRequest(
            $request,
            action: 'integration_deleted',
            title: 'Интеграция удалена',
            description: "Удалена интеграция «{$integrationSnapshot['name']}»",
            meta: [
                'entity_type' => 'integration',
                'entity_id' => $integrationSnapshot['id'],
                'marketplace' => $integrationSnapshot['marketplace'],
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Интеграция удалена',
        ]);
    }

    /**
     * Запустить синхронизацию для интеграции
     * Получает credentials из Sellico API по integration_id
     */
    public function sync(Request $request, int $id, ProductService $productService, SellicoApiService $sellicoApi, IntegrationAccessService $integrationAccess, LimitsSyncService $limitsSync): JsonResponse
    {
        $token = $request->bearerToken()
            ?? $request->header('X-Sellico-Token')
            ?? $request->header('X-Token');

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Токен не предоставлен',
            ], 401);
        }

        // Проверка доступа пользователя к интеграции через Sellico (по user-token + workspace-заголовку).
        // Без этой проверки любой авторизованный мог дёргать sync ЧУЖОЙ интеграции
        // и триггерить takeover work_space_id ниже.
        $access = $integrationAccess->ensureAccessibleIntegration($request, $id);
        if (! ($access['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $access['message'] ?? 'Нет доступа к интеграции',
            ], $access['status'] ?? 403);
        }

        // getIntegrationById использует сервисный токен (autobidder) для запросов к Sellico API
        // Пользовательский токен сохраняем для прокидывания в фоновые задачи
        $result = $sellicoApi->getIntegrationById($id);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Интеграция не найдена в Sellico',
            ], 404);
        }

        $integrationData = $result['integration'];
        $credentials = $result['credentials'];
        $credentials['_sellico_token'] = $token; // Прокидываем токен для фоновых задач
        $workspaceId = IntegrationAccessService::extractRemoteWorkspaceIdFromSellicoPayload($integrationData);
        $workspaceId = $workspaceId ?: (int) ($access['integration']->work_space_id ?? 0);
        $marketplace = strtolower($integrationData['type'] ?? '');

        // Нормализуем тип маркетплейса
        $marketplace = match ($marketplace) {
            'yandexmarket', 'yandex_market', 'yandex' => 'yandex_market',
            default => $marketplace,
        };

        if ($marketplace === 'yandex_market') {
            if (empty($credentials['campaign_id'] ?? null) && ! empty($credentials['client_id'] ?? null)) {
                $credentials['campaign_id'] = $credentials['client_id'];
            }
        }

        if (empty($credentials) || empty($marketplace)) {
            return response()->json([
                'success' => false,
                'message' => 'Не удалось получить credentials интеграции',
            ], 400);
        }

        // Сохраняем work_space_id в локальную запись если ещё не сохранён
        if ($workspaceId) {
            Integration::where('id', $id)->update(['work_space_id' => $workspaceId]);
        }

        $syncType = $request->input('type', 'products');

        if ($syncType === 'products' && $workspaceId) {
            $limitCheck = $limitsSync->ensureLimitAvailable((int) $workspaceId, 'products', 1);
            if (! ($limitCheck['success'] ?? false)) {
                return response()->json(
                    $limitsSync->limitResponsePayload($limitCheck),
                    (int) ($limitCheck['status'] ?? 403)
                );
            }
        }

        try {
            Log::info('Starting sync from Sellico integration', [
                'integration_id' => $id,
                'marketplace' => $marketplace,
                'sync_type' => $syncType,
                'workspace_id' => $workspaceId,
            ]);

            $syncLog = $productService->startSync(
                $marketplace,
                $credentials,
                $id,
                $syncType
            );

            ActivityLogger::forRequest(
                $request,
                action: 'integration_sync_triggered',
                title: 'Запущена синхронизация интеграции',
                description: "Пользователь запустил {$syncType}-синхронизацию ({$marketplace})",
                meta: [
                    'entity_type' => 'integration',
                    'entity_id' => $id,
                    'marketplace' => $marketplace,
                    'sync_type' => $syncType,
                    'sync_id' => $syncLog->id,
                ],
            );

            return response()->json([
                'success' => true,
                'message' => 'Синхронизация запущена',
                'data' => [
                    'sync_id' => $syncLog->id,
                    'status' => $syncLog->status,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Integration sync failed', [
                'integration_id' => $id,
                'marketplace' => $marketplace,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка запуска синхронизации: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Статус синхронизации интеграции
     */
    public function syncStatus(Request $request, int $id): JsonResponse
    {
        $integration = $this->authorizedIntegration($request, $id);
        if ($integration instanceof JsonResponse) {
            return $integration;
        }

        $lastSyncs = SyncLog::where('integration_id', $id)
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($sync) {
                return [
                    'id' => $sync->id,
                    'type' => $sync->sync_type,
                    'status' => $sync->status,
                    'synced' => $sync->items_synced,
                    'failed' => $sync->items_failed,
                    'started_at' => $sync->created_at,
                    'finished_at' => $sync->updated_at,
                    'error' => $sync->error_message,
                ];
            });

        $runningSyncs = SyncLog::where('integration_id', $id)
            ->running()
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'is_syncing' => $runningSyncs > 0,
                'last_sync_at' => $integration->last_sync_at,
                'last_sync_status' => $integration->last_sync_status,
                'recent_syncs' => $lastSyncs,
            ],
        ]);
    }

    public function performanceStatus(
        Request $request,
        int $id,
        SellicoApiService $sellicoApi,
        OzonPerformanceApiService $performanceApi
    ): JsonResponse {
        $resolved = $this->resolveOzonPerformanceRuntime($request, $id, $sellicoApi);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $integration = $resolved['integration'];
        $credentials = $resolved['credentials'];
        $check = $performanceApi->checkCredentials(is_array($credentials) ? $credentials : []);

        return response()->json([
            'success' => (bool) ($check['success'] ?? false),
            'data' => [
                'integration_id' => $integration->id,
                'name' => $integration->name,
                'marketplace' => $integration->marketplace,
                'source' => 'sellico_runtime_credentials',
                'has_performance_api_key' => (bool) ($credentials['performance_api_key'] ?? false),
                'has_performance_client_secret' => (bool) ($credentials['performance_client_secret'] ?? false),
                'performance_api' => $check,
            ],
        ], ($check['success'] ?? false) ? 200 : 422);
    }

    public function performanceSummary(
        Request $request,
        int $id,
        SellicoApiService $sellicoApi,
        OzonPerformanceApiService $performanceApi
    ): JsonResponse {
        $validated = Validator::make($request->all(), [
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'campaign_limit' => 'nullable|integer|min:1|max:100',
        ])->validate();

        $dateTo = $validated['date_to'] ?? now()->subDay()->toDateString();
        $dateFrom = $validated['date_from'] ?? now()->subDays(30)->toDateString();
        if ($dateFrom > $dateTo) {
            return response()->json([
                'success' => false,
                'message' => 'date_from не может быть позже date_to',
            ], 422);
        }

        $resolved = $this->resolveOzonPerformanceRuntime($request, $id, $sellicoApi);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $summary = $performanceApi->advertisingSummary(
            is_array($resolved['credentials']) ? $resolved['credentials'] : [],
            $dateFrom,
            $dateTo,
            (int) ($validated['campaign_limit'] ?? 50)
        );

        return response()->json([
            'success' => (bool) ($summary['success'] ?? false),
            'data' => [
                'integration_id' => $resolved['integration']->id,
                'name' => $resolved['integration']->name,
                'marketplace' => $resolved['integration']->marketplace,
                'source' => 'sellico_runtime_credentials',
                'summary' => $summary,
            ],
        ], ($summary['success'] ?? false) ? 200 : 422);
    }

    public function performanceCampaignObjects(
        Request $request,
        int $id,
        string $campaignId,
        SellicoApiService $sellicoApi,
        OzonPerformanceApiService $performanceApi
    ): JsonResponse {
        $resolved = $this->resolveOzonPerformanceRuntime($request, $id, $sellicoApi);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $objects = $performanceApi->campaignObjects(
            is_array($resolved['credentials']) ? $resolved['credentials'] : [],
            $campaignId
        );

        return response()->json([
            'success' => (bool) ($objects['success'] ?? false),
            'data' => [
                'integration_id' => $resolved['integration']->id,
                'campaign_id' => $campaignId,
                'source' => 'sellico_runtime_credentials',
                'objects' => $objects,
            ],
        ], ($objects['success'] ?? false) ? 200 : 422);
    }

    public function requestPerformanceProductReport(
        Request $request,
        int $id,
        SellicoApiService $sellicoApi,
        OzonPerformanceApiService $performanceApi
    ): JsonResponse {
        $validated = Validator::make($request->all(), [
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d',
        ])->validate();

        if ($validated['date_from'] > $validated['date_to']) {
            return response()->json([
                'success' => false,
                'message' => 'date_from не может быть позже date_to',
            ], 422);
        }

        $resolved = $this->resolveOzonPerformanceRuntime($request, $id, $sellicoApi);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $report = $performanceApi->requestProductStatisticsReport(
            is_array($resolved['credentials']) ? $resolved['credentials'] : [],
            $validated['date_from'],
            $validated['date_to']
        );

        return response()->json([
            'success' => (bool) ($report['success'] ?? false),
            'data' => [
                'integration_id' => $resolved['integration']->id,
                'source' => 'sellico_runtime_credentials',
                'uuid' => $report['uuid'] ?? null,
                'state' => $report['state'] ?? null,
                'status' => $report['status'] ?? null,
                'http_status' => $report['http_status'] ?? null,
                'period' => $report['period'] ?? null,
                'report' => $report,
            ],
        ], ($report['success'] ?? false) ? 200 : 422);
    }

    public function performanceReportStatus(
        Request $request,
        int $id,
        string $uuid,
        SellicoApiService $sellicoApi,
        OzonPerformanceApiService $performanceApi
    ): JsonResponse {
        $resolved = $this->resolveOzonPerformanceRuntime($request, $id, $sellicoApi);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $status = $performanceApi->reportStatus(
            is_array($resolved['credentials']) ? $resolved['credentials'] : [],
            $uuid
        );

        return response()->json([
            'success' => (bool) ($status['success'] ?? false),
            'data' => [
                'integration_id' => $resolved['integration']->id,
                'source' => 'sellico_runtime_credentials',
                'uuid' => $status['uuid'] ?? $uuid,
                'state' => $status['state'] ?? null,
                'status' => $status['status'] ?? null,
                'error' => $status['error'] ?? null,
                'file' => $status['file'] ?? null,
                'link' => $status['link'] ?? null,
                'report_status' => $status,
            ],
        ], ($status['success'] ?? false) ? 200 : 422);
    }

    public function performanceReportPreview(
        Request $request,
        int $id,
        string $uuid,
        SellicoApiService $sellicoApi,
        OzonPerformanceApiService $performanceApi
    ): JsonResponse {
        $validated = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:500',
        ])->validate();

        $resolved = $this->resolveOzonPerformanceRuntime($request, $id, $sellicoApi);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $preview = $performanceApi->productReportPreview(
            is_array($resolved['credentials']) ? $resolved['credentials'] : [],
            $uuid,
            (int) ($validated['limit'] ?? 50)
        );

        return response()->json([
            'success' => (bool) ($preview['success'] ?? false),
            'data' => [
                'integration_id' => $resolved['integration']->id,
                'source' => 'sellico_runtime_credentials',
                'product_report_preview' => $preview,
            ],
        ], ($preview['success'] ?? false) ? 200 : 422);
    }

    public function performanceAdvertisingImpact(
        Request $request,
        int $id,
        string $uuid,
        SellicoApiService $sellicoApi,
        OzonPerformanceApiService $performanceApi
    ): JsonResponse {
        $validated = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:5000',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
        ])->validate();

        $resolved = $this->resolveOzonPerformanceRuntime($request, $id, $sellicoApi);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $impact = $performanceApi->productAdvertisingImpact(
            is_array($resolved['credentials']) ? $resolved['credentials'] : [],
            $uuid,
            (int) ($validated['limit'] ?? 5000),
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null
        );

        return response()->json([
            'success' => (bool) ($impact['success'] ?? false),
            'data' => [
                'integration_id' => $resolved['integration']->id,
                'source' => 'sellico_runtime_credentials',
                'report_uuid' => $impact['report_uuid'] ?? $uuid,
                'summary' => $impact['summary'] ?? null,
                'products' => $impact['products'] ?? [],
                'advertising_impact' => $impact,
            ],
        ], ($impact['success'] ?? false) ? 200 : 422);
    }

    /**
     * @return array{integration: Integration, credentials: mixed}|JsonResponse
     */
    private function resolveOzonPerformanceRuntime(
        Request $request,
        int $id,
        SellicoApiService $sellicoApi
    ): array|JsonResponse {
        $integration = $this->authorizedIntegration($request, $id);
        if ($integration instanceof JsonResponse) {
            return $integration;
        }

        if (strtolower((string) $integration->marketplace) !== 'ozon') {
            return response()->json([
                'success' => false,
                'message' => 'Performance API сейчас проверяется только для Ozon',
            ], 422);
        }

        $token = $request->bearerToken()
            ?? $request->header('X-Sellico-Token')
            ?? $request->header('X-Token');
        if ($token) {
            $sellicoApi->setAccessToken($token);
        }

        $remote = $sellicoApi->getIntegrationById($id, (int) $integration->work_space_id ?: null);
        if (! ($remote['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $remote['error'] ?? 'Не удалось получить интеграцию из основного backend',
            ], 502);
        }

        return [
            'integration' => $integration,
            'credentials' => $remote['credentials'] ?? [],
        ];
    }

    /**
     * Проверить подключение (тест credentials)
     */
    public function testConnection(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'marketplace' => 'required|string|in:wildberries,ozon,yandex_market',
            'credentials' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $service = \App\Services\Marketplace\MarketplaceFactory::create(
                $request->marketplace,
                $request->credentials
            );

            // Пробуем получить товары (лимит 1)
            $products = $service->getProducts();

            return response()->json([
                'success' => true,
                'message' => 'Подключение успешно',
                'data' => [
                    'products_found' => count($products),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка подключения: '.$e->getMessage(),
            ], 400);
        }
    }

    /**
     * Установить ручной процент выкупа для не-Premium аккаунтов
     */
    public function setManualRedemptionRate(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'redemption_rate' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $integration = $this->authorizedIntegration($request, $id);
        if ($integration instanceof JsonResponse) {
            return $integration;
        }

        // Premium аккаунты получают данные автоматически — ручной ввод запрещён
        if ($integration->is_premium) {
            return response()->json([
                'success' => false,
                'message' => 'Premium аккаунт получает данные о выкупе автоматически. Ручной ввод недоступен.',
            ], 403);
        }

        $integration->update([
            'manual_redemption_rate' => $request->redemption_rate,
        ]);

        Log::info('Manual redemption rate set', [
            'integration_id' => $id,
            'redemption_rate' => $request->redemption_rate,
        ]);

        ActivityLogger::forRequest(
            $request,
            action: 'integration_manual_redemption_rate_set',
            title: 'Установлен ручной процент выкупа',
            description: "Ручной процент выкупа: {$request->redemption_rate}%",
            meta: [
                'entity_type' => 'integration',
                'entity_id' => $id,
                'redemption_rate' => (float) $request->redemption_rate,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Процент выкупа установлен',
            'data' => $this->formatIntegration($integration, true),
        ]);
    }

    /**
     * Получить Premium статус интеграции
     */
    public function getPremiumStatus(Request $request, int $id, IntegrationAccessService $integrationAccessService): JsonResponse
    {
        $resolution = $integrationAccessService->ensureAccessibleIntegration($request, $id);
        if (! ($resolution['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $resolution['message'] ?? 'Интеграция не найдена',
            ], $resolution['status'] ?? 404);
        }

        $integration = $resolution['integration'];

        return response()->json([
            'success' => true,
            'data' => [
                'is_premium' => $integration->is_premium,
                'premium_checked_at' => $integration->premium_checked_at,
                'manual_redemption_rate' => $integration->manual_redemption_rate,
                'redemption_source' => $integration->is_premium ? 'api' : ($integration->manual_redemption_rate ? 'manual' : 'fallback'),
            ],
        ]);
    }

    /**
     * Форматирование интеграции для ответа
     */
    private function formatIntegration(Integration $integration, bool $detailed = false): array
    {
        $data = [
            'id' => $integration->id,
            'name' => $integration->name,
            'marketplace' => $integration->marketplace,
            'is_active' => $integration->is_active,
            'auto_sync_enabled' => $integration->auto_sync_enabled,
            'sync_interval_hours' => $integration->sync_interval_hours,
            'last_sync_at' => $integration->last_sync_at,
            'last_sync_status' => $integration->last_sync_status,
            'products_count' => $integration->products_count,
            'is_premium' => $integration->is_premium,
            'manual_redemption_rate' => $integration->manual_redemption_rate,
            'created_at' => $integration->created_at,
            // Валидация интеграции
            'last_validation_at' => $integration->last_validation_at,
            'last_validation_status' => $integration->last_validation_status,
            'last_validation_error' => $detailed ? $integration->last_validation_error : null,
        ];

        if ($detailed) {
            $data['last_sync_error'] = $integration->last_sync_error;
            $data['settings'] = $integration->settings;
            $data['has_credentials'] = ! empty($integration->credentials);
            $data['premium_checked_at'] = $integration->premium_checked_at;
        }

        return $data;
    }

    /**
     * Валидация credentials по маркетплейсу
     */
    private function validateCredentials(string $marketplace, array $credentials): array
    {
        $errors = [];

        switch ($marketplace) {
            case 'wildberries':
                if (empty($credentials['api_key'])) {
                    $errors[] = 'api_key обязателен для Wildberries';
                }
                break;

            case 'ozon':
                if (empty($credentials['client_id'])) {
                    $errors[] = 'client_id обязателен для Ozon';
                }
                if (empty($credentials['api_key'])) {
                    $errors[] = 'api_key обязателен для Ozon';
                }
                break;

            case 'yandex_market':
                if (empty($credentials['token'])) {
                    $errors[] = 'token обязателен для Yandex Market';
                }
                break;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
