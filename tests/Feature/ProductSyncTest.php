<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\ProductService;
use App\Services\SellicoApiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sellico.skip_permission_check', true);

        Schema::dropIfExists('integrations');
        Schema::create('integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('work_space_id')->nullable();
            $table->string('name')->nullable();
            $table->string('marketplace')->nullable();
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_sync_enabled')->default(true);
            $table->unsignedInteger('sync_interval_hours')->default(6);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('integrations');

        parent::tearDown();
    }

    public function test_products_sync_without_integration_id_starts_sync_in_other_workspaces(): void
    {
        $productService = new class extends ProductService
        {
            public array $calls = [];

            public function startSync(
                string $marketplace,
                array $credentials = [],
                ?int $integrationId = null,
                string $syncType = 'products'
            ): SyncLog {
                $this->calls[] = [
                    'marketplace' => $marketplace,
                    'credentials' => $credentials,
                    'integration_id' => $integrationId,
                    'sync_type' => $syncType,
                ];

                $syncLog = new SyncLog([
                    'marketplace' => $marketplace,
                    'integration_id' => $integrationId,
                    'sync_type' => $syncType,
                    'status' => SyncLog::STATUS_PENDING,
                ]);
                $syncLog->id = "sync-{$integrationId}";

                return $syncLog;
            }
        };

        $sellicoApi = new class extends SellicoApiService
        {
            public string $accessToken = '';

            public function setAccessToken(string $token): void
            {
                $this->accessToken = $token;
            }

            public function getWorkspaces(): array
            {
                return [
                    'success' => true,
                    'workspaces' => [
                        ['id' => 101, 'name' => 'Workspace 101'],
                        ['id' => 202, 'name' => 'Workspace 202'],
                    ],
                ];
            }

            public function getMarketplaceCredentials(int $workspaceId): array
            {
                return match ($workspaceId) {
                    101 => [
                        'success' => true,
                        'all' => [
                            [
                                'id' => 11,
                                'work_space_id' => 101,
                                'name' => 'Ozon A',
                                'type' => 'ozon',
                                'client_id' => 'client-a',
                                'api_key' => 'api-a',
                            ],
                        ],
                    ],
                    202 => [
                        'success' => true,
                        'all' => [
                            [
                                'id' => 22,
                                'work_space_id' => 202,
                                'name' => 'Ozon B',
                                'type' => 'ozon',
                                'client_id' => 'client-b',
                                'api_key' => 'api-b',
                            ],
                            [
                                'id' => 33,
                                'work_space_id' => 202,
                                'name' => 'WB C',
                                'type' => 'wildberries',
                                'api_key' => 'wb-key',
                            ],
                        ],
                    ],
                    default => [
                        'success' => true,
                        'all' => [],
                    ],
                };
            }
        };

        $this->app->instance(ProductService::class, $productService);
        $this->app->instance(SellicoApiService::class, $sellicoApi);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/products/sync/ozon', []);

        $response->assertOk()
            ->assertJsonPath('data.marketplace', 'ozon')
            ->assertJsonPath('data.started', 2);

        $this->assertCount(2, $productService->calls);
        $this->assertSame([11, 22], array_column($productService->calls, 'integration_id'));
        $this->assertSame('test-token', $productService->calls[0]['credentials']['_sellico_token']);
        $this->assertDatabaseHas('integrations', [
            'id' => 11,
            'work_space_id' => 101,
            'marketplace' => 'ozon',
        ]);
        $this->assertDatabaseHas('integrations', [
            'id' => 22,
            'work_space_id' => 202,
            'marketplace' => 'ozon',
        ]);
        $this->assertFalse(Integration::where('id', 33)->exists());
    }
}
