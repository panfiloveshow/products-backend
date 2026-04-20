<?php

namespace App\Domains\Ozon\Api;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP клиент для Ozon API
 */
class OzonClient
{
    private const BASE_URL = 'https://api-seller.ozon.ru';
    
    private string $clientId;
    private string $apiKey;
    private int $timeout = 30;

    public function __construct(?string $clientId = null, ?string $apiKey = null)
    {
        $this->clientId = $clientId ?? config('services.ozon.client_id') ?? '';
        $this->apiKey = $apiKey ?? config('services.ozon.api_key') ?? '';
    }

    public function getClientCacheKey(): string
    {
        return hash('sha256', $this->clientId);
    }

    /**
     * Создать клиент из Integration модели
     */
    public static function fromIntegration(Integration $integration): self
    {
        $credentials = $integration->getDecryptedCredentials();
        return new self(
            $credentials['client_id'] ?? null,
            $credentials['api_key'] ?? null
        );
    }

    /**
     * POST запрос к API (основной метод для Ozon)
     * @param bool $forceObject Если true и $data пустой, отправляет {} вместо []
     */
    public function post(string $endpoint, array $data = [], bool $forceObject = false): ?array
    {
        $requestId = uniqid('ozon_req_', true);
        $maxAttempts = 3;
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
        ]);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withHeaders($this->getHeaders())
                    ->timeout($this->timeout)
                    ->asJson()
                    ->post(self::BASE_URL . $endpoint, $body);

                $status = $response->status();
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
                    return $response->json();
                }

                if ($response->status() === 429 && $attempt < $maxAttempts) {
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
                if (empty($decoded)) {
                    $decoded = ['error' => ['message' => $response->body()]];
                }

                $decoded['_error']       = true;
                $decoded['_http_status'] = $response->status();

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
                return null;
            }
        }

        return null;
    }

    /**
     * Скачать файл (PDF) из Ozon API
     */
    public function download(string $endpoint): ?string
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->get(self::BASE_URL . $endpoint);

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
        $requestId = uniqid('ozon_req_', true);
        
        Log::info('Ozon API Request', [
            'request_id' => $requestId,
            'method' => 'GET',
            'endpoint' => $endpoint,
            'url' => self::BASE_URL . $endpoint,
            'params' => $params,
            'client_id_prefix' => $this->clientId ? substr($this->clientId, 0, 4) . '***' : 'empty',
            'has_api_key' => !empty($this->apiKey),
        ]);

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->get(self::BASE_URL . $endpoint, $params);

            $status = $response->status();
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
