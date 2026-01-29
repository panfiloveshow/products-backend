<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с отправлениями FBS
 * 
 * Endpoints:
 * - POST /v3/posting/fbs/list — список отправлений
 * - POST /v3/posting/fbs/get — детали отправления
 * - POST /v3/posting/fbs/ship — отгрузка
 * - POST /v2/posting/fbs/package-label — этикетки
 * - POST /v2/posting/fbs/cancel — отмена
 * - POST /v1/posting/fbs/cancel-reason/list — причины отмены
 * - POST /v2/posting/fbs/act/create — создание акта
 */
class FbsPostingsApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Получение списка отправлений FBS
     */
    public function list(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $body = [
            'dir' => 'DESC',
            'limit' => $limit,
            'offset' => $offset,
            'with' => [
                'analytics_data' => false,
                'financial_data' => true,
                'translit' => false,
            ],
        ];

        if (!empty($filter)) {
            $body['filter'] = $filter;
        }

        $response = $this->client->post('/v3/posting/fbs/list', $body);

        Log::info('Ozon FBS postings/list', [
            'filter' => $filter,
            'count' => count($response['result']['postings'] ?? []),
        ]);

        return [
            'postings' => $response['result']['postings'] ?? [],
            'has_next' => $response['result']['has_next'] ?? false,
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
     * Отгрузка отправления (перевод в статус "Собирается")
     */
    public function ship(string $postingNumber, array $packages): array
    {
        $response = $this->client->post('/v3/posting/fbs/ship', [
            'posting_number' => $postingNumber,
            'packages' => $packages,
        ]);

        Log::info('Ozon FBS posting/ship', [
            'posting_number' => $postingNumber,
            'packages_count' => count($packages),
        ]);

        return $response['result'] ?? [];
    }

    /**
     * Получение этикетки отправления
     */
    public function getPackageLabel(string $postingNumber): array
    {
        $response = $this->client->post('/v2/posting/fbs/package-label', [
            'posting_number' => [$postingNumber],
        ]);

        return [
            'content' => $response['content'] ?? null,
            'content_type' => $response['content_type'] ?? 'application/pdf',
        ];
    }

    /**
     * Массовое получение этикеток
     */
    public function getPackageLabels(array $postingNumbers): array
    {
        $response = $this->client->post('/v2/posting/fbs/package-label', [
            'posting_number' => $postingNumbers,
        ]);

        return [
            'content' => $response['content'] ?? null,
            'content_type' => $response['content_type'] ?? 'application/pdf',
        ];
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
     * Получение списка причин отмены
     */
    public function getCancelReasons(): array
    {
        $response = $this->client->post('/v1/posting/fbs/cancel-reason/list', []);

        return $response['result'] ?? [];
    }

    /**
     * Создание акта приёма-передачи
     */
    public function createAct(int $containersCount, string $departureDate): array
    {
        $response = $this->client->post('/v2/posting/fbs/act/create', [
            'containers_count' => $containersCount,
            'delivery_method_id' => 0,
            'departure_date' => $departureDate,
        ]);

        Log::info('Ozon FBS act/create', [
            'containers_count' => $containersCount,
            'departure_date' => $departureDate,
        ]);

        return [
            'id' => $response['result']['id'] ?? null,
        ];
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
