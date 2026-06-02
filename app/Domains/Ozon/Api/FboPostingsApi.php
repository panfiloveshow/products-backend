<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с отправлениями FBO (аналитика)
 * 
 * Endpoints:
 * - POST /v3/posting/fbo/list — список отправлений
 * - POST /v2/posting/fbo/get — детали отправления
 */
class FboPostingsApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получение списка отправлений FBO
     */
    public function list(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $body = [
            'dir' => 'DESC',
            'limit' => $limit,
            'offset' => $offset,
            'with' => [
                'analytics_data' => true,
                'financial_data' => true,
            ],
        ];

        if (!empty($filter)) {
            $body['filter'] = $filter;
        }

        $response = $this->client->post('/v3/posting/fbo/list', $body);

        Log::info('Ozon FBO postings/list', [
            'filter' => $filter,
            'count' => count($response['result']['postings'] ?? $response['result'] ?? []),
        ]);

        return [
            'postings' => $response['result']['postings'] ?? $response['result'] ?? [],
        ];
    }

    /**
     * Получение деталей отправления FBO
     */
    public function get(string $postingNumber): array
    {
        $response = $this->client->post('/v2/posting/fbo/get', [
            'posting_number' => $postingNumber,
            'with' => [
                'analytics_data' => true,
                'financial_data' => true,
            ],
        ]);

        return $response['result'] ?? [];
    }

    /**
     * Получение списка причин отмены FBO отправлений
     */
    public function getCancelReasons(): array
    {
        $response = $this->client->post('/v1/posting/fbo/cancel-reason/list', []);

        return $response['result'] ?? $response ?? [];
    }
}
