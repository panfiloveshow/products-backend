<?php

namespace Tests\Feature;

use App\Services\SellicoApiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntegrationAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sellico.skip_permission_check', true);

        Schema::dropIfExists('unit_economics_cache');
        Schema::dropIfExists('unit_economics_settings');
        Schema::dropIfExists('inventory_warehouses');
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
            $table->decimal('total_costs', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('unit_economics_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku');
            $table->timestamps();
        });

        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('marketplace')->nullable();
            $table->string('sku')->nullable();
            $table->string('fulfillment_type')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('average_daily_sales', 12, 4)->default(0);
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();
        });

        $sellicoApi = new class extends SellicoApiService
        {
            public string $accessToken = '';

            public function setAccessToken(string $token): void
            {
                $this->accessToken = $token;
            }

            public function getIntegrationById(int $integrationId, ?int $workspaceId = null): array
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
        Schema::dropIfExists('unit_economics_settings');
        Schema::dropIfExists('inventory_warehouses');
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

    public function test_unit_economics_index_is_read_only_and_does_not_sync_missing_remote_integration(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->withHeader('X-Sellico-Workspace', '101')
            ->getJson('/api/unit-economics/ozon?integration_id=55&fulfillment_type=FBO&limit=50');

        $response->assertNotFound()
            ->assertJsonPath('message', 'Интеграция не найдена в products-backend cache. Запустите sync интеграции.');

        $this->assertDatabaseMissing('integrations', [
            'id' => 55,
        ]);
    }

    public function test_unit_economics_index_paginates_items_but_stats_use_filtered_rows(): void
    {
        DB::table('integrations')->insert([
            'id' => 56,
            'work_space_id' => 101,
            'name' => 'Ozon 56',
            'marketplace' => 'ozon',
            'credentials' => '{}',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [
            ['sku' => 'A-001', 'fulfillment_type' => 'FBO', 'revenue' => 100, 'total_costs' => 90, 'net_profit' => 10, 'margin_percent' => 10],
            ['sku' => 'A-002', 'fulfillment_type' => 'FBO', 'revenue' => 200, 'total_costs' => 180, 'net_profit' => 20, 'margin_percent' => 20],
            ['sku' => 'A-003', 'fulfillment_type' => 'FBO', 'revenue' => 300, 'total_costs' => 270, 'net_profit' => 30, 'margin_percent' => 30],
            ['sku' => 'A-004', 'fulfillment_type' => 'FBO', 'revenue' => 400, 'total_costs' => 420, 'net_profit' => -20, 'margin_percent' => -5],
            ['sku' => 'A-005', 'fulfillment_type' => 'FBO', 'revenue' => 500, 'total_costs' => 550, 'net_profit' => -50, 'margin_percent' => -10],
            ['sku' => 'B-001', 'fulfillment_type' => 'FBS', 'revenue' => 100, 'total_costs' => 80, 'net_profit' => 20, 'margin_percent' => 20],
            ['sku' => 'B-002', 'fulfillment_type' => 'FBS', 'revenue' => 100, 'total_costs' => 80, 'net_profit' => 20, 'margin_percent' => 20],
        ];

        foreach ($rows as $row) {
            DB::table('unit_economics_cache')->insert(array_merge([
                'integration_id' => 56,
                'product_name' => $row['sku'],
                'marketplace' => 'ozon',
                'price' => $row['revenue'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $row));
        }

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->withHeader('X-Sellico-Workspace', '101')
            ->getJson('/api/unit-economics/ozon?integration_id=56&fulfillment_type=FBO&profitable=1&limit=2&page=2');

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.sku', 'A-003')
            ->assertJsonPath('data.scheme_counts.FBO', 5)
            ->assertJsonPath('data.scheme_counts.FBS', 2)
            ->assertJsonPath('data.scheme_counts.RFBS', 0)
            ->assertJsonPath('data.scheme_counts.EXPRESS', 0)
            ->assertJsonPath('data.stats.total_count', 3)
            ->assertJsonPath('stats.total_count', 3)
            ->assertJsonPath('data.stats.total_revenue', 600)
            ->assertJsonPath('data.stats.total_costs', 540)
            ->assertJsonPath('data.stats.total_profit', 60)
            ->assertJsonPath('data.stats.avg_margin', 20);
    }
}
