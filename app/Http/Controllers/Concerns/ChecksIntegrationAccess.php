<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Integration;
use App\Services\IntegrationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Общий helper для всех API-контроллеров, работающих с интеграциями.
 *
 * Закрывает массовый IDOR: раньше десятки методов делали Integration::findOrFail($id)
 * без проверки принадлежности интеграции текущему workspace. Любой авторизованный
 * пользователь мог подменить integration_id в запросе и ходить в чужие интеграции,
 * создавать поставки/черновики/webhooks на чужих магазинах, читать credentials
 * (расшифрованные через Eloquent cast).
 *
 * Использование (рекомендуется):
 *   use ChecksIntegrationAccess;
 *
 *   public function someAction(Request $request, int $id) {
 *       $integration = $this->resolveAccessibleIntegration($request, $id);
 *       if ($integration instanceof JsonResponse) return $integration;
 *       // ... дальше работа с $integration
 *   }
 *
 * Требует DI IntegrationAccessService либо через конструктор контроллера,
 * либо тянет через app() если $integrationAccess не задан.
 */
trait ChecksIntegrationAccess
{
    /**
     * Проверить доступ пользователя к интеграции и вернуть её,
     * либо JsonResponse с 403/404, который контроллер должен вернуть клиенту.
     */
    protected function resolveAccessibleIntegration(
        Request $request,
        int $integrationId,
        ?string $expectedMarketplace = null
    ): Integration|JsonResponse {
        $service = $this->integrationAccessService();

        $access = $service->ensureAccessibleIntegration($request, $integrationId, $expectedMarketplace);

        if (! ($access['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $access['message'] ?? 'Нет доступа к интеграции',
            ], $access['status'] ?? 403);
        }

        return $access['integration'];
    }

    private function integrationAccessService(): IntegrationAccessService
    {
        if (property_exists($this, 'integrationAccess') && $this->integrationAccess instanceof IntegrationAccessService) {
            return $this->integrationAccess;
        }

        return app(IntegrationAccessService::class);
    }
}
