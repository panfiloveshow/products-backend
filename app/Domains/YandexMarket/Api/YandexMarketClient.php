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
    private int $timeout = 30;

    public function __construct(?string $token = null, ?string $campaignId = null, ?string $businessId = null)
    {
        $this->apiKey = $token ?? config('services.yandex_market.token', '');
        $this->campaignId = $campaignId ?? config('services.yandex_market.campaign_id', '');
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

            Log::warning('Yandex Market API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Yandex Market API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * POST запрос к API
     */
    public function post(string $endpoint, array $data = []): ?array
    {
        try {
            $url = $this->buildUrl($endpoint);
            
            $response = Http::withHeaders($this->getHeaders())
                ->timeout($this->timeout)
                ->post($url, $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Yandex Market API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Yandex Market API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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

            return null;
        } catch (\Exception $e) {
            Log::error('Yandex Market API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Построить URL с campaign_id
     */
    private function buildUrl(string $endpoint): string
    {
        $url = self::BASE_URL . $endpoint;
        
        // Заменить {campaignId} на реальный ID
        if (str_contains($endpoint, '{campaignId}')) {
            $url = str_replace('{campaignId}', $this->campaignId, $url);
        }
        
        return $url;
    }

    /**
     * Заголовки запроса
     */
    private function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Получить campaign_id
     */
    public function getCampaignId(): string
    {
        return $this->campaignId;
    }

    /**
     * Установить таймаут
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }
}
