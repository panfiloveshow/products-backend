<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Services\UnitEconomics\UnitEconomicsFreshness;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UnitEconomicsFreshnessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_previous_published_calculations_are_available_while_a_new_price_request_is_rate_limited(): void
    {
        config(['unit_economics_sync.enabled' => true]);
        $integration = Integration::factory()->wildberries()->create(['id' => 62042]);
        $product = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'A',
            'wb_data' => ['price_version' => 1, 'price_observed_at' => now()->subMinutes(40)->utc()->toIso8601String()]]);
        $cache = UnitEconomicsCache::create(['integration_id' => $integration->id, 'sku' => 'A', 'product_id' => $product->id,
            'product_name' => 'A', 'marketplace' => 'wildberries', 'fulfillment_type' => 'FBO', 'price' => 800, 'source_version' => 1]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $coordinator->withCurrentVersion($run->id, 1, function ($state, $current): void {
            $state->update(['observed_version' => 1, 'published_version' => 1]);
            $current->update(['published_version' => 1]);
        });
        $run = $coordinator->request($integration, 'full');
        $coordinator->observe($run->id, 2, fn () => ['total' => 1, 'matched' => 0, 'missing' => 0, 'stale' => 0], false, [
            'http_status' => 429, 'error_code' => 'rate_limited', 'next_retry_at' => now()->addMinutes(15)->utc()->toIso8601String(),
        ]);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertSame('pending', $freshness['stages']['unit_economics']['status'], 'The new workflow must still await its own observation.');
        $this->assertSame(1, $freshness['stages']['unit_economics']['published']['version']);
        $this->assertSame(1, $freshness['stages']['unit_economics']['published']['total']);
        $this->assertSame('доступен предыдущий расчёт', $freshness['stages']['unit_economics']['published']['label']);
        $this->assertSame('Расчёты доступны; обновление цен ожидает API WB', $freshness['overall']['label']);
        $this->assertFalse($freshness['overall']['is_fresh']);
        $this->assertFalse($freshness['source']['price_is_fresh']);

        $cache->update(['marketplace_data' => ['calculation_error_code' => 'calculation_failed']]);
        $failed = app(UnitEconomicsFreshness::class)->forIntegration($integration);
        $this->assertNull($failed['stages']['unit_economics']['published']);
    }

    public function test_recent_cache_with_unknown_price_observation_is_never_reported_fresh(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62016]);
        $product = Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => 'A']);
        UnitEconomicsCache::create(['integration_id' => $integration->id, 'sku' => 'A', 'product_id' => $product->id,
            'product_name' => 'A', 'marketplace' => 'wildberries', 'fulfillment_type' => 'FBO', 'price' => 1000]);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertFalse($freshness['overall']['is_fresh']);
        $this->assertSame('unknown', $freshness['status']);
        $this->assertSame('unknown', $freshness['stages']['unit_economics']['status']);
        $this->assertSame(0, $freshness['source']['observed']);
        $this->assertNotSame('green', $freshness['overall']['color']);
    }

    public function test_progress_counts_all_observed_prices_and_waits_for_enrichment_publication(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62043]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $observation = $coordinator->observe($run->id, 1, fn () => [
            'total' => 250, 'matched' => 250, 'missing' => 0, 'stale' => 0,
        ], true)['value'];
        $coordinator->projectionBatch($run->id, 1, $observation['observation_id'], null, 'first-batch', [
            'total' => 100, 'success' => 100, 'errors' => 0,
        ], false);
        $coordinator->updateStage($run->id, 'projection', 'running', version: 1);
        $coordinator->updateStage($run->id, 'enrichment', 'running', [
            'source_status' => 'completed', 'total' => 250, 'succeeded' => 250,
        ]);

        $service = app(UnitEconomicsFreshness::class);
        $freshness = $service->forIntegration($integration);
        $this->assertSame('running', $freshness['stages']['unit_economics']['status']);
        $this->assertEquals(40, $freshness['stages']['unit_economics']['progress_percent']);
        $this->assertSame('running', $freshness['stages']['enrichment']['status']);
        $this->assertNull($freshness['stages']['enrichment']['progress_percent']);

        $coordinator->projectionBatch($run->id, 1, $observation['observation_id'], 'first-batch', 'last-batch', [
            'total' => 150, 'success' => 150, 'errors' => 0,
        ], true);
        $coordinator->enrichmentPublished($run->id, ['total' => 250, 'success' => 250, 'errors' => 0]);

        $freshness = $service->forIntegration($integration);
        $this->assertSame('completed', $freshness['stages']['unit_economics']['status']);
        $this->assertEquals(100, $freshness['stages']['unit_economics']['progress_percent']);
        $this->assertSame('completed', $freshness['stages']['enrichment']['status']);
        $this->assertEquals(100, $freshness['stages']['enrichment']['progress_percent']);
    }

    public function test_rejected_wb_key_does_not_leave_unstarted_stages_looking_queued(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62037]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $coordinator->updateStage($run->id, 'prices', 'failed', ['http_status' => 401, 'error_code' => 'credentials_rejected'], 1);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertSame('failed', $freshness['status']);
        $this->assertFalse($freshness['overall']['is_updating']);
        $this->assertSame('red', $freshness['overall']['color']);
        $this->assertSame('WB отклонил API-ключ. Обновите его в интеграции', $freshness['overall']['label']);
        $this->assertSame('failed', $freshness['stages']['products']['status']);
        $this->assertSame('credentials_rejected', $freshness['stages']['finance']['error_code']);
        $this->assertSame('queued', $run->fresh()->status, 'Rendering must not mutate the persisted workflow.');
    }

    public function test_prices_permission_denial_does_not_hide_independent_running_work(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62038]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $coordinator->updateStage($run->id, 'catalog', 'running');
        $coordinator->updateStage($run->id, 'prices', 'failed', ['http_status' => 403, 'error_code' => 'credentials_rejected'], 1);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertSame('running', $freshness['status']);
        $this->assertTrue($freshness['overall']['is_updating']);
        $this->assertSame('running', $freshness['stages']['products']['status']);
        $this->assertSame('red', $freshness['overall']['color']);
        $this->assertSame('WB запретил получение цен. Проверьте права API-ключа', $freshness['overall']['label']);
    }

    public function test_rate_limit_reports_retry_time_and_persisted_status_without_queue_payload_inspection(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 62017]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration);
        $next = now()->addMinutes(15)->utc()->toIso8601String();
        $coordinator->updateStage($run->id, 'prices', 'waiting_external', ['next_retry_at' => $next, 'error_code' => 'rate_limited'], 1);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertSame('waiting_external', $freshness['status']);
        $this->assertSame($next, $freshness['next_retry_at']);
        $this->assertSame('rate_limited', $freshness['stages']['prices']['error_code']);
        $this->assertTrue($freshness['overall']['is_updating']);
        $this->assertFalse($freshness['overall']['is_fresh']);
    }

    public function test_queued_work_is_reported_as_waiting_for_a_worker(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 62018]);
        app(UnitEconomicsSyncCoordinator::class)->request($integration);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertSame('queued', $freshness['status']);
        $this->assertSame('Синхронизация в очереди; ожидаем обработчик', $freshness['overall']['label']);
        $this->assertTrue($freshness['overall']['is_updating']);
    }

    public function test_failed_catalog_is_visible_while_independent_price_work_waits(): void
    {
        $integration = Integration::factory()->wildberries()->create(['id' => 62019]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $coordinator->updateStage($run->id, 'catalog', 'failed', ['error_code' => 'marketplace_sync_failed']);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertSame('queued', $freshness['status']);
        $this->assertSame('failed', $freshness['stages']['products']['status']);
        $this->assertSame('Синхронизация ожидает обработки; есть ошибки этапов', $freshness['overall']['label']);
        $this->assertTrue($coordinator->withCurrentVersion($run->id, 1, fn () => true)['accepted']);
    }

    public function test_expired_work_is_not_presented_as_an_active_sync(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 62020]);
        $run = app(UnitEconomicsSyncCoordinator::class)->request($integration);
        $run->update(['deadline_at' => now()->subMinute()]);

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertSame('failed', $freshness['status']);
        $this->assertFalse($freshness['overall']['is_updating']);
        $this->assertSame('red', $freshness['overall']['color']);
        $this->assertSame('failed', $freshness['stages']['prices']['status']);
        $this->assertSame('deadline_exceeded', $freshness['stages']['prices']['error_code']);
        $this->assertSame('queued', $run->fresh()->status, 'The read model must not change the workflow while rendering.');
    }

    public function test_published_prices_remain_visible_while_enrichment_waits(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 62021]);
        $product = Product::factory()->ozon()->create(['integration_id' => $integration->id, 'sku' => 'A',
            'ozon_data' => ['price_observed_at' => now()->utc()->toIso8601String()]]);
        UnitEconomicsCache::create(['integration_id' => $integration->id, 'sku' => 'A', 'product_id' => $product->id,
            'product_name' => 'A', 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO', 'price' => 1000, 'source_version' => 1]);
        $coordinator = app(UnitEconomicsSyncCoordinator::class);
        $run = $coordinator->request($integration, 'full');
        $coordinator->updateStage($run->id, 'prices', 'completed', version: 1);
        $coordinator->updateStage($run->id, 'projection', 'completed', version: 1);
        $coordinator->withCurrentVersion($run->id, 1, function ($state, $current): void {
            $state->update(['published_version' => 1]);
            $current->update(['published_version' => 1]);
        });

        $freshness = app(UnitEconomicsFreshness::class)->forIntegration($integration);

        $this->assertTrue($freshness['source']['price_is_fresh']);
        $this->assertSame('Цены обновлены; остальные этапы в очереди', $freshness['overall']['label']);
        $this->assertSame('pending', $freshness['stages']['enrichment']['status']);

        $coordinator->updateStage($run->id, 'inventory', 'waiting_external', [
            'error_code' => 'rate_limited', 'next_retry_at' => now()->addMinutes(15)->utc()->toIso8601String(),
        ]);
        $waiting = app(UnitEconomicsFreshness::class)->forIntegration($integration);
        $this->assertSame('Цены обновлены; остальные данные ожидают API Ozon', $waiting['overall']['label']);
        $this->assertTrue($waiting['source']['price_is_fresh']);
        $this->assertSame('completed', $waiting['stages']['unit_economics']['status']);
    }
}
