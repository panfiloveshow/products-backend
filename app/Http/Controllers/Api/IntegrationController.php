<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\StoreIntegrationRequest;
use App\Http\Requests\Integration\UpdateIntegrationRequest;
use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\ProductService;
use App\Services\SellicoApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class IntegrationController extends Controller
{
    /**
     * Список всех интеграций
     */
    public function index(Request $request): JsonResponse
    {
        $query = Integration::query();
        
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
     * Получить одну интеграцию
     */
    public function show(int $id): JsonResponse
    {
        $integration = Integration::find($id);
        
        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Интеграция не найдена',
            ], 404);
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
        $integration = Integration::find($id);
        
        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Интеграция не найдена',
            ], 404);
        }
        
        $validated = $request->validated();
        
        $integration->update($validated);
        
        return response()->json([
            'success' => true,
            'message' => 'Интеграция обновлена',
            'data' => $this->formatIntegration($integration),
        ]);
    }

    /**
     * Удалить интеграцию
     */
    public function destroy(int $id): JsonResponse
    {
        $integration = Integration::find($id);
        
        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Интеграция не найдена',
            ], 404);
        }
        
        // Проверяем, есть ли связанные товары
        $productsCount = $integration->products()->count();
        
        if ($productsCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Невозможно удалить интеграцию с {$productsCount} товарами. Сначала удалите товары.",
            ], 400);
        }
        
        $integration->delete();
        
        Log::info('Integration deleted', ['id' => $id]);
        
        return response()->json([
            'success' => true,
            'message' => 'Интеграция удалена',
        ]);
    }

    /**
     * Запустить синхронизацию для интеграции
     * Получает credentials из Sellico API по integration_id
     */
    public function sync(Request $request, int $id, ProductService $productService, SellicoApiService $sellicoApi): JsonResponse
    {
        $token = $request->bearerToken()
            ?? $request->header('X-Sellico-Token')
            ?? $request->header('X-Token');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Токен не предоставлен',
            ], 401);
        }
        
        // Устанавливаем токен для Sellico API
        $sellicoApi->setAccessToken($token);
        
        // Получаем credentials интеграции из Sellico
        $result = $sellicoApi->getIntegrationById($id);
        
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Интеграция не найдена в Sellico',
            ], 404);
        }
        
        $integrationData = $result['integration'];
        $credentials = $result['credentials'];
        $credentials['_sellico_token'] = $token; // Прокидываем токен для фоновых задач
        $marketplace = strtolower($integrationData['type'] ?? '');
        
        // Нормализуем тип маркетплейса
        $marketplace = match ($marketplace) {
            'yandexmarket', 'yandex_market', 'yandex' => 'yandex_market',
            default => $marketplace,
        };
        
        if (empty($credentials) || empty($marketplace)) {
            return response()->json([
                'success' => false,
                'message' => 'Не удалось получить credentials интеграции',
            ], 400);
        }
        
        $syncType = $request->input('type', 'products');
        
        try {
            Log::info('Starting sync from Sellico integration', [
                'integration_id' => $id,
                'marketplace' => $marketplace,
                'sync_type' => $syncType,
            ]);
            
            $syncLog = $productService->startSync(
                $marketplace,
                $credentials,
                $id,
                $syncType
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
                'message' => 'Ошибка запуска синхронизации: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Статус синхронизации интеграции
     */
    public function syncStatus(int $id): JsonResponse
    {
        $integration = Integration::find($id);
        
        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Интеграция не найдена',
            ], 404);
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
                    'synced' => $sync->synced_count,
                    'failed' => $sync->failed_count,
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

    /**
     * Проверить подключение (тест credentials)
     */
    public function testConnection(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'marketplace' => 'required|string|in:wildberries,ozon,yandex',
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
                'message' => 'Ошибка подключения: ' . $e->getMessage(),
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
        
        $integration = Integration::find($id);
        
        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Интеграция не найдена',
            ], 404);
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
        
        return response()->json([
            'success' => true,
            'message' => 'Процент выкупа установлен',
            'data' => $this->formatIntegration($integration, true),
        ]);
    }
    
    /**
     * Получить Premium статус интеграции
     */
    public function getPremiumStatus(int $id): JsonResponse
    {
        $integration = Integration::find($id);
        
        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Интеграция не найдена',
            ], 404);
        }
        
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
        ];
        
        if ($detailed) {
            $data['last_sync_error'] = $integration->last_sync_error;
            $data['settings'] = $integration->settings;
            $data['has_credentials'] = !empty($integration->credentials);
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
                
            case 'yandex':
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
