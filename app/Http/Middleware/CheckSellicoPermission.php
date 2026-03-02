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

        $allowed = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($token, $workspace, $permission, $routeName) {
            return $this->checkPermissionRemotely($token, $workspace, $permission, $routeName);
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
     * Проверка права через Sellico API.
     * Возвращает: true — разрешено, false — запрещено, null — ошибка связи (fallback: пропускаем)
     */
    protected function checkPermissionRemotely(
        string $token,
        string $workspace,
        string $permission,
        string $routeName
    ): ?bool {
        $crmUrl = config('services.crm.url', 'https://sellico.ru');

        try {
            $response = Http::timeout(5)
                ->accept('application/json')
                ->get("{$crmUrl}/api/check-permission", [
                    'token'      => $token,
                    'user'       => 0,
                    'workspace'  => $workspace,
                    'permission' => $permission,
                ]);

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

            if ($response->status() === 401 || $response->status() === 403) {
                Log::warning('CheckSellicoPermission: доступ запрещён CRM', [
                    'route'      => $routeName,
                    'status'     => $response->status(),
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

            // Fallback: пропускаем при ошибках CRM
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
}
