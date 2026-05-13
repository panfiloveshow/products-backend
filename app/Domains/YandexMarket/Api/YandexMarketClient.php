<?php

namespace App\Domains\YandexMarket\Api;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP клиент для Yandex Market API
 */
class YandexMarketClient
{
    private const BASE_URL = 'https://api.partner.market.yandex.ru';

    private string $apiKey;

    private string $authScheme;

    private ?string $campaignId;

    private ?string $businessId;

    private ?string $resolvedBusinessId = null;

    private int $timeout = 30;

    public function __construct(?string $token = null, ?string $campaignId = null, ?string $businessId = null)
    {
        $rawToken = (string) ($token ?? config('services.yandex_market.token') ?? '');
        $this->authScheme = $this->detectAuthScheme($rawToken);
        $this->apiKey = $this->normalizeToken($rawToken);
        $this->campaignId = $campaignId ?? config('services.yandex_market.campaign_id');
        $this->businessId = $businessId ?? config('services.yandex_market.business_id');
    }

    /**
     * Создать клиент из Integration модели
     */
    public static function fromIntegration(Integration $integration): self
    {
        $credentials = $integration->getDecryptedCredentials();

        return new self(
            $credentials['api_key'] ?? $credentials['token'] ?? null,
            $credentials['campaign_id'] ?? $credentials['client_id'] ?? '',
            $credentials['business_id'] ?? null
        );
    }

    /**
     * GET запрос к API
     */
    public function get(string $endpoint, array $params = []): ?array
    {
        $requestId = uniqid('ym_req_', true);
        $url = $this->buildUrl($endpoint);

        Log::info('Yandex Market API Request', [
            'request_id' => $requestId,
            'method' => 'GET',
            'endpoint' => $endpoint,
            'url' => $url,
            'params' => $params,
            'campaign_id' => $this->campaignId ? substr($this->campaignId, 0, 4) . '***' : 'empty',
            'has_token' => !empty($this->apiKey),
        ]);

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->get($url, $params);

            $status = $response->status();
            $body = $response->body();

            Log::info('Yandex Market API Response', [
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

            $message = $this->formatHttpError($endpoint, $status, $body);

            Log::warning('Yandex Market API error', [
                'request_id' => $requestId,
                'endpoint' => $endpoint,
                'status' => $status,
                'body' => substr($body, 0, 500),
            ]);

            throw new \RuntimeException($message);
        } catch (\Exception $e) {
            Log::error('Yandex Market API exception', [
                'request_id' => $requestId,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * POST запрос к API
     *
     * @param  array<string, mixed>  $query  Query string (page_token, limit, …)
     */
    public function post(string $endpoint, array $data = [], array $query = []): ?array
    {
        $requestId = uniqid('ym_req_', true);
        $url = $this->buildUrl($endpoint);
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $payload = $data === [] ? new \stdClass : $data;

        Log::info('Yandex Market API Request', [
            'request_id' => $requestId,
            'method' => 'POST',
            'endpoint' => $endpoint,
            'url' => $url,
            'payload_size' => strlen(json_encode($payload)),
            'payload_sample' => $this->truncateResponse(json_encode($payload), 500),
            'campaign_id' => $this->campaignId ? substr($this->campaignId, 0, 4) . '***' : 'empty',
            'has_token' => !empty($this->apiKey),
        ]);

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->post($url, $payload);

            $status = $response->status();
            $body = $response->body();

            Log::info('Yandex Market API Response', [
                'request_id' => $requestId,
                'method' => 'POST',
                'endpoint' => $endpoint,
                'status' => $status,
                'response_size' => strlen($body),
                'response_sample' => $this->truncateResponse($body, 500),
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            $message = $this->formatHttpError($endpoint, $status, $body);

            Log::warning('Yandex Market API error', [
                'request_id' => $requestId,
                'endpoint' => $endpoint,
                'status' => $status,
                'body' => substr($body, 0, 500),
            ]);

            throw new \RuntimeException($message);
        } catch (\Exception $e) {
            Log::error('Yandex Market API exception', [
                'request_id' => $requestId,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * ID кабинета для POST /v2/businesses/{businessId}/offer-mappings.
     * Берётся из credentials или из GET /v2/campaigns/{campaignId}.
     */
    public function resolveBusinessId(): string
    {
        $configured = $this->businessId !== null ? trim((string) $this->businessId) : '';
        if ($configured !== '') {
            return $configured;
        }
        if ($this->resolvedBusinessId !== null) {
            return $this->resolvedBusinessId;
        }
        $cid = trim((string) ($this->campaignId ?? ''));
        if ($cid === '') {
            throw new \RuntimeException('Yandex Market: укажите campaign_id или business_id');
        }
        $json = $this->get('/v2/campaigns/{campaignId}');
        $id = data_get($json, 'campaign.business.id')
            ?? data_get($json, 'result.campaign.business.id');
        if ($id === null || $id === '') {
            throw new \RuntimeException('Yandex Market: в ответе кампании нет business.id');
        }
        $this->resolvedBusinessId = (string) $id;

        return $this->resolvedBusinessId;
    }

    /**
     * PUT запрос к API
     */
    public function put(string $endpoint, array $data = []): ?array
    {
        try {
            $url = $this->buildUrl($endpoint);

            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->put($url, $data);

            if ($response->successful()) {
                return $response->json();
            }

            $message = $this->formatHttpError($endpoint, $response->status(), $response->body());

            Log::warning('Yandex Market API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException($message);
        } catch (\Exception $e) {
            Log::error('Yandex Market API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Построить URL с campaign_id
     */
    private function buildUrl(string $endpoint): string
    {
        $url = self::BASE_URL.$endpoint;

        if (str_contains($endpoint, '{campaignId}')) {
            $cid = (string) ($this->campaignId ?? '');
            $url = str_replace('{campaignId}', $cid, $url);
        }

        return $url;
    }

    /**
     * Заголовки запроса
     */
    private function getHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($this->authScheme === 'api_key') {
            $headers['Api-Key'] = $this->apiKey;
        } else {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        return $headers;
    }

    /**
     * Получить campaign_id
     */
    public function getCampaignId(): string
    {
        return (string) ($this->campaignId ?? '');
    }

    /**
     * Установить таймаут
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token);

        if ($token === '') {
            return '';
        }

        return preg_replace('/^(oauth|bearer)\s+/i', '', $token) ?? $token;
    }

    private function detectAuthScheme(string $token): string
    {
        $token = trim($token);

        if (str_starts_with($token, 'ACMA:')) {
            return 'api_key';
        }

        if (preg_match('/^(oauth|bearer)\s+/i', $token) === 1) {
            return 'oauth';
        }

        return str_contains($token, ':') ? 'api_key' : 'oauth';
    }

    private function formatHttpError(string $endpoint, int $status, string $body): string
    {
        $snippet = trim(mb_substr($body, 0, 300));

        return "Yandex Market API request failed: {$endpoint} [{$status}] {$snippet}";
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
