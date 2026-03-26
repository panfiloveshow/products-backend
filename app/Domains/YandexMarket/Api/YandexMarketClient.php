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

    private ?string $campaignId;

    private ?string $businessId;

    private ?string $resolvedBusinessId = null;

    private int $timeout = 30;

    public function __construct(?string $token = null, ?string $campaignId = null, ?string $businessId = null)
    {
        $this->apiKey = $this->normalizeToken((string) ($token ?? config('services.yandex_market.token') ?? ''));
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
        try {
            $url = $this->buildUrl($endpoint);

            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->get($url, $params);

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
     * POST запрос к API
     *
     * @param  array<string, mixed>  $query  Query string (page_token, limit, …)
     */
    public function post(string $endpoint, array $data = [], array $query = []): ?array
    {
        try {
            $url = $this->buildUrl($endpoint);
            if ($query !== []) {
                $url .= '?'.http_build_query($query);
            }

            $payload = $data === [] ? new \stdClass : $data;

            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->post($url, $payload);

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
        return [
            'Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
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

    private function formatHttpError(string $endpoint, int $status, string $body): string
    {
        $snippet = trim(mb_substr($body, 0, 300));

        return "Yandex Market API request failed: {$endpoint} [{$status}] {$snippet}";
    }
}
