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
 *   (GET /v1/cargoes-label/file/{file_guid} отключён 10.04.2026 — PDF по ссылке result.file_url)
 */
class FboCargoesApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Создание грузомест для заявки
     */
    public function create(int $supplyId, array $cargoes): array
    {
        $response = $this->client->post('/v1/cargoes/create', [
            'supply_id' => $supplyId,
            'cargoes' => $cargoes,
        ]);

        Log::info('Ozon FBO cargoes/create', [
            'supply_id' => $supplyId,
            'cargoes_count' => count($cargoes),
        ]);

        return [
            'operation_id' => $response['operation_id'] ?? null,
            'success' => !empty($response['operation_id']) && empty($response['_error']) && empty($response['errors']),
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
    public function get(int $supplyId): array
    {
        $response = $this->client->post('/v1/cargoes/get', ['supply_ids' => [(string) $supplyId]]);
        $supply = collect($response['supply'] ?? [])->first(fn ($s) => (string) ($s['supply_id'] ?? '') === (string) $supplyId);

        return $supply['cargoes'] ?? [];
    }

    /**
     * Создание задачи на генерацию этикеток
     */
    public function createLabels(int $supplyId, array $cargoIds = []): array
    {
        $response = $this->client->post('/v1/cargoes-label/create', [
            'supply_id' => $supplyId,
            'cargoes' => array_map(fn ($id) => ['cargo_id' => (int) $id], $cargoIds),
        ]);

        Log::info('Ozon FBO cargoes-label/create', [
            'supply_id' => $supplyId,
            'task_id' => $response['operation_id'] ?? null,
        ]);

        return [
            'operation_id' => $response['operation_id'] ?? null,
            'task_id' => $response['operation_id'] ?? null,
            'success' => !empty($response['operation_id']) && empty($response['_error']) && empty($response['errors']),
        ];
    }

    /**
     * Получение статуса генерации этикеток
     */
    public function getLabelsStatus(string $taskId): array
    {
        $response = $this->client->post('/v1/cargoes-label/get', [
            'operation_id' => $taskId,
        ]);

        return [
            'status' => $response['status'] ?? null,
            'file_guid' => $response['file_guid'] ?? null,
            'file_url' => $response['result']['file_url'] ?? null,
            'error' => $response['errors'] ?? $response['error'] ?? null,
        ];
    }
}
