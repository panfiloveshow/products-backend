<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SellicoApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkSpaceController extends Controller
{
    public function __construct(
        private SellicoApiService $sellicoApi
    ) {}

    /**
     * Получить лимиты workspace по тарифам/типам из основного backend.
     */
    public function getLimitsExternal(Request $request, int $workspace): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:products,autoplanning'],
        ]);

        $result = $this->sellicoApi->getWorkspaceLimitsExternal($workspace, $validated['type'] ?? null);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'message' => $result['error'] ?? 'Ошибка получения лимитов workspace',
            ], $this->normalizeStatus($result['status'] ?? 502));
        }

        return response()->json($result['limits'] ?? [], $this->normalizeStatus($result['status'] ?? 200));
    }

    /**
     * Сохранить лимиты workspace в основном backend.
     */
    public function storeLimitExternal(Request $request, int $workspace): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:products,autoplanning'],
            'value' => ['required', 'integer', 'min:0'],
        ]);

        $result = $this->sellicoApi->storeWorkspaceLimitExternal($workspace, $validated);

        if (! ($result['success'] ?? false)) {
            $body = [
                'message' => $result['error'] ?? 'Ошибка сохранения лимитов workspace',
            ];

            if (! empty($result['errors'])) {
                $body['errors'] = $result['errors'];
            }

            return response()->json($body, $this->normalizeStatus($result['status'] ?? 502));
        }

        return response()->json($result['limits'] ?? [], $this->normalizeStatus($result['status'] ?? 200));
    }

    /**
     * Синхронизировать абсолютное текущее значение лимита workspace.
     */
    public function syncLimitExternal(Request $request, int $workspace): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:products,autoplanning'],
            'current_value' => ['required_without:value', 'integer', 'min:0'],
            'value' => ['required_without:current_value', 'integer', 'min:0'],
        ]);

        $result = $this->sellicoApi->syncWorkspaceLimitExternal($workspace, $validated);

        if (! ($result['success'] ?? false)) {
            $body = [
                'message' => $result['error'] ?? 'Ошибка синхронизации лимита workspace',
            ];

            if (! empty($result['errors'])) {
                $body['errors'] = $result['errors'];
            }

            return response()->json($body, $this->normalizeStatus($result['status'] ?? 502));
        }

        return response()->json($result['limits'] ?? [], $this->normalizeStatus($result['status'] ?? 200));
    }

    private function normalizeStatus(int $status): int
    {
        return $status >= 100 && $status < 600 ? $status : 502;
    }
}
