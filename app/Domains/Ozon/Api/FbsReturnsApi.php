<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с возвратами FBS
 * 
 * Endpoints:
 * - POST /v1/returns/list (filter.return_schema = FBS) — список возвратов.
 *   /v3/returns/company/fbs и /v2/returns/company/fbs/get удалены 18.02.2025.
 */
class FbsReturnsApi
{
    private const PAGE_LIMIT = 500; // максимум /v1/returns/list

    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получение списка возвратов FBS.
     *
     * $filter['status'] → filter.visual_status_name (DisputeOpened, MovingToSeller, …).
     * У /v1/returns/list курсор last_id вместо offset: до нужной страницы
     * пролистываем пачками по 500. Элементы — как в ответе /v1/returns/list.
     */
    public function list(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $apiFilter = ['return_schema' => 'FBS'];
        if (! empty($filter['status'])) {
            $apiFilter['visual_status_name'] = (string) $filter['status'];
        }

        $need = max(0, $offset) + max(1, $limit);
        $collected = [];
        $lastId = 0;

        do {
            $body = ['filter' => $apiFilter, 'limit' => min(self::PAGE_LIMIT, $need - count($collected))];
            if ($lastId > 0) {
                $body['last_id'] = $lastId;
            }

            $response = $this->client->post('/v1/returns/list', $body);
            if (! is_array($response) || ! empty($response['_error'])) {
                throw new \RuntimeException('Ozon /v1/returns/list: '.($response['message'] ?? $response['error']['message'] ?? 'нет ответа'));
            }

            $page = $response['returns'] ?? [];
            $collected = array_merge($collected, $page);
            $hasNext = (bool) ($response['has_next'] ?? false);
            // last_id в ответе нет — курсор = id последнего возврата страницы.
            $nextLastId = $page === [] ? 0 : (int) ($page[array_key_last($page)]['id'] ?? 0);
            $moved = $nextLastId > 0 && $nextLastId !== $lastId;
            $lastId = $nextLastId;
        } while ($hasNext && $moved && count($collected) < $need);

        Log::info('Ozon FBS returns/list', [
            'filter' => $apiFilter,
            'count' => count($collected),
        ]);

        return [
            'returns' => array_slice($collected, max(0, $offset), max(1, $limit)),
            'has_next' => $hasNext && $moved,
        ];
    }
}
