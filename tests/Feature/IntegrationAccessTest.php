<?php

namespace Tests\Feature;

use App\Services\SellicoApiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntegrationAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sellico.skip_permission_check', true);

        Schema::dropIfExists('unit_economics_cache');
        Schema::dropIfExists('unit_economics');
        Schema::dropIfExists('products');
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
            $table->boolean('is_premium')->default(false);
            $table->timestamp('premium_checked_at')->nullable();
            $table->decimal('manual_redemption_rate', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('marketplace')->nullable();
            $table->string('fulfillment_type')->nullable();
            $table->timestamps();
        });

        Schema::create('unit_economics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('marketplace')->nullable();
            $table->string('fulfillment_type')->nullable();
            $table->boolean('is_actual_scheme')->default(false);
            $table->timestamps();
        });

        Schema::create('unit_economics_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('product_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('product_name')->nullable();
            $table->string('marketplace')->nullable();
            $table->string('fulfillment_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('net_profit', 12, 2)->default(0);
            $table->decimal('margin_percent', 12, 2)->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->timestamps();
        });

        $sellicoApi = new class extends SellicoApiService
        {
            public string $accessToken = '';

            public function setAccessToken(string $token): void
            {
                $this->accessToken = $token;
            }

            public function getIntegrationById(int $integrationId): array
            {
                if ($integrationId !== 55) {
                    return [
                        'success' => false,
                        'error' => 'Интеграция не найдена',
                    ];
                }

                return [
                    'success' => true,
                    'integration' => [
                        'id' => 55,
                        'work_space_id' => 101,
                        'name' => 'Ozon 55',
                        'type' => 'ozon',
                        'is_active' => true,
                        'is_premium' => true,
                        'premium_checked_at' => '2026-03-21 10:00:00',
                    ],
                    'credentials' => [
                        'client_id' => 'client-55',
                        'api_key' => 'api-55',
                    ],
                ];
            }
        };

        $this->app->instance(SellicoApiService::class, $sellicoApi);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('unit_economics_cache');
        Schema::dropIfExists('unit_economics');
        Schema::dropIfExists('products');
        Schema::dropIfExists('integrations');

        parent::tearDown();
    }

    public function test_premium_status_syncs_missing_local_integration_from_sellico(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->withHeader('X-Sellico-Workspace', '101')
            ->getJson('/api/integrations/55/premium-status');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_premium', true)
            ->assertJsonPath('data.redemption_source', 'api');

        $this->assertDatabaseHas('integrations', [
            'id' => 55,
            'work_space_id' => 101,
            'marketplace' => 'ozon',
            'is_premium' => 1,
        ]);
    }

    public function test_unit_economics_index_accepts_remote_integration_before_local_sync(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->withHeader('X-Sellico-Workspace', '101')
            ->getJson('/api/unit-economics/ozon?integration_id=55&fulfillment_type=FBO&limit=50');

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 0)
            ->assertJsonPath('data.default_scheme', 'FBO')
            ->assertJsonPath('stats.total_count', 0);

        $this->assertDatabaseHas('integrations', [
            'id' => 55,
            'work_space_id' => 101,
            'marketplace' => 'ozon',
        ]);
    }
}
