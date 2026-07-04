<?php

namespace Tests\Unit;

use App\Services\LimitsSyncService;
use App\Services\SellicoApiService;
use Tests\TestCase;

class LimitsSyncServiceTest extends TestCase
{
    public function test_missing_external_limit_is_treated_as_unlimited(): void
    {
        $service = new LimitsSyncService($this->sellicoApi([
            'success' => false,
            'status' => 404,
            'error' => 'Limit not found.',
        ]));

        $result = $service->getWorkspaceLimit(25, 'autoplanning');

        $this->assertTrue($result['success']);
        $this->assertNull($result['limit']);
        $this->assertTrue($result['missing_limit']);
    }

    public function test_sync_treats_missing_external_limit_as_success_not_failure(): void
    {
        // Регрессия: limits:sync-products падал каждый прогон (exit 1) для workspace,
        // уже удалённого на стороне Sellico — 404 "Limit not found" считался провалом
        // синка, хотя для getWorkspaceLimit() этот же 404 давно трактуется как норма.
        $service = new LimitsSyncService($this->sellicoApi([
            'success' => false,
            'status' => 404,
            'error' => 'Limit not found.',
        ], syncResponse: [
            'success' => false,
            'status' => 404,
            'error' => 'Limit not found.',
        ]));

        // workspaceId <= 0 коротит countWorkspaceProducts() без обращения к БД —
        // тесту важен только путь через syncWorkspaceLimit(), не подсчёт товаров.
        $result = $service->syncWorkspaceProductsLimit(0);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['missing_limit']);
    }

    public function test_limit_check_failures_are_not_reported_as_exceeded(): void
    {
        $service = new LimitsSyncService($this->sellicoApi([]));

        $payload = $service->limitResponsePayload([
            'success' => false,
            'message' => 'Не удалось проверить лимит тарифа',
            'type' => 'autoplanning',
            'current_value' => 0,
        ]);

        $this->assertSame('limit_check_failed', $payload['error']);
    }

    public function test_external_limit_type_aliases_are_normalized(): void
    {
        $service = new LimitsSyncService($this->sellicoApi([
            'success' => true,
            'status' => 200,
            'limits' => [
                ['type' => 'auto_planning', 'limit' => 5, 'value' => 1],
            ],
        ]));

        $result = $service->getWorkspaceLimit(25, 'autoplanning');

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['limit']['limit']);
    }

    private function sellicoApi(array $response, ?array $syncResponse = null): SellicoApiService
    {
        return new class($response, $syncResponse) extends SellicoApiService {
            public function __construct(private array $response, private ?array $syncResponse = null)
            {
            }

            public function getWorkspaceLimitsExternal(int $workspaceId, ?string $type = null): array
            {
                return $this->response;
            }

            public function syncWorkspaceLimitExternal(int $workspaceId, array $payload): array
            {
                return $this->syncResponse ?? $this->response;
            }
        };
    }
}
