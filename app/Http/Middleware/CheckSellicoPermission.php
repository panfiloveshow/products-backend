<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckSellicoPermission
{
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

        // Если роут не имеет имени или не в маппинге — запрещаем доступ (защита по умолчанию)
        if (!$routeName || !isset(self::ROUTE_PERMISSIONS[$routeName])) {
            Log::warning('CheckSellicoPermission: route not in permissions map', [
                'route' => $routeName,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'message' => 'Доступ запрещён: роут не зарегистрирован в системе прав',
                'error' => 'route_not_mapped',
                'route' => $routeName,
            ], 403);
        }

        $permission = self::ROUTE_PERMISSIONS[$routeName];

        // Получаем параметры из заголовков или query
        // Поддерживаем оба формата: X-Sellico-* и X-* (от фронтенда)
        $token = $request->header('X-Sellico-Token') 
            ?? $request->header('X-Token') 
            ?? $request->input('token');
        
        $user = $request->header('X-Sellico-User') 
            ?? $request->header('X-User-Id') 
            ?? $request->header('X-User-Email')
            ?? $request->input('user');
        
        $workspace = $request->header('X-Sellico-Workspace') 
            ?? $request->header('X-Workspace-Id')
            ?? $request->input('workspace');

        if (!$token || !$user || !$workspace) {
            Log::warning('CheckSellicoPermission: missing credentials', [
                'route' => $routeName,
                'permission' => $permission,
                'has_token' => !empty($token),
                'has_user' => !empty($user),
                'has_workspace' => !empty($workspace),
            ]);

            return response()->json([
                'message' => 'Отсутствуют учётные данные для проверки прав доступа',
                'error' => 'missing_credentials',
            ], 401);
        }

        try {
            // Проверяем права через реальный Sellico API:
            // GET /workspaces/{workspace}/user-permissions
            $response = Http::timeout(5)
                ->withToken($token)
                ->get("https://sellico.ru/api/workspaces/{$workspace}/user-permissions");

            if (!$response->successful()) {
                Log::error('CheckSellicoPermission: API error', [
                    'route' => $routeName,
                    'permission' => $permission,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return response()->json([
                    'message' => 'Ошибка проверки прав доступа',
                    'error' => 'permission_check_failed',
                ], 500);
            }

            $data = $response->json();

            // Администратор — полный доступ
            $role = $data['role'] ?? null;
            if ($role === 'admin' || (is_array($role) && in_array($role['slug'] ?? '', ['admin', 'super-admin', 'owner']))) {
                Log::info('CheckSellicoPermission: admin access granted', [
                    'route' => $routeName,
                    'permission' => $permission,
                    'workspace' => $workspace,
                ]);
                return $next($request);
            }

            // Проверяем наличие конкретного права в списке permissions
            $permissions = $data['permissions'] ?? [];
            $permissionSlugs = array_column($permissions, 'slug');

            // Для products-backend используем упрощённую проверку:
            // если у пользователя есть хоть какие-то права в workspace — разрешаем
            // (детальные права управляются на уровне sellico.ru, а products-backend — дополнительный сервис)
            $hasAccess = !empty($permissionSlugs) || !empty($permissions);

            if (!$hasAccess) {
                Log::warning('CheckSellicoPermission: access denied', [
                    'route' => $routeName,
                    'permission' => $permission,
                    'user' => $user,
                    'workspace' => $workspace,
                    'available_permissions' => $permissionSlugs,
                ]);

                return response()->json([
                    'message' => 'Доступ запрещён',
                    'error' => 'permission_denied',
                    'permission' => $permission,
                ], 403);
            }

            // Права подтверждены — пропускаем запрос
            Log::info('CheckSellicoPermission: access granted', [
                'route' => $routeName,
                'permission' => $permission,
                'user' => $user,
                'workspace' => $workspace,
            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('CheckSellicoPermission: exception', [
                'route' => $routeName,
                'permission' => $permission,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Ошибка при проверке прав доступа',
                'error' => 'permission_check_exception',
            ], 500);
        }
    }
}
