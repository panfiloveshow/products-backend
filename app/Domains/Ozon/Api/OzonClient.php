<?php

namespace App\Domains\Ozon\Api;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use App\Services\Marketplace\PriceDispatchAuthorization;
use App\Services\Marketplace\PriceDispatchDenied;
use App\Services\Manager\ManagerFbsExecutionPolicy;
use Illuminate\Support\Facades\Log;

/**
 * HTTP клиент для Ozon API
 */
class OzonClient
{
    private const BASE_URL = 'https://api-seller.ozon.ru';
    
    private string $clientId;
    private string $apiKey;
    private string $accessToken;
    private int $timeout = 30;
    private ?int $lastResponseStatus = null;
    private ?int $lastRateLimitRetryAfter = null;

    public function getLastResponseStatus(): ?int
    {
        return $this->lastResponseStatus;
    }

    public function getLastRateLimitRetryAfter(): ?int
    {
        return $this->lastRateLimitRetryAfter;
    }

    private function rememberResponse(\Illuminate\Http\Client\Response $response): void
    {
        $this->lastResponseStatus = $response->status();
        $this->lastRateLimitRetryAfter = null;
        if ($response->status() === 429) {
            $retry = $response->header('Retry-After');
            $seconds = is_numeric($retry) ? (int) ceil((float) $retry) : (($retry && strtotime($retry)) ? strtotime($retry) - time() : 60);
            $this->lastRateLimitRetryAfter = max(1, $seconds);
        }
    }

    public function __construct(
        ?string $clientId = null,
        ?string $apiKey = null,
        ?string $accessToken = null
    )
    {
        $this->clientId = $clientId ?? config('services.ozon.client_id') ?? '';
        $this->apiKey = $apiKey ?? config('services.ozon.api_key') ?? '';
        $this->accessToken = $accessToken ?? config('services.ozon.access_token') ?? '';
    }

    public function getClientCacheKey(): string
    {
        return hash('sha256', $this->clientId . '|' . $this->accessToken);
    }

    /**
     * Создать клиент из Integration модели
     */
    public static function fromIntegration(Integration $integration): self
    {
        // resolveCredentials: локальные креды могут быть пустыми, рабочие — из Sellico.
        $credentials = $integration->resolveCredentials();
        return new self(
            $credentials['client_id'] ?? null,
            $credentials['api_key'] ?? null,
            $credentials['oauth_access_token'] ?? $credentials['access_token'] ?? null
        );
    }

    /**
     * POST запрос к API (основной метод для Ozon)
     * @param bool $forceObject Если true и $data пустой, отправляет {} вместо []
     * @param bool $retryRateLimit false — на 429 не ретраить (методы с лимитом «раз в минуту/сутки»)
     */
    public function post(string $endpoint, array $data = [], bool $forceObject = false, bool $retryRateLimit = true): ?array
    {
        $this->lastResponseStatus = null;
        $this->lastRateLimitRetryAfter = null;
        $requestId = uniqid('ozon_req_', true);
        $priceMutation = PriceDispatchAuthorization::isOzonMutation($endpoint);
        $fbsMutation = ManagerFbsExecutionPolicy::isMutation('ozon', 'POST', self::BASE_URL, $endpoint);
        $fboMutation = \App\Services\Manager\ManagerFboPublicationGuard::isMutation($endpoint);
        $maxAttempts = ($priceMutation || $fbsMutation || $fboMutation) ? 1 : 3;
        $dispatchId = null;
        $nativeRequestStarted = false;
        $body = (empty($data) && $forceObject) ? (object)[] : $data;

        Log::info('Ozon API Request', [
            'request_id' => $requestId,
            'method' => 'POST',
            'endpoint' => $endpoint,
            'url' => self::BASE_URL . $endpoint,
            'payload_size' => strlen(json_encode($body)),
            'payload_sample' => $this->truncateResponse(json_encode($body), 500),
            'client_id_prefix' => $this->clientId ? substr($this->clientId, 0, 4) . '***' : 'empty',
            'has_api_key' => !empty($this->apiKey),
            'auth_type' => $this->accessToken !== '' ? 'oauth' : 'api_key',
        ]);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $pending = Http::withHeaders($this->getHeaders())->timeout($this->timeout)->asJson();
                if ($priceMutation) {
                    $wire = PriceDispatchAuthorization::encode($body);
                    $account = PriceDispatchAuthorization::ozonAccount($this->clientId, $this->apiKey, $this->accessToken);
                    $grant = app(PriceDispatchAuthorization::class)->authorize('ozon', $account, $endpoint, $wire);
                    $dispatchId = $grant['command_id'];
                    $pending->beforeSending(function () use ($grant, &$nativeRequestStarted): void {
                        PriceDispatchAuthorization::assertFresh($grant);
                        $nativeRequestStarted = true;
                    });
                    $response = $pending->withOptions(['allow_redirects' => false])->withBody($wire, 'application/json')->post(self::BASE_URL . $endpoint);
                } else {
                    $response = $fboMutation
                        ? app(\App\Services\Manager\ManagerFboPublicationGuard::class)->legacy($this->accessToken !== '' ? null : $this->clientId,
                            fn () => $pending->withOptions(['allow_redirects' => false])->post(self::BASE_URL . $endpoint, $body), isset($data['draft_id']) ? (string) $data['draft_id'] : null)
                        : ($fbsMutation
                        ? app(ManagerFbsExecutionPolicy::class)->legacy('ozon', $this->apiKey, $this->accessToken !== '' ? null : $this->clientId, fn () => $pending->withOptions(['allow_redirects' => false])->post(self::BASE_URL . $endpoint, $body))
                        : $pending->post(self::BASE_URL . $endpoint, $body));
                }

                $status = $response->status();
                $this->rememberResponse($response);
                $bodyContent = $response->body();

                Log::info('Ozon API Response', [
                    'request_id' => $requestId,
                    'method' => 'POST',
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'status' => $status,
                    'response_size' => strlen($bodyContent),
                    'response_sample' => $this->truncateResponse($bodyContent, 500),
                ]);

                if ($response->successful()) {
                    $decoded = $response->json();
                    if ($priceMutation) {
                        if (! is_array($decoded)) return ['_error' => true, '_http_status' => $status, '_sellico_price_dispatch' => ['command_id' => $dispatchId, 'status' => 'submit_unknown']];
                        $decoded['_sellico_price_dispatch'] = ['command_id' => $dispatchId, 'status' => 'receipt_received'];
                    }
                    return $decoded;
                }

                if ($response->status() === 429 && $retryRateLimit && $attempt < $maxAttempts) {
                    $delay = $attempt * 1500000;
                    Log::warning('Ozon API rate limit, retry', [
                        'request_id' => $requestId,
                        'endpoint' => $endpoint,
                        'attempt'  => $attempt,
                        'delay_ms' => $delay / 1000,
                    ]);
                    usleep($delay);
                    continue;
                }

                $decoded = $response->json();
                if (! is_array($decoded) || empty($decoded)) {
                    $decoded = ['error' => ['message' => $response->body()]];
                }

                $decoded['_error']       = true;
                $decoded['_http_status'] = $response->status();
                if ($priceMutation) $decoded['_sellico_price_dispatch'] = ['command_id' => $dispatchId, 'status' => $status < 400 || $status >= 500 || $status === 408 || $status === 499 ? 'submit_unknown' : 'rejected'];

                Log::warning('Ozon API error', [
                    'request_id' => $requestId,
                    'endpoint'  => $endpoint,
                    'status'    => $status,
                    'body'      => substr($bodyContent, 0, 500),
                    'client_id' => $this->clientId ? substr($this->clientId, 0, 4) . '***' : 'empty',
                    'has_api_key' => !empty($this->apiKey),
                ]);

                return $decoded;
            } catch (\Exception $e) {
                Log::error('Ozon API exception', [
                    'request_id' => $requestId,
                    'endpoint'  => $endpoint,
                    'attempt' => $attempt,
                    'error'     => $e->getMessage(),
                    'client_id' => $this->clientId ? substr($this->clientId, 0, 4) . '***' : 'empty',
                ]);
                $dispatch = ['command_id' => $dispatchId, 'status' => $nativeRequestStarted ? 'submit_unknown' : 'not_dispatched'];
                if (! $nativeRequestStarted && $e instanceof PriceDispatchDenied) $dispatch['denial'] = $e->publicData();
                return $priceMutation ? ['_error' => true, '_http_status' => 0, '_sellico_price_dispatch' => $dispatch] : null;
            }
        }

        return null;
    }

    /**
     * Скачать файл (PDF) из Ozon API
     */
    public function download(string $endpoint, ?array $data = null): ?string
    {
        $this->lastResponseStatus = null;
        $this->lastRateLimitRetryAfter = null;
        try {
            $request = Http::withHeaders($this->getHeaders())->timeout($this->timeout);
            $response = $data === null
                ? $request->get(self::BASE_URL . $endpoint)
                : $request->asJson()->post(self::BASE_URL . $endpoint, $data);
            $this->rememberResponse($response);

            if ($response->successful()) {
                return $response->body();
            }

            Log::warning('Ozon API file download error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Ozon API file download exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * GET запрос к API
     */
    public function get(string $endpoint, array $params = []): ?array
    {
        $this->lastResponseStatus = null;
        $this->lastRateLimitRetryAfter = null;
        $requestId = uniqid('ozon_req_', true);
        
        Log::info('Ozon API Request', [
            'request_id' => $requestId,
            'method' => 'GET',
            'endpoint' => $endpoint,
            'url' => self::BASE_URL . $endpoint,
            'params' => $params,
            'client_id_prefix' => $this->clientId ? substr($this->clientId, 0, 4) . '***' : 'empty',
            'has_api_key' => !empty($this->apiKey),
            'auth_type' => $this->accessToken !== '' ? 'oauth' : 'api_key',
        ]);

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->get(self::BASE_URL . $endpoint, $params);

            $status = $response->status();
            $this->rememberResponse($response);
            $body = $response->body();

            Log::info('Ozon API Response', [
                'request_id' => $requestId,
                'method' => 'GET',
                'endpoint' => $endpoint,
                'status' => $status,
                'response_size' => strlen($body),
                'response_sample' => $this->truncateResponse($body, 500),
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Ozon API error', [
                'request_id' => $requestId,
                'endpoint' => $endpoint,
                'status' => $status,
                'body' => substr($body, 0, 500),
                'client_id_prefix' => $this->clientId ? substr($this->clientId, 0, 4) . '***' : 'empty',
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Ozon API exception', [
                'request_id' => $requestId,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'client_id_prefix' => $this->clientId ? substr($this->clientId, 0, 4) . '***' : 'empty',
            ]);
            return null;
        }
    }

    /**
     * Заголовки запроса
     */
    private function getHeaders(): array
    {
        if ($this->accessToken !== '') {
            return [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
        }

        return [
            'Client-Id' => $this->clientId,
            'Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Установить таймаут
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Обрезать тело ответа для логирования
     */
    private function truncateResponse(string $body, int $maxLength = 500): string
    {
        if (strlen($body) <= $maxLength) {
            return $body;
        }
        return substr($body, 0, $maxLength) . '... [truncated]';
    }
}
