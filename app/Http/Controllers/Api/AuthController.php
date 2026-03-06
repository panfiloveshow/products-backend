<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SellicoApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private SellicoApiService $sellicoApi
    ) {}

    /**
     * Авторизация через Sellico API
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $result = $this->sellicoApi->login(
            $request->input('email'),
            $request->input('password')
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 401);
        }

        // Получаем workspaces
        $workspacesResult = $this->sellicoApi->getWorkspaces();

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $result['access_token'],
                'user' => $result['user'],
                'workspaces' => $workspacesResult['workspaces'] ?? [],
            ],
        ]);
    }

    /**
     * Получить профиль пользователя
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Токен не предоставлен',
            ], 401);
        }

        $this->sellicoApi->setAccessToken($token);
        $result = $this->sellicoApi->getProfile();

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $result['user'],
        ]);
    }

    /**
     * Получить список workspaces
     * GET /api/auth/workspaces
     */
    public function workspaces(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Токен не предоставлен',
            ], 401);
        }

        $this->sellicoApi->setAccessToken($token);
        $result = $this->sellicoApi->getWorkspaces();

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $result['workspaces'],
        ]);
    }

    /**
     * Получить интеграции с маркетплейсами для workspace
     * GET /api/auth/workspaces/{workspaceId}/integrations
     */
    public function integrations(Request $request, int $workspaceId): JsonResponse
    {
        if (!$workspaceId) {
            return response()->json([
                'success' => false,
                'message' => 'workspace_id обязателен',
            ], 422);
        }

        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Токен не предоставлен',
            ], 401);
        }

        $this->sellicoApi->setAccessToken($token);
        $result = $this->sellicoApi->getMarketplaceCredentials($workspaceId);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'integrations' => $result['integrations'],
                'all' => $result['all'],
            ],
        ]);
    }

    /**
     * Сохранить API ключ маркетплейса
     * POST /api/auth/integrations/{marketplace}
     */
    public function saveIntegration(Request $request, string $marketplace): JsonResponse
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Токен не предоставлен',
            ], 401);
        }

        // Валидация в зависимости от маркетплейса
        $rules = match ($marketplace) {
            'wildberries' => ['api_key' => 'required|string'],
            'ozon' => ['client_id' => 'required|string', 'api_key' => 'required|string'],
            'yandex_market' => ['token' => 'required|string', 'campaign_id' => 'required|string'],
            default => [],
        };

        if (empty($rules)) {
            return response()->json([
                'success' => false,
                'message' => 'Неизвестный маркетплейс',
            ], 400);
        }

        $request->validate($rules);

        $this->sellicoApi->setAccessToken($token);
        $result = $this->sellicoApi->saveMarketplaceCredentials($marketplace, $request->all());

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $result['integration'],
            'message' => 'Интеграция сохранена',
        ]);
    }

    /**
     * Удалить интеграцию с маркетплейсом
     * DELETE /api/auth/integrations/{marketplace}
     */
    public function deleteIntegration(Request $request, string $marketplace): JsonResponse
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Токен не предоставлен',
            ], 401);
        }

        $this->sellicoApi->setAccessToken($token);
        $result = $this->sellicoApi->deleteIntegration($marketplace);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Интеграция удалена',
        ]);
    }

    /**
     * Проверить подключение к маркетплейсу
     * POST /api/auth/integrations/{marketplace}/test
     */
    public function testIntegration(Request $request, string $marketplace): JsonResponse
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Токен не предоставлен',
            ], 401);
        }

        $this->sellicoApi->setAccessToken($token);
        $result = $this->sellicoApi->testConnection($marketplace);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'connected' => $result['connected'],
            'message' => $result['message'],
        ]);
    }
}
