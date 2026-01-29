<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с возвратами FBS
 * 
 * Endpoints:
 * - POST /v3/returns/company/fbs — список возвратов
 * - POST /v2/returns/company/fbs/get — детали возврата
 */
class FbsReturnsApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получение списка возвратов FBS
     */
    public function list(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $body = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        if (!empty($filter)) {
            $body['filter'] = $filter;
        }

        $response = $this->client->post('/v3/returns/company/fbs', $body);

        Log::info('Ozon FBS returns/list', [
            'filter' => $filter,
            'count' => count($response['returns'] ?? []),
        ]);

        return [
            'returns' => $response['returns'] ?? [],
            'has_next' => $response['has_next'] ?? false,
        ];
    }

    /**
     * Получение деталей возврата
     */
    public function get(int $returnId): array
    {
        $response = $this->client->post('/v2/returns/company/fbs/get', [
            'return_id' => $returnId,
        ]);

        return $response['result'] ?? [];
    }
}
