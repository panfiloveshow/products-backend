<?php

namespace App\Http\Middleware;

use App\Services\IntegrationAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-middleware, проверяющий доступ пользователя к интеграции.
 *
 * Закрывает массовый IDOR без точечных правок в десятках контроллеров:
 * достаточно навесить middleware на route и интеграция гарантированно
 * проверяется до того, как контроллер прочитает integration_id.
 *
 * Где ищет integration_id (первый найденный — winner):
 *   1. route-параметр {integrationId} / {integration_id} / {integration} / {id}
 *   2. query / body field `integration_id`
 *   3. body field `integrationId`
 *
 * Usage:
 *   Route::middleware('integration.access')->group(function () {
 *       Route::post('ozon/supplies/{integrationId}/create', ...);
 *   });
 *
 * Или точечно:
 *   Route::post('...', ...)->middleware('integration.access');
 *
 * Если integration_id нужно брать из body (например, для POST /ozon/supplies/batch):
 * передайте параметр через middleware-алиас с указанием поля,
 * например 'integration.access:integration_id' — сейчас реализовано авто-поиск
 * по стандартным именам.
 */
class EnsureIntegrationAccess
{
    public function __construct(
        private readonly IntegrationAccessService $integrationAccess,
    ) {
    }

    public function handle(Request $request, Closure $next, ?string $fieldOverride = null): Response
    {
        $integrationId = $this->extractIntegrationId($request, $fieldOverride);

        if ($integrationId === null) {
            return response()->json([
                'success' => false,
                'message' => 'integration_id обязателен',
                'error'   => 'missing_integration_id',
            ], 400);
        }

        $access = $this->integrationAccess->ensureAccessibleIntegration($request, $integrationId);

        if (! ($access['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $access['message'] ?? 'Нет доступа к интеграции',
            ], $access['status'] ?? 403);
        }

        // Чтобы контроллер мог переиспользовать уже провалидированную интеграцию
        // без повторных запросов к Sellico:
        $request->attributes->set('authorized_integration', $access['integration']);

        return $next($request);
    }

    private function extractIntegrationId(Request $request, ?string $fieldOverride): ?int
    {
        if ($fieldOverride !== null && $fieldOverride !== '') {
            $value = $request->input($fieldOverride);
            if ($this->isValidId($value)) {
                return (int) $value;
            }
        }

        foreach (['integrationId', 'integration_id', 'integration', 'id'] as $routeParam) {
            $value = $request->route($routeParam);
            if ($this->isValidId($value)) {
                return (int) $value;
            }
        }

        foreach (['integration_id', 'integrationId'] as $field) {
            $value = $request->input($field);
            if ($this->isValidId($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function isValidId(mixed $value): bool
    {
        return $value !== null
            && $value !== ''
            && is_numeric($value)
            && (int) $value > 0;
    }
}
