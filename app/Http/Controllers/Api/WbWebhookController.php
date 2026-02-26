<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWbWebhookJob;
use App\Models\Integration;
use App\Models\WbWebhookConfig;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WbWebhookController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $request->validate(['integration_id' => 'required|integer']);

        $config = WbWebhookConfig::where('integration_id', $request->integration_id)->first();

        return response()->json([
            'message' => 'OK',
            'data'    => $config ?? ['integration_id' => $request->integration_id, 'is_active' => false],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'integration_id' => 'required|integer',
            'webhook_url'    => 'required|url|max:500',
        ]);

        $integrationId = (int) $request->integration_id;
        $webhookUrl    = $request->webhook_url;

        $integration = Integration::find($integrationId);
        if (!$integration) {
            return response()->json(['message' => 'Интеграция не найдена'], 404);
        }

        $secretKey = Str::random(32);

        $config = WbWebhookConfig::updateOrCreate(
            ['integration_id' => $integrationId],
            [
                'webhook_url' => $webhookUrl,
                'secret_key'  => $secretKey,
                'is_active'   => false,
            ]
        );

        // Регистрируем вебхук в WB API
        $credentials = $integration->getDecryptedCredentials();
        $wbToken = $credentials['api_key'] ?? $credentials['token'] ?? null;
        if (!$wbToken) {
            return response()->json(['message' => 'Токен WB не найден в интеграции'], 422);
        }

        try {
            $response = Http::withToken($wbToken)
                ->timeout(15)
                ->post('https://push.wildberries.ru/api/v1/subscribe', [
                    'url' => $webhookUrl,
                ]);

            if ($response->successful()) {
                $config->update(['is_active' => true]);
                return response()->json([
                    'message' => 'Вебхук успешно зарегистрирован',
                    'data'    => $config,
                ]);
            }

            Log::warning('WbWebhookController: ошибка регистрации вебхука WB', [
                'integration_id' => $integrationId,
                'status'         => $response->status(),
                'body'           => $response->body(),
            ]);

            return response()->json([
                'message' => 'Ошибка регистрации в WB: ' . $response->status(),
                'data'    => ['wb_response' => $response->json()],
            ], 422);

        } catch (\Exception $e) {
            Log::error('WbWebhookController: исключение при регистрации', [
                'integration_id' => $integrationId,
                'error'          => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Ошибка соединения с WB API: ' . $e->getMessage()], 500);
        }
    }

    public function receive(Request $request, int $integrationId): JsonResponse
    {
        $payload = $request->all();

        Log::info('WbWebhookController: получено событие', [
            'integration_id' => $integrationId,
            'payload'        => $payload,
        ]);

        $config = WbWebhookConfig::where('integration_id', $integrationId)->first();
        if (!$config || !$config->is_active) {
            return response()->json(['message' => 'OK'], 200);
        }

        ProcessWbWebhookJob::dispatch($integrationId, $payload);

        return response()->json(['message' => 'OK'], 200);
    }

    public function deactivate(Request $request): JsonResponse
    {
        $request->validate(['integration_id' => 'required|integer']);

        WbWebhookConfig::where('integration_id', $request->integration_id)
            ->update(['is_active' => false]);

        return response()->json(['message' => 'Вебхук деактивирован']);
    }
}
