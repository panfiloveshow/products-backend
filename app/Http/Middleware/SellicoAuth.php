<?php

namespace App\Http\Middleware;

use App\Services\SellicoApiService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SellicoAuth
{
    public function __construct(
        private SellicoApiService $sellicoApi
    ) {}

    /**
     * Проверка авторизации через Sellico API
     * Проверяет Bearer token и валидирует его через внешний API
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Токен авторизации не предоставлен',
            ], 401);
        }

        // Проверяем токен через Sellico API
        Log::info('SellicoAuth: Checking token', [
            'token_length' => strlen($token),
            'token_prefix' => substr($token, 0, 20) . '...',
        ]);
        
        $this->sellicoApi->setAccessToken($token);
        $result = $this->sellicoApi->getProfile();

        Log::info('SellicoAuth: Sellico API response', [
            'success' => $result['success'] ?? false,
            'error' => $result['error'] ?? null,
        ]);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Недействительный токен авторизации',
                'debug_error' => $result['error'] ?? null,
            ], 401);
        }

        // Сохраняем данные пользователя в request для использования в контроллерах
        $request->merge([
            'sellico_user' => $result['user'] ?? null,
            'sellico_token' => $token,
        ]);

        return $next($request);
    }
}
