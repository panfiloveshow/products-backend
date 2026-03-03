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
        'products.index'               => 'products.view',
        'products.show'                => 'products.view',
        'products.store'               => 'products.create',
        'products.update'              => 'products.edit',
        'products.destroy'             => 'products.delete',
        'products.sync'                => 'products.sync.execute',
        'products.syncStatus'          => 'products.sync.status',
        'products.export'              => 'products.export',
        'products.cost-price.index'    => 'products.cost_price.view',
        'products.cost-price.upload'   => 'products.cost_price.upload',
        'products.cost-price.bulk'     => 'products.cost_price.edit',
        'products.cost-price.template' => 'products.cost_price.view',

        // Юнит-экономика
        'unit-economics.index'             => 'unit_economics.view',
        'unit-economics.marketplace'       => 'unit_economics.view',
        'unit-economics.details'           => 'unit_economics.details.view',
        'unit-economics.calculate'         => 'unit_economics.calculate',
        'unit-economics.settings.update'   => 'unit_economics.settings.edit',
        'unit-economics.settings.bulk'     => 'unit_economics.settings.edit',
        'unit-economics.recalculate'       => 'unit_economics.recalculate',
        'unit-economics.stats'             => 'unit_economics.stats.view',
        'unit-economics.stats.marketplace' => 'unit_economics.stats.view',
        'unit-economics.comparison'        => 'unit_economics.stats.view',
        'unit-economics.commissions'       => 'unit_economics.tariffs.view',
        'unit-economics.tariffs'           => 'unit_economics.tariffs.view',
        'unit-economics.cache-stats'       => 'unit_economics.view',

        // Автопланирование
        'auto-supply-plans.index'        => 'auto_supply.view',
        'auto-supply-plans.show'         => 'auto_supply.view',
        'auto-supply-plans.lines'        => 'auto_supply.view',
        'auto-supply-plans.lines.update' => 'auto_supply.view',
        'auto-supply-plans.warehouses'   => 'auto_supply.view',
        'auto-supply-plans.simulate'     => 'auto_supply.simulate',
        'auto-supply-plans.store'        => 'auto_supply.create',
        'auto-supply-plans.destroy'      => 'auto_supply.delete',
        'auto-supply-plans.calculate'    => 'auto_supply.calculate',
        'auto-supply-plans.export'       => 'auto_supply.export',
        'auto-supply-plans.export.xlsx'  => 'auto_supply.export',
        'auto-supply-plans.export.csv'   => 'auto_supply.export',
        'auto-supply-plans.export.wb'    => 'auto_supply.export',

        // Остатки
        'inventory.index'           => 'products.inventory.view',
        'inventory.show'            => 'products.inventory.view',
        'inventory.stats'           => 'products.inventory.view',
        'inventory.alerts'          => 'products.inventory.view',
        'inventory.recommendations' => 'products.inventory.view',
        'inventory.redistribution'  => 'products.inventory.view',
        'inventory.history'         => 'products.inventory.view',
        'inventory.forecast'        => 'products.inventory.view',
        'inventory.matrix'          => 'products.inventory.view',
        'inventory.sync'            => 'products.sync.execute',
        'inventory.syncStatus'      => 'products.sync.status',
        'inventory.syncStorageFees' => 'products.sync.execute',

        // Интеграции (служебные)
        'integrations.index'         => 'products.view',
        'integrations.premiumStatus' => 'products.view',
        'integrations.sync'          => 'products.sync.execute',
        'integrations.syncStatus'    => 'products.sync.status',

        // Поставки
        'shipments.index'                  => 'auto_supply.view',
        'shipments.show'                   => 'auto_supply.view',
        'shipments.slots'                  => 'auto_supply.view',
        'shipments.recommendations'        => 'auto_supply.view',
        'shipments.stats'                  => 'auto_supply.view',
        'shipments.store'                  => 'auto_supply.create',
        'shipments.update'                 => 'auto_supply.view',
        'shipments.destroy'                => 'auto_supply.delete',
        'shipments.createFromRecommendation' => 'auto_supply.create',
        'shipments.addItem'                => 'auto_supply.view',
        'shipments.updateItem'             => 'auto_supply.view',
        'shipments.removeItem'             => 'auto_supply.view',
        'shipments.submit'                 => 'auto_supply.view',
        'shipments.approve'                => 'auto_supply.view',
        'shipments.reject'                 => 'auto_supply.view',
        'shipments.send'                   => 'auto_supply.view',
        'shipments.deliver'                => 'auto_supply.view',
        'shipments.bookSlot'               => 'auto_supply.view',
        'shipments.export'                 => 'auto_supply.export',
        'shipments.export.csv'             => 'auto_supply.export',

        // Поставщики
        'suppliers.index'   => 'products.view',
        'suppliers.show'    => 'products.view',
        'suppliers.store'   => 'products.create',
        'suppliers.update'  => 'products.edit',
        'suppliers.destroy' => 'products.delete',

        // Складские остатки продавца
        'seller-stocks.index'      => 'products.warehouse_stock.edit',
        'seller-stocks.summary'    => 'products.inventory.view',
        'seller-stocks.catalog'    => 'products.inventory.view',
        'seller-stocks.upsert'     => 'products.warehouse_stock.edit',
        'seller-stocks.bulkUpsert' => 'products.warehouse_stock.edit',
        'seller-stocks.destroy'    => 'products.warehouse_stock.edit',

        // WB штрихкоды/себестоимость
        'wb-barcode-costs.index'      => 'products.cost_price.view',
        'wb-barcode-costs.bulkUpsert' => 'products.cost_price.edit',
        'wb-barcode-costs.destroy'    => 'products.cost_price.edit',

        // Отчёты Ozon
        'ozon-reports.index'           => 'unit_economics.stats.view',
        'ozon-reports.upload'          => 'unit_economics.stats.view',
        'ozon-reports.summary'         => 'unit_economics.stats.view',
        'ozon-reports.warehouseSales'  => 'unit_economics.stats.view',
        'ozon-reports.destroy'         => 'unit_economics.stats.view',
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

        if (config('services.sellico.skip_permission_check', false)) {
            return $next($request);
        }

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

        $plainToken = str_contains($token, '|') ? explode('|', $token, 2)[1] : $token;

        $requestParams = [
            'token'      => $plainToken,
            'user'       => $user,
            'workspace'  => $workspace,
            'permission' => $permission,
        ];

        Log::info('CheckSellicoPermission: отправка запроса к CRM', [
            'route'           => $routeName,
            'url'             => "{$crmUrl}/api/check-permission",
            'params'          => $requestParams,
            'service_token'   => $serviceToken
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
                Log::info('CheckSellicoPermission: повторная авторизация', [
                    'route'      => $routeName,
                    'permission' => $permission,
                    'workspace'  => $workspace,
                    'user'       => $user,
                    'token'      => $token,
                ]);
                $retryResponse = Http::timeout(5)
                    ->accept('application/json')
                    ->withToken($freshToken)
                    ->get("{$crmUrl}/api/check-permission", [
                        'token'      => $plainToken,
                        'user'       => $user,
                        'workspace'  => $workspace,
                        'permission' => $permission,
                    ]);
                Log::info($retryResponse);
                if ($retryResponse->successful()) {
                    $data = $retryResponse->json();
                    return (bool) ($data['valid'] ?? false);
                }
            }

            if ($response->status() === 403) {
                $body = $response->body();
                if (str_contains($body, 'service account')) {
                    Cache::forget(self::TOKEN_CACHE_KEY);
                }
                Log::warning('CheckSellicoPermission: доступ запрещён CRM', [
                    'route'      => $routeName,
                    'status'     => $response->status(),
                    'body'       => $body,
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
                    $token = str_contains($token, '|') ? explode('|', $token, 2)[1] : $token;
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
