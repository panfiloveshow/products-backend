<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWbWebhookJob;
use App\Models\Integration;
use App\Models\WbWebhookConfig;
use App\Services\IntegrationAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WbWebhookController extends Controller
{
    public function __construct(
        private readonly IntegrationAccessService $integrationAccess,
    ) {
    }

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

        // Базовая валидация URL уже есть (url|max:500), добавляем защиту от SSRF —
        // запрещаем приватные диапазоны/localhost в хосте webhook.
        if (! $this->isSafeExternalUrl($webhookUrl)) {
            return response()->json(['message' => 'webhook_url указывает на внутренний адрес'], 422);
        }

        // IDOR-защита: проверяем что текущий пользователь имеет доступ к интеграции.
        $access = $this->integrationAccess->ensureAccessibleIntegration($request, $integrationId);
        if (! ($access['success'] ?? false)) {
            return response()->json([
                'message' => $access['message'] ?? 'Нет доступа к интеграции',
            ], $access['status'] ?? 403);
        }
        $integration = $access['integration'];

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
            $response = Http::withHeaders([
                'User-Agent' => config('services.wildberries.user_agent', 'wbas_sellico.ru9757'),
            ])
                ->withToken($wbToken)
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
     * Разрешаем только публичные http(s)-URL. Блокируем localhost и
     * приватные диапазоны (RFC1918), metadata-IP (169.254.169.254),
     * cloud metadata на IPv6.
     */
    private function isSafeExternalUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parts['host']);

        // Явный блэк-лист по имени
        $bannedHostSubstrings = ['localhost', 'metadata.google.internal'];
        foreach ($bannedHostSubstrings as $banned) {
            if (str_contains($host, $banned)) {
                return false;
            }
        }

        // Разрешаем через IP только, если он не приватный/link-local.
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            // Не смогли резолвить — разрешаем, WB сам дальше не достучится.
            return true;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
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

        WbWebhookConfig::where('integration_id', $request->integration_id)
            ->update(['is_active' => false]);

        return response()->json(['message' => 'Вебхук деактивирован']);
    }
}
