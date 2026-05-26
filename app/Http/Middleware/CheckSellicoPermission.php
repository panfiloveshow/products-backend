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
     * TTL grace-кэша положительных результатов проверки прав (секунды) — 24 часа.
     * Используется как fallback, если CRM недоступен: ранее успешно проверенные
     * связки (token + workspace + permission) продолжают работать, но новые
     * получают 503. Это балансирует безопасность и устойчивость к CRM-outage.
     */
    protected const GRACE_CACHE_TTL = 86400;
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
        'products.export.status'       => 'products.export',
        'products.export.download'     => 'products.export',
        'products.cost-price.index'    => 'products.view',
        'products.cost-price.upload'   => 'products.edit',
        'products.cost-price.bulk'     => 'products.edit',
        'products.cost-price.template' => 'products.view',

        // Юнит-экономика
        'unit-economics.index'             => 'unit_economics.view',
        'unit-economics.marketplace'       => 'unit_economics.view',
        'unit-economics.details'           => 'unit_economics.details.view',
        'unit-economics.calculate'         => 'unit_economics.calculate',
        'unit-economics.settings.update'   => 'unit_economics.settings.edit',
        'unit-economics.settings.bulk'     => 'unit_economics.settings.edit',
        'unit-economics.wildberries.indexes.import' => 'unit_economics.settings.edit',
        'unit-economics.recalculate'       => 'unit_economics.recalculate',
        'unit-economics.stats'             => 'unit_economics.stats.view',
        'unit-economics.stats.marketplace' => 'unit_economics.stats.view',
        'unit-economics.comparison'        => 'unit_economics.stats.view',
        'unit-economics.commissions'       => 'unit_economics.tariffs.view',
        'unit-economics.tariffs'           => 'unit_economics.tariffs.view',
        'unit-economics.cache-stats'       => 'unit_economics.view',
        'unit-economics.freshness'         => 'unit_economics.view',
        'unit-economics.export.excel'      => 'unit_economics.view',

        // Автопланирование
        'auto-supply-plans.index'        => 'auto_supply.view',
        'auto-supply-plans.show'         => 'auto_supply.view',
        'auto-supply-plans.lines'        => 'auto_supply.view',
        'auto-supply-plans.clusters'     => 'auto_supply.view',
        'auto-supply-plans.lines.update' => 'auto_supply.view',
        'auto-supply-plans.warehouses'   => 'auto_supply.view',
        'auto-supply-plans.data-health'  => 'auto_supply.view',
        'auto-supply-plans.simulate'     => 'auto_supply.simulate',
        'auto-supply-plans.store'        => 'auto_supply.create',
        'auto-supply-plans.destroy'      => 'auto_supply.delete',
        'auto-supply-plans.calculate'    => 'auto_supply.calculate',
        'auto-supply-plans.export'       => 'auto_supply.export',
        'auto-supply-plans.export.xlsx'  => 'auto_supply.export',
        'auto-supply-plans.export.csv'   => 'auto_supply.export',
        'auto-supply-plans.export.wb'    => 'auto_supply.export',
        'auto-supply-plans.locality-impact' => 'auto_supply.view',
        'auto-supply-plans.cluster-split' => 'auto_supply.view',
        'auto-supply-plans.locality-recommendations' => 'auto_supply.view',
        'auto-supply-plans.cluster-draft-preview' => 'auto_supply.view',
        'auto-supply-plans.create-cluster-drafts' => 'auto_supply.export',
        'auto-supply-plans.from-locality-recommendations' => 'auto_supply.create',
        'auto-supply-plans.preview-split-by-cluster' => 'auto_supply.view',

        // Остатки
        'inventory.index'           => 'products.view',
        'inventory.show'            => 'products.view',
        'inventory.stats'           => 'products.view',
        'inventory.alerts'          => 'products.view',
        'inventory.recommendations' => 'products.view',
        'inventory.redistribution'  => 'products.view',
        'inventory.history'         => 'products.view',
        'inventory.forecast'        => 'products.view',
        'inventory.matrix'          => 'products.view',
        'inventory.sync'            => 'products.sync.execute',
        'inventory.syncStatus'      => 'products.sync.status',
        'inventory.syncStorageFees' => 'products.sync.execute',

        // Интеграции (служебные)
        'integrations.index'         => 'products.view',
        'integrations.premiumStatus' => 'products.view',
        'integrations.sync'          => 'products.sync.execute',
        'integrations.sync.direct'   => 'products.sync.execute',
        'integrations.manualRedemptionRate' => 'products.edit',
        'integrations.syncStatus'    => 'products.sync.status',
        'integrations.status'        => 'products.view',

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

        // Рекомендации поставок
        'supply-recommendations.index'       => 'auto_supply.view',
        'supply-recommendations.show'        => 'auto_supply.view',
        'supply-recommendations.byWarehouse' => 'auto_supply.view',
        'supply-recommendations.stats'       => 'auto_supply.view',
        'supply-recommendations.generate'    => 'auto_supply.calculate',
        'supply-recommendations.apply'       => 'auto_supply.create',
        'supply-recommendations.dismiss'     => 'auto_supply.view',

        // Планы поставок
        'supply-plans.index'     => 'auto_supply.view',
        'supply-plans.show'      => 'auto_supply.view',
        'supply-plans.store'     => 'auto_supply.create',
        'supply-plans.update'    => 'auto_supply.view',
        'supply-plans.destroy'   => 'auto_supply.delete',
        'supply-plans.calculate' => 'auto_supply.calculate',
        'supply-plans.approve'   => 'auto_supply.create',
        'supply-plans.cancel'    => 'auto_supply.view',

        // Слоты складов
        'warehouse-slots.index'      => 'auto_supply.view',
        'warehouse-slots.warehouses' => 'auto_supply.view',
        'warehouse-slots.sync'       => 'auto_supply.view',
        'warehouse-slots.book'       => 'auto_supply.create',
        'warehouse-slots.release'    => 'auto_supply.view',

        // Ozon FBO supplies
        'supplies.index'                                  => 'auto_supply.view',
        'supplies.show'                                   => 'auto_supply.view',
        'supplies.store'                                  => 'auto_supply.create',
        'supplies.store-manual'                           => 'auto_supply.create',
        'supplies.stats'                                  => 'auto_supply.view',
        'supplies.settings.index'                         => 'auto_supply.view',
        'supplies.settings.update'                        => 'auto_supply.view',
        'supplies.analytics'                              => 'auto_supply.view',
        'supplies.clusters'                               => 'auto_supply.view',
        'supplies.cluster-products'                       => 'auto_supply.view',
        'supplies.add-cluster-products'                   => 'auto_supply.create',
        'supplies.set-cluster-delivery-method'            => 'auto_supply.create',
        'supplies.set-cluster-warehouse'                  => 'auto_supply.create',
        'supplies.slots'                                  => 'auto_supply.view',
        'supplies.sync-slots'                             => 'auto_supply.view',
        'supplies.products-for-supply'                    => 'auto_supply.view',
        'supplies.create-with-slot'                       => 'auto_supply.create',
        'supplies.create-draft'                           => 'auto_supply.create',
        'supplies.timeslots'                              => 'auto_supply.view',
        'supplies.book-slot'                              => 'auto_supply.create',
        'supplies.start-preparing'                        => 'auto_supply.view',
        'supplies.ready-to-ship'                          => 'auto_supply.view',
        'supplies.ship'                                   => 'auto_supply.view',
        'supplies.cancel'                                 => 'auto_supply.view',
        'supplies.sync-status'                            => 'auto_supply.view',
        'supplies.events'                                 => 'auto_supply.view',
        'supplies.recommendations.index'                  => 'auto_supply.view',
        'supplies.recommendations.map'                    => 'auto_supply.view',
        'supplies.recommendations.map-warehouses'         => 'auto_supply.view',
        'supplies.recommendations.calculate'              => 'auto_supply.calculate',
        'supplies.recommendations.by-sku'                 => 'auto_supply.view',
        'supplies.recommendations.summary'                => 'auto_supply.view',
        'supplies.recommendations.accept'                 => 'auto_supply.create',
        'supplies.recommendations.reject'                 => 'auto_supply.view',
        'supplies.recommendations.postpone'               => 'auto_supply.view',
        'supplies.packages.index'                         => 'auto_supply.view',
        'supplies.packages.store'                         => 'auto_supply.create',
        'supplies.packages.show'                          => 'auto_supply.view',
        'supplies.packages.update'                        => 'auto_supply.view',
        'supplies.packages.destroy'                       => 'auto_supply.delete',
        'supplies.packages.add-item'                      => 'auto_supply.create',
        'supplies.packages.remove-item'                   => 'auto_supply.delete',
        'supplies.packages.pack'                          => 'auto_supply.view',
        'supplies.packages.auto-pack'                     => 'auto_supply.calculate',
        'supplies.packages.summary'                       => 'auto_supply.view',
        'supplies.documents.index'                        => 'auto_supply.view',
        'supplies.documents.show'                         => 'auto_supply.view',
        'supplies.documents.download'                     => 'auto_supply.export',
        'supplies.documents.package-label'                => 'auto_supply.export',
        'supplies.documents.all-labels'                   => 'auto_supply.export',
        'supplies.documents.packing-list'                 => 'auto_supply.export',

        // Ozon FBS postings
        'postings.index'          => 'auto_supply.view',
        'postings.statistics'     => 'auto_supply.view',
        'postings.sync'           => 'auto_supply.view',
        'postings.show'           => 'auto_supply.view',
        'postings.assemble'       => 'auto_supply.view',
        'postings.pack'           => 'auto_supply.view',
        'postings.ship'           => 'auto_supply.view',
        'postings.cancel'         => 'auto_supply.view',
        'postings.label'          => 'auto_supply.export',
        'postings.bulk-labels'    => 'auto_supply.export',
        'postings.bulk-ship'      => 'auto_supply.view',
        'postings.act.create'     => 'auto_supply.export',
        'postings.act.download'   => 'auto_supply.export',
        'postings.cancel-reasons' => 'auto_supply.view',
        'postings.returns'        => 'auto_supply.view',

        // Поставщики
        'suppliers.index'   => 'products.view',
        'suppliers.show'    => 'products.view',
        'suppliers.store'   => 'products.create',
        'suppliers.update'  => 'products.edit',
        'suppliers.destroy' => 'products.delete',

        // Складские остатки продавца
        'seller-stocks.index'      => 'products.edit',
        'seller-stocks.summary'    => 'products.view',
        'seller-stocks.catalog'    => 'products.view',
        'seller-stocks.upsert'     => 'products.edit',
        'seller-stocks.bulkUpsert' => 'products.edit',
        'seller-stocks.destroy'    => 'products.edit',

        // WB штрихкоды/себестоимость
        'wb-barcode-costs.index'      => 'products.view',
        'wb-barcode-costs.bulkUpsert' => 'products.edit',
        'wb-barcode-costs.destroy'    => 'products.edit',

        // Отчёты Ozon
        'ozon-reports.index'           => 'unit_economics.view',
        'ozon-reports.upload'          => 'unit_economics.view',
        'ozon-reports.summary'         => 'unit_economics.view',
        'ozon-reports.warehouseSales'  => 'unit_economics.view',
        'ozon-reports.destroy'         => 'unit_economics.view',

        // Locality Engine (Ozon FBO — встроен в юнит-экономику)
        'locality.overview'                        => 'unit_economics.view',
        'locality.skus'                            => 'unit_economics.view',
        'locality.clusters'                        => 'unit_economics.view',
        'locality.explain'                         => 'unit_economics.view',
        'locality.counterfactual'                  => 'unit_economics.view',
        'locality.explain.q'                       => 'unit_economics.view',
        'locality.counterfactual.q'                => 'unit_economics.view',
        'locality.recommendations.index'           => 'unit_economics.view',
        'locality.recommendations.show'            => 'unit_economics.view',
        'locality.recommendations.dismiss'         => 'unit_economics.edit',
        'locality.recommendations.draftPreview'    => 'unit_economics.view',
        'locality.recommendations.draftCreate'     => 'unit_economics.edit',
        'locality.reconciliation'                  => 'unit_economics.view',
        'locality.recompute'                       => 'unit_economics.view',
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

        // Kill-switch для локальной разработки. В production запрещён —
        // даже если флаг случайно попадёт в .env prod, доступ не откроется.
        if (config('services.sellico.skip_permission_check', false)) {
            if (app()->environment('production')) {
                Log::critical('CheckSellicoPermission: SELLICO_SKIP_PERMISSION_CHECK запрещён в production, игнорируем флаг', [
                    'route' => $routeName,
                ]);
            } else {
                Log::debug('CheckSellicoPermission: проверка прав пропущена через skip_permission_check (non-production)');
                return $next($request);
            }
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

        // Кешируем токен пользователя по workspace_id — используется CLI-синком для доступа к API
        Cache::put("workspace_user_token:{$workspace}", $token, now()->addHours(22));

        $tokenHash = md5($token);
        $cacheKey = "perm:{$workspace}:{$permission}:{$tokenHash}";
        $graceCacheKey = "perm_grace:{$workspace}:{$permission}:{$tokenHash}";

        $allowed = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($token, $user, $workspace, $permission, $routeName, $graceCacheKey) {
            $result = $this->checkPermissionRemotely($token, $user, $workspace, $permission, $routeName);
            // При подтверждённом доступе пишем в grace-кэш на 24 часа —
            // чтобы пережить кратковременный outage CRM без падения API.
            if ($result === true) {
                Cache::put($graceCacheKey, true, self::GRACE_CACHE_TTL);
            }
            return $result;
        });

        // Fail-closed with grace: при ошибке CRM пропускаем только тех, кто уже был
        // успешно проверен в последние 24 часа; новым запросам отвечаем 503.
        // Это ликвидирует прежний fail-open на любой ошибке/таймауте CRM.
        if ($allowed === null) {
            if (Cache::has($graceCacheKey)) {
                Log::warning('CheckSellicoPermission: CRM недоступен, используем grace-кэш', [
                    'route'      => $routeName,
                    'permission' => $permission,
                    'workspace'  => $workspace,
                ]);
                return $next($request);
            }

            Log::error('CheckSellicoPermission: CRM недоступен и нет grace-кэша — блокируем запрос', [
                'route'      => $routeName,
                'permission' => $permission,
                'workspace'  => $workspace,
            ]);

            return response()->json([
                'message' => 'Не удалось проверить права доступа. Попробуйте позже.',
                'error'   => 'permission_check_failed',
            ], 503);
        }

        if (!$allowed) {
            Log::error('CheckSellicoPermission: доступ запрещён', [
                'route'      => $routeName,
                'permission' => $permission,
                'workspace'  => $workspace,
                'user'       => $user,
            ]);
            
            return response()->json([
                'message'    => 'Недостаточно прав для выполнения этого действия',
                'error'      => 'permission_denied',
                'permission' => $permission,
                'workspace'  => $workspace,
                'route'      => $routeName,
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
        // Поддержка разных доменов: products.sellico.ru, sellico.ru, и т.д.
        $currentHost = request()->getHost();
        $crmUrl = config('services.crm.url') ?? 'https://sellico.ru';
        
        // Если мы на поддомене (например, products.sellico.ru), используем основной домен для CRM
        if (str_contains($currentHost, 'sellico.ru')) {
            $crmUrl = 'https://sellico.ru';
        }
        
        Log::debug('CheckSellicoPermission: проверка прав', [
            'current_host' => $currentHost,
            'crm_url' => $crmUrl,
            'permission' => $permission,
            'workspace' => $workspace,
        ]);

        $serviceToken = $this->getServiceToken();

        if (empty($serviceToken)) {
            Log::error('CheckSellicoPermission: не удалось получить сервисный токен, блокируем запрос', [
                'route'      => $routeName,
                'permission' => $permission,
            ]);
            // Fail-closed: без сервис-токена проверка невозможна.
            return null;
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
                    // Fail-closed: возвращаем null, middleware примет решение на основе grace-кэша.
                    return null;
                }
                Log::info('CheckSellicoPermission: повторная авторизация', [
                    'route'      => $routeName,
                    'permission' => $permission,
                    'workspace'  => $workspace,
                    'user'       => $user,
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
                
                // Сбрасываем токен и пробуем повторно авторизоваться
                Cache::forget(self::TOKEN_CACHE_KEY);
                $freshToken = $this->authorizeAndCacheToken();
                
                if ($freshToken) {
                    Log::info('CheckSellicoPermission: повторная авторизация после 403', [
                        'route'      => $routeName,
                        'permission' => $permission,
                        'workspace'  => $workspace,
                    ]);
                    
                    $retryResponse = Http::timeout(5)
                        ->accept('application/json')
                        ->withToken($freshToken)
                        ->get("{$crmUrl}/api/check-permission", $requestParams);
                    
                    if ($retryResponse->successful()) {
                        $data = $retryResponse->json();
                        return (bool) ($data['valid'] ?? false);
                    }
                    
                    if ($retryResponse->status() === 403) {
                        Log::error('CheckSellicoPermission: повторный запрос также вернул 403', [
                            'route'      => $routeName,
                            'body'       => $retryResponse->body(),
                            'permission' => $permission,
                        ]);
                    }
                }
                
                return false;
            }

            Log::error('CheckSellicoPermission: неожиданный ответ CRM', [
                'route'      => $routeName,
                'status'     => $response->status(),
                'permission' => $permission,
                'workspace'  => $workspace,
            ]);

            // Fail-closed: null → middleware решит через grace-кэш, иначе 503.
            return null;

        } catch (\Exception $e) {
            Log::error('CheckSellicoPermission: ошибка запроса к CRM', [
                'route'      => $routeName,
                'error'      => $e->getMessage(),
                'permission' => $permission,
                'workspace'  => $workspace,
            ]);

            // Fail-closed: null → middleware решит через grace-кэш, иначе 503.
            return null;
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
        $baseUrl  = config('services.sellico.base_url') ?? 'https://sellico.ru/api';
        $email    = config('services.sellico.email');
        $password = config('services.sellico.password');

        if (empty($email) || empty($password)) {
            Log::warning('CheckSellicoPermission: SELLICO_EMAIL или SELLICO_PASSWORD не заданы в .env', [
                'email_set' => !empty($email),
                'password_set' => !empty($password),
            ]);
            return null;
        }

        try {
            Log::info('CheckSellicoPermission: попытка авторизации в Sellico', [
                'url' => "{$baseUrl}/login",
                'email' => $email,
            ]);
            
            $response = Http::timeout(10)
                ->accept('application/json')
                ->post("{$baseUrl}/login", [
                    'email'    => $email,
                    'password' => $password,
                ]);

            Log::info('CheckSellicoPermission: ответ от Sellico login', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            if ($response->successful()) {
                $token = $response->json('access_token');

                if ($token) {
                    $token = str_contains($token, '|') ? explode('|', $token, 2)[1] : $token;
                    Cache::put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_CACHE_TTL);
                    Log::info('CheckSellicoPermission: авторизация в Sellico прошла успешно, токен закеширован', [
                        'token_length' => strlen($token),
                    ]);
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
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
}
