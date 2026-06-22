<?php

namespace App\Domains\Uzum\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP клиент для Uzum Market Seller OpenAPI.
 *
 * Авторизация: заголовок `Authorization: <token>` БЕЗ префикса Bearer.
 */
class UzumClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout = 30;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        $this->apiKey = $apiKey ?? config('services.uzum.api_key') ?? '';
        $this->baseUrl = rtrim($baseUrl ?? config('services.uzum.base_url') ?? 'https://api-seller.uzum.uz/api/seller-openapi', '/');
    }

    /**
     * GET запрос к API.
     */
    public function get(string $endpoint, array $params = []): ?array
    {
        $requestId = uniqid('uzum_req_', true);
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withHeaders($this->getHeaders())
                    ->timeout($this->timeout)
                    ->get($this->baseUrl . $endpoint, $params);

                Log::info('Uzum API Response', [
                    'request_id' => $requestId,
                    'method' => 'GET',
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'response_size' => strlen($response->body()),
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() === 429 && $attempt < $maxAttempts) {
                    usleep($attempt * 1500000);
                    continue;
                }

                Log::warning('Uzum API error', [
                    'request_id' => $requestId,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                    'has_api_key' => $this->apiKey !== '',
                ]);

                return null;
            } catch (\Throwable $e) {
                Log::error('Uzum API exception', [
                    'request_id' => $requestId,
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
    }

    /**
     * POST запрос к API.
     */
    public function post(string $endpoint, array $data = []): ?array
    {
        $requestId = uniqid('uzum_req_', true);

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->asJson()
                ->post($this->baseUrl . $endpoint, $data);

            Log::info('Uzum API Response', [
                'request_id' => $requestId,
                'method' => 'POST',
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Uzum API error', [
                'request_id' => $requestId,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('Uzum API exception', [
                'request_id' => $requestId,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function getHeaders(): array
    {
        return [
            'Authorization' => $this->apiKey, // без префикса Bearer — требование Uzum
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }
}
