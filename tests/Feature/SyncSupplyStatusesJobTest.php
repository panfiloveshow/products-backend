<?php

namespace Tests\Feature;

use App\Jobs\SyncSupplyStatusesJob;
use App\Models\Integration;
use App\Models\Supply;
use App\Services\Supply\SupplyService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Фоновый трекинг поставок (расписание каждые 15 минут).
 */
class SyncSupplyStatusesJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_active_supplies_with_ozon_ids_are_synced(): void
    {
        $integration = $this->integration();

        $tracked = $this->supply($integration, ['ozon_order_id' => '900001', 'status' => Supply::STATUS_SHIPPED]);
        $draftOnly = $this->supply($integration, ['ozon_draft_id' => '900002', 'status' => Supply::STATUS_DRAFT_OZON]);
        $this->supply($integration, ['status' => Supply::STATUS_DRAFT]);            // ещё не в Ozon
        $this->supply($integration, ['ozon_order_id' => '900003', 'status' => Supply::STATUS_CLOSED]);
        $this->supply($integration, ['ozon_order_id' => '900004', 'status' => Supply::STATUS_CANCELLED]);

        $synced = [];
        $service = \Mockery::mock(SupplyService::class);
        $service->shouldReceive('syncStatus')
            ->andReturnUsing(function (Supply $supply) use (&$synced): void {
                $synced[] = $supply->id;
            });

        (new SyncSupplyStatusesJob())->handle($service);

        sort($synced);
        $expected = [$tracked->id, $draftOnly->id];
        sort($expected);
        $this->assertSame($expected, $synced);
    }

    public function test_one_broken_supply_does_not_stop_the_rest(): void
    {
        $integration = $this->integration();
        $failing = $this->supply($integration, ['ozon_order_id' => '900101', 'status' => Supply::STATUS_SHIPPED]);
        $healthy = $this->supply($integration, ['ozon_order_id' => '900102', 'status' => Supply::STATUS_SHIPPED]);

        $synced = [];
        $service = \Mockery::mock(SupplyService::class);
        $service->shouldReceive('syncStatus')
            ->andReturnUsing(function (Supply $supply) use (&$synced, $failing): void {
                if ($supply->id === $failing->id) {
                    throw new \RuntimeException('Ozon вернул 500');
                }
                $synced[] = $supply->id;
            });

        (new SyncSupplyStatusesJob())->handle($service);

        $this->assertSame([$healthy->id], $synced);
    }

    public function test_integration_scope_does_not_leak_across_accounts(): void
    {
        $mine = $this->integration();
        $foreign = $this->integration();
        $ours = $this->supply($mine, ['ozon_order_id' => '900201', 'status' => Supply::STATUS_SHIPPED]);
        $this->supply($foreign, ['ozon_order_id' => '900202', 'status' => Supply::STATUS_SHIPPED]);

        $synced = [];
        $service = \Mockery::mock(SupplyService::class);
        $service->shouldReceive('syncStatus')
            ->andReturnUsing(function (Supply $supply) use (&$synced): void {
                $synced[] = $supply->id;
            });

        (new SyncSupplyStatusesJob($mine->id))->handle($service);

        $this->assertSame([$ours->id], $synced);
    }

    private function integration(): Integration
    {
        return Integration::factory()->ozon()->create([
            'id' => random_int(100000, 999999),
            'work_space_id' => 95,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function supply(Integration $integration, array $overrides): Supply
    {
        return Supply::create(array_merge([
            'integration_id' => $integration->id,
            'supply_type' => Supply::TYPE_FBO,
            'supply_method' => Supply::METHOD_DIRECT,
            'cluster_id' => '101',
            'cluster_name' => 'Москва',
            'status' => Supply::STATUS_DRAFT,
        ], $overrides));
    }
}
