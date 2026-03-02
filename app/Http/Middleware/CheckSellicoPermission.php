<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckSellicoPermission
{
    /**
     * TTL кэша результата проверки (секунды)
     */
    protected const CACHE_TTL = 60;

    /**
     * TTL кэша авторизационного токена (секунды) — 23 часа
     */
    protected const TOKEN_CACHE_TTL = 82800;

    /**
     * Ключ кэша для авторизационного токена сервис-аккаунта
     */
    protected const TOKEN_CACHE_KEY = 'sellico_access_token';
    /**
     * Маппинг роутов на permissions
     */
    private const ROUTE_PERMISSIONS = [
        // Товары
        'products.index' => 'products.view',
        'products.show' => 'products.view',
        'products.store' => 'products.create',
        'products.update' => 'products.edit',
        'products.destroy' => 'products.delete',
        'products.sync' => 'products.sync.execute',
        'products.syncStatus' => 'products.sync.status',
        'products.export' => 'products.export',
        'products.cost-price.index' => 'products.view',
        'products.cost-price.upload' => 'products.edit',
        'products.cost-price.bulk' => 'products.edit',
        'products.cost-price.template' => 'products.view',

        // Юнит-экономика
        'unit-economics.index' => 'unit_economics.view',
        'unit-economics.show' => 'unit_economics.view',
        'unit-economics.marketplace' => 'unit_economics.view',
        'unit-economics.details' => 'unit_economics.details.view',
        'unit-economics.calculate' => 'unit_economics.calculate',
        'unit-economics.settings.update' => 'unit_economics.settings.edit',
        'unit-economics.settings.bulk' => 'unit_economics.settings.edit',
        'unit-economics.recalculate' => 'unit_economics.recalculate',
        'unit-economics.stats' => 'unit_economics.stats.view',
        'unit-economics.stats.marketplace' => 'unit_economics.stats.view',
        'unit-economics.comparison' => 'unit_economics.stats.view',
        'unit-economics.commissions' => 'unit_economics.tariffs.view',
        'unit-economics.tariffs' => 'unit_economics.tariffs.view',
        'unit-economics.cache-stats' => 'unit_economics.view',

        // Автопланирование
        'auto-supply-plans.index' => 'auto_supply.view',
        'auto-supply-plans.show' => 'auto_supply.view',
        'auto-supply-plans.lines' => 'auto_supply.view',
        'auto-supply-plans.lines.update' => 'auto_supply.view',
        'auto-supply-plans.warehouses' => 'auto_supply.view',
        'auto-supply-plans.simulate' => 'auto_supply.simulate',
        'auto-supply-plans.store' => 'auto_supply.create',
        'auto-supply-plans.destroy' => 'auto_supply.delete',
        'auto-supply-plans.calculate' => 'auto_supply.calculate',
        'auto-supply-plans.export' => 'auto_supply.export',
        'auto-supply-plans.export.xlsx' => 'auto_supply.export',
        'auto-supply-plans.export.csv' => 'auto_supply.export',
        'auto-supply-plans.export.wb' => 'auto_supply.export',

        // Остатки
        'inventory.index' => 'inventory.view',
        'inventory.show' => 'inventory.view',
        'inventory.matrix' => 'inventory.view',
        'inventory.stats' => 'inventory.view',
        'inventory.alerts' => 'inventory.view',
        'inventory.recommendations' => 'inventory.view',
        'inventory.redistribution' => 'inventory.view',
        'inventory.history' => 'inventory.view',
        'inventory.forecast' => 'inventory.view',
        'inventory.sync' => 'inventory.sync.execute',
        'inventory.syncStatus' => 'inventory.sync.status',
        'inventory.syncStorageFees' => 'inventory.sync.execute',

        // Поставки
        'shipments.index' => 'shipments.view',
        'shipments.show' => 'shipments.view',
        'shipments.slots' => 'shipments.view',
        'shipments.recommendations' => 'shipments.view',
        'shipments.stats' => 'shipments.view',
        'shipments.store' => 'shipments.create',
        'shipments.update' => 'shipments.edit',
        'shipments.destroy' => 'shipments.delete',
        'shipments.addItem' => 'shipments.edit',
        'shipments.updateItem' => 'shipments.edit',
        'shipments.removeItem' => 'shipments.edit',
        'shipments.submit' => 'shipments.workflow',
        'shipments.approve' => 'shipments.workflow',
        'shipments.reject' => 'shipments.workflow',
        'shipments.send' => 'shipments.workflow',
        'shipments.deliver' => 'shipments.workflow',
        'shipments.bookSlot' => 'shipments.workflow',
        'shipments.export' => 'shipments.export',
        'shipments.export.csv' => 'shipments.export',
        'shipments.createFromRecommendation' => 'shipments.create',

        // Поставщики
        'suppliers.index' => 'suppliers.view',
        'suppliers.show' => 'suppliers.view',
        'suppliers.store' => 'suppliers.create',
        'suppliers.update' => 'suppliers.edit',
        'suppliers.destroy' => 'suppliers.delete',

        // Остатки продавца
        'seller-stocks.index' => 'seller_stocks.view',
        'seller-stocks.summary' => 'seller_stocks.view',
        'seller-stocks.catalog' => 'seller_stocks.view',
        'seller-stocks.upsert' => 'seller_stocks.edit',
        'seller-stocks.bulkUpsert' => 'seller_stocks.edit',
        'seller-stocks.destroy' => 'seller_stocks.delete',

        // WB себестоимости по баркодам
        'wb-barcode-costs.index' => 'wb_barcode_costs.view',
        'wb-barcode-costs.bulkUpsert' => 'wb_barcode_costs.edit',
        'wb-barcode-costs.destroy' => 'wb_barcode_costs.delete',

        // Ozon отчёты
        'ozon-reports.index' => 'ozon_reports.view',
        'ozon-reports.summary' => 'ozon_reports.view',
        'ozon-reports.warehouseSales' => 'ozon_reports.view',
        'ozon-reports.upload' => 'ozon_reports.upload',
        'ozon-reports.destroy' => 'ozon_reports.delete',

        // Интеграции
        'integrations.index' => 'integrations.view',
        'integrations.premiumStatus' => 'integrations.view',
        'integrations.sync' => 'integrations.sync',
        'integrations.syncStatus' => 'integrations.sync',
    ];

    /**
     * Проверка прав доступа через Sellico API
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if (!$routeName || !isset(self::ROUTE_PERMISSIONS[$routeName])) {
            Log::warning('CheckSellicoPermission: route not in permissions map', [
                'route'  => $routeName,
                'url'    => $request->fullUrl(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'message' => 'Доступ запрещён: роут не зарегистрирован в системе прав',
                'error'   => 'route_not_mapped',
                'route'   => $routeName,
            ], 403);
        }

        $permission = self::ROUTE_PERMISSIONS[$routeName];

        $token = $request->header('X-Sellico-Token')
            ?? $request->header('X-Token')
            ?? $request->bearerToken();

        $user = $request->header('X-Sellico-User')
            ?? $request->header('X-User-Id')
            ?? $request->header('X-User-Email')
            ?? 0;

        $workspace = $request->header('X-Sellico-Workspace')
            ?? $request->header('X-Workspace-Id')
            ?? $request->input('workspace');

        if (!$token || !$workspace) {
            Log::warning('CheckSellicoPermission: missing credentials', [
                'route'         => $routeName,
                'permission'    => $permission,
                'has_token'     => !empty($token),
                'has_workspace' => !empty($workspace),
            ]);

            return response()->json([
                'message' => 'Отсутствуют учётные данные для проверки прав доступа',
                'error'   => 'missing_credentials',
            ], 401);
        }

        $cacheKey = "perm:{$workspace}:{$permission}:" . md5($token);

        $allowed = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($token, $user, $workspace, $permission, $routeName) {
            return $this->checkPermissionRemotely($token, $user, $workspace, $permission, $routeName);
        });

        if ($allowed === null) {
            return response()->json([
                'message' => 'Не удалось проверить права доступа. Попробуйте позже.',
                'error'   => 'permission_check_failed',
            ], 503);
        }

        if (!$allowed) {
            return response()->json([
                'message'    => 'Недостаточно прав для выполнения этого действия',
                'error'      => 'permission_denied',
                'permission' => $permission,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Проверка права через Sellico API (GET /api/check-permission).
     * Вызов выполняется от имени сервис-аккаунта — токен берётся из кеша,
     * при отсутствии — выполняется авторизация через sellico.ru/api/login.
     * Возвращает: true — разрешено, false — запрещено, null — ошибка (fallback: пропускаем)
     */
    protected function checkPermissionRemotely(
        string $token,
        mixed $user,
        string $workspace,
        string $permission,
        string $routeName
    ): ?bool {
        $crmUrl = config('services.crm.url', 'https://sellico.ru');

        $serviceToken = $this->getServiceToken();

        if (empty($serviceToken)) {
            Log::warning('CheckSellicoPermission: не удалось получить сервисный токен, пропускаем проверку', [
                'route'      => $routeName,
                'permission' => $permission,
            ]);
            return true;
        }

        $requestParams = [
            'token'      => $token,
            'user'       => $user,
            'workspace'  => $workspace,
            'permission' => $permission,
        ];

        Log::info('CheckSellicoPermission: отправка запроса к CRM', [
            'route'           => $routeName,
            'url'             => "{$crmUrl}/api/check-permission",
            'params'          => $requestParams,
            'service_token'   => substr($serviceToken, 0, 10) . '...',
        ]);

        try {
            $response = Http::timeout(5)
                ->accept('application/json')
                ->withToken($serviceToken)
                ->get("{$crmUrl}/api/check-permission", $requestParams);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('CheckSellicoPermission: ответ от CRM', [
                    'route'      => $routeName,
                    'status'     => $response->status(),
                    'valid'      => $data['valid'] ?? null,
                    'permission' => $permission,
                    'workspace'  => $workspace,
                ]);

                return (bool) ($data['valid'] ?? false);
            }

            // Токен протух — сбрасываем кеш и повторяем авторизацию
            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);

                $freshToken = $this->authorizeAndCacheToken();

                if (!$freshToken) {
                    Log::warning('CheckSellicoPermission: повторная авторизация не удалась', [
                        'route'      => $routeName,
                        'permission' => $permission,
                    ]);
                    return true;
                }

                $retryResponse = Http::timeout(5)
                    ->accept('application/json')
                    ->withToken($freshToken)
                    ->get("{$crmUrl}/api/check-permission", [
                        'token'      => $token,
                        'user'       => $user,
                        'workspace'  => $workspace,
                        'permission' => $permission,
                    ]);

                if ($retryResponse->successful()) {
                    $data = $retryResponse->json();
                    return (bool) ($data['valid'] ?? false);
                }
            }

            if ($response->status() === 403) {
                Log::warning('CheckSellicoPermission: доступ запрещён CRM', [
                    'route'      => $routeName,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                    'permission' => $permission,
                    'workspace'  => $workspace,
                ]);
                return false;
            }

            Log::error('CheckSellicoPermission: неожиданный ответ CRM', [
                'route'      => $routeName,
                'status'     => $response->status(),
                'body'       => $response->body(),
                'permission' => $permission,
                'workspace'  => $workspace,
            ]);

            // Fallback: пропускаем при прочих ошибках CRM
            return true;

        } catch (\Exception $e) {
            Log::error('CheckSellicoPermission: ошибка запроса к CRM', [
                'route'      => $routeName,
                'error'      => $e->getMessage(),
                'permission' => $permission,
                'workspace'  => $workspace,
            ]);

            // Fallback: пропускаем при ошибках сети
            return true;
        }
    }

    /**
     * Получить сервисный токен: из кеша или через авторизацию.
     */
    protected function getServiceToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if ($cached) {
            return $cached;
        }

        return $this->authorizeAndCacheToken();
    }

    /**
     * Авторизация в sellico.ru/api/login с данными из .env, сохранение токена в кеш.
     */
    protected function authorizeAndCacheToken(): ?string
    {
        $baseUrl  = config('services.sellico.base_url', 'https://sellico.ru/api');
        $email    = config('services.sellico.email');
        $password = config('services.sellico.password');

        if (empty($email) || empty($password)) {
            Log::warning('CheckSellicoPermission: SELLICO_EMAIL или SELLICO_PASSWORD не заданы в .env');
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->accept('application/json')
                ->post("{$baseUrl}/login", [
                    'email'    => $email,
                    'password' => $password,
                ]);

            if ($response->successful()) {
                $token = $response->json('access_token');

                if ($token) {
                    Cache::put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_CACHE_TTL);
                    Log::info('CheckSellicoPermission: авторизация в Sellico прошла успешно, токен закешировн');
                    return $token;
                }

                Log::warning('CheckSellicoPermission: авторизация успешна, но access_token отсутствует в ответе', [
                    'body' => $response->body(),
                ]);
                return null;
            }

            Log::error('CheckSellicoPermission: ошибка авторизации в Sellico', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;

        } catch (\Exception $e) {
            Log::error('CheckSellicoPermission: исключение при авторизации в Sellico', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
