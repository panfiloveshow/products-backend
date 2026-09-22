<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с отправлениями FBS
 * 
 * Endpoints:
 * - POST /v4/posting/fbs/list — список отправлений
 * - POST /v3/posting/fbs/get — детали отправления
 * - POST /v2/posting/fbs/cancel — отмена
 * - POST /v2/posting/fbs/cancel-reason/list — причины отмены
 *
 * Сборка (/v4/posting/fbs/ship), этикетки и отгрузка — в PostingService.
 */
class FbsPostingsApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получение списка отправлений FBS
     */
    public function list(array $filter = [], int $limit = 100, string $cursor = ''): array
    {
        $body = [
            // Контракт v3 FBO / v4 FBS: cursor вместо offset, limit ≤ 100, sort_dir.
            'sort_dir' => 'DESC',
            'limit' => min($limit, 100),
            'with' => [
                'analytics_data' => false,
                'financial_data' => true,
                'translit' => false,
            ],
        ];

        if (!empty($filter)) {
            $body['filter'] = $filter;
        }
        if ($cursor !== '') {
            $body['cursor'] = $cursor;
        }

        $response = $this->client->post('/v4/posting/fbs/list', $body);

        Log::info('Ozon FBS postings/list', [
            'filter' => $filter,
            'count' => count($response['postings'] ?? []),
        ]);

        return [
            'postings' => $response['postings'] ?? [],
            'cursor' => $response['cursor'] ?? null,
            'has_next' => (bool) ($response['has_next'] ?? false),
        ];
    }

    /**
     * Получение деталей отправления
     */
    public function get(string $postingNumber): array
    {
        $response = $this->client->post('/v3/posting/fbs/get', [
            'posting_number' => $postingNumber,
            'with' => [
                'analytics_data' => true,
                'financial_data' => true,
                'translit' => false,
            ],
        ]);

        return $response['result'] ?? [];
    }

    /**
     * Отмена отправления
     */
    public function cancel(string $postingNumber, int $cancelReasonId, string $message = ''): array
    {
        $response = $this->client->post('/v2/posting/fbs/cancel', [
            'posting_number' => $postingNumber,
            'cancel_reason_id' => $cancelReasonId,
            'cancel_reason_message' => $message,
        ]);

        Log::info('Ozon FBS posting/cancel', [
            'posting_number' => $postingNumber,
            'reason_id' => $cancelReasonId,
        ]);

        return [
            'success' => $response['result'] ?? false,
        ];
    }

    /**
     * Причины отмены для всех отправлений FBS: id, title, type_id,
     * is_available_for_cancellation. /v1/posting/fbs/cancel-reason/list убран
     * из документации — v2 без тела запроса.
     */
    public function getCancelReasons(): array
    {
        $response = $this->client->post('/v2/posting/fbs/cancel-reason/list', [], true);

        if (! is_array($response) || ! empty($response['_error'])) {
            throw new \RuntimeException('Ozon /v2/posting/fbs/cancel-reason/list: '.($response['message'] ?? $response['error']['message'] ?? 'нет ответа'));
        }

        return $response['result'] ?? [];
    }

    /**
     * Получение статуса акта
     */
    public function getActStatus(int $actId): array
    {
        $response = $this->client->post('/v2/posting/fbs/act/check-status', [
            'id' => $actId,
        ]);

        return $response['result'] ?? [];
    }

    /**
     * Скачать PDF акта
     */
    public function downloadAct(int $actId): array
    {
        $response = $this->client->post('/v2/posting/fbs/act/get-pdf', [
            'id' => $actId,
        ]);

        return [
            'content' => $response['content'] ?? null,
            'content_type' => 'application/pdf',
        ];
    }
}
