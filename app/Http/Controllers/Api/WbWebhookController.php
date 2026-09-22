<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWbWebhookJob;
use App\Models\WbWebhookConfig;
use App\Services\IntegrationAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WbWebhookController extends Controller
{
    public function __construct(
        private readonly IntegrationAccessService $integrationAccess,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $request->validate(['integration_id' => 'required|integer']);
        $integrationId = (int) $request->integration_id;
        $access = $this->integrationAccess->ensureAccessibleIntegration($request, $integrationId);
        if (! ($access['success'] ?? false)) {
            return response()->json([
                'message' => $access['message'] ?? 'Нет доступа к интеграции',
            ], $access['status'] ?? 403);
        }

        $config = WbWebhookConfig::where('integration_id', $integrationId)->first();

        return response()->json([
            'message' => 'OK',
            'data'    => $config
                ? [
                    'integration_id' => $config->integration_id,
                    'webhook_url' => $config->webhook_url,
                    'is_active' => $config->is_active,
                    'last_event_at' => $config->last_event_at,
                    'events_count' => $config->events_count,
                ]
                : ['integration_id' => $integrationId, 'is_active' => false],
        ]);
    }

    /**
     * В WB Seller API нет push/webhook-подписки: ни в одной из спек и в журнале
     * изменений метода нет, а push.wildberries.ru не резолвится. Регистрировать
     * нечего — честно отвечаем 501 без сетевого вызова и без записи конфига.
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate(['integration_id' => 'required|integer']);

        $access = $this->integrationAccess->ensureAccessibleIntegration($request, (int) $request->integration_id);
        if (! ($access['success'] ?? false)) {
            return response()->json([
                'message' => $access['message'] ?? 'Нет доступа к интеграции',
            ], $access['status'] ?? 403);
        }

        return response()->json([
            'message' => 'Wildberries не предоставляет API для подписки на вебхуки: регистрация невозможна. События WB получаем опросом API.',
        ], 501);
    }

    public function receive(Request $request, int $integrationId): JsonResponse
    {
        $config = WbWebhookConfig::where('integration_id', $integrationId)->first();
        if (!$config || !$config->is_active) {
            // Чтобы не светить факт наличия интеграции наружу — отвечаем 200.
            return response()->json(['message' => 'OK'], 200);
        }

        // Проверка HMAC-подписи. WB подписывает payload заголовком
        // X-Signature / X-Wb-Signature (HMAC-SHA256 тела ключом secret_key).
        // Без валидной подписи любой мог бы вбрасывать поддельные события и
        // забивать очередь ProcessWbWebhookJob.
        $signature = $request->header('X-Wb-Signature')
            ?? $request->header('X-Signature')
            ?? $request->header('X-Hub-Signature-256');

        $rawBody = $request->getContent();
        $secretKey = (string) ($config->secret_key ?? '');

        if ($secretKey === '') {
            Log::error('WbWebhookController: отсутствует secret_key в конфиге', [
                'integration_id' => $integrationId,
            ]);
            return response()->json(['message' => 'webhook not configured'], 503);
        }

        if (! $this->isSignatureValid($signature, $rawBody, $secretKey)) {
            Log::warning('WbWebhookController: неверная HMAC-подпись, запрос отклонён', [
                'integration_id' => $integrationId,
                'has_signature'  => $signature !== null,
                'body_size'      => strlen($rawBody),
            ]);
            return response()->json(['message' => 'invalid signature'], 401);
        }

        $payload = $request->all();

        ProcessWbWebhookJob::dispatch($integrationId, $payload);

        return response()->json(['message' => 'OK'], 200);
    }

    /**
     * Constant-time проверка HMAC-SHA256 подписи.
     * Принимает несколько форматов заголовка (raw hex / sha256=<hex> / base64).
     */
    private function isSignatureValid(?string $signature, string $rawBody, string $secretKey): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        // Нормализуем формат "sha256=<hex>"
        $normalized = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        $expected = hash_hmac('sha256', $rawBody, $secretKey);

        if (hash_equals($expected, $normalized)) {
            return true;
        }

        // Некоторые системы шлют base64 вместо hex — проверим и это.
        $expectedBase64 = base64_encode(hash_hmac('sha256', $rawBody, $secretKey, true));
        return hash_equals($expectedBase64, $normalized);
    }

    public function deactivate(Request $request): JsonResponse
    {
        $request->validate(['integration_id' => 'required|integer']);
        $integrationId = (int) $request->integration_id;
        $access = $this->integrationAccess->ensureAccessibleIntegration($request, $integrationId);
        if (! ($access['success'] ?? false)) {
            return response()->json([
                'message' => $access['message'] ?? 'Нет доступа к интеграции',
            ], $access['status'] ?? 403);
        }

        WbWebhookConfig::where('integration_id', $integrationId)
            ->update(['is_active' => false]);

        return response()->json(['message' => 'Вебхук деактивирован']);
    }
}
