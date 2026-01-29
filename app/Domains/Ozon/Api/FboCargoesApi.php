<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * API для работы с грузоместами FBO
 * 
 * Endpoints:
 * - POST /v1/cargoes/create — создание грузомест
 * - POST /v2/cargoes/create/info — статус создания
 * - POST /v1/cargoes/get — получение грузомест
 * - POST /v1/cargoes-label/create — создание этикеток
 * - POST /v1/cargoes-label/get — статус этикеток
 * - GET /v1/cargoes-label/file/{file_guid} — скачать PDF
 */
class FboCargoesApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Создание грузомест для заявки
     */
    public function create(int $supplyOrderId, array $cargoes): array
    {
        $response = $this->client->post('/v1/cargoes/create', [
            'supply_order_id' => $supplyOrderId,
            'cargoes' => $cargoes,
        ]);

        Log::info('Ozon FBO cargoes/create', [
            'supply_order_id' => $supplyOrderId,
            'cargoes_count' => count($cargoes),
        ]);

        return [
            'operation_id' => $response['operation_id'] ?? null,
            'success' => !empty($response['operation_id']),
        ];
    }

    /**
     * Получение статуса создания грузомест
     */
    public function getCreateStatus(string $operationId): array
    {
        $response = $this->client->post('/v2/cargoes/create/info', [
            'operation_id' => $operationId,
        ]);

        return $response ?? [];
    }

    /**
     * Получение грузомест заявки
     */
    public function get(int $supplyOrderId): array
    {
        $response = $this->client->post('/v1/cargoes/get', [
            'supply_order_id' => $supplyOrderId,
        ]);

        return $response['cargoes'] ?? [];
    }

    /**
     * Создание задачи на генерацию этикеток
     */
    public function createLabels(int $supplyOrderId): array
    {
        $response = $this->client->post('/v1/cargoes-label/create', [
            'supply_order_id' => $supplyOrderId,
        ]);

        Log::info('Ozon FBO cargoes-label/create', [
            'supply_order_id' => $supplyOrderId,
            'task_id' => $response['task_id'] ?? null,
        ]);

        return [
            'task_id' => $response['task_id'] ?? null,
            'success' => !empty($response['task_id']),
        ];
    }

    /**
     * Получение статуса генерации этикеток
     */
    public function getLabelsStatus(string $taskId): array
    {
        $response = $this->client->post('/v1/cargoes-label/get', [
            'task_id' => $taskId,
        ]);

        return [
            'status' => $response['status'] ?? null,
            'file_guid' => $response['file_guid'] ?? null,
            'error' => $response['error'] ?? null,
        ];
    }

    /**
     * Скачать PDF с этикетками
     */
    public function downloadLabels(string $fileGuid): ?string
    {
        $response = $this->client->get("/v1/cargoes-label/file/{$fileGuid}");
        
        return $response['content'] ?? null;
    }
}
