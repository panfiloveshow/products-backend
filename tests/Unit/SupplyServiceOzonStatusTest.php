<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Models\Supply;
use App\Models\TimeslotCache;
use App\Exceptions\OzonPreconditionException;
use App\Services\Supply\SupplyService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupplyServiceOzonStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_existing_supply_order_uses_v3_details_instead_of_draft_status(): void
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => 919191,
            'work_space_id' => 91,
        ]);
        $supply = Supply::create([
            'integration_id' => $integration->id,
            'supply_type' => Supply::TYPE_FBO,
            'supply_method' => Supply::METHOD_DIRECT,
            'status' => Supply::STATUS_IN_TRANSIT,
            'ozon_draft_id' => '1234',
            'ozon_supply_id' => '5678',
        ]);

        Http::fake([
            'api-seller.ozon.ru/v3/supply-order/get' => Http::response([
                'orders' => [[
                    'id' => 5678,
                    'state' => 'ACCEPTED',
                    'state_name' => 'Принято',
                ]],
            ]),
        ]);

        app(SupplyService::class)->syncStatus($supply);

        $supply->refresh();
        $this->assertSame('ACCEPTED', $supply->ozon_status);
        $this->assertSame('Принято', $supply->ozon_status_description);
        $this->assertSame(Supply::STATUS_ACCEPTED_FULL, $supply->status);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-seller.ozon.ru/v3/supply-order/get'
            && $request['order_ids'] === [5678]);
    }

    public function test_expired_or_foreign_timeslot_is_rejected_before_money_path(): void
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => 919192,
            'work_space_id' => 91,
        ]);
        $supply = Supply::create([
            'integration_id' => $integration->id,
            'supply_type' => Supply::TYPE_FBO,
            'supply_method' => Supply::METHOD_DIRECT,
            'status' => Supply::STATUS_DRAFT_OZON,
            'ozon_draft_id' => '1234',
            'warehouse_id' => '701',
            'cluster_id' => '101',
        ]);
        TimeslotCache::create([
            'integration_id' => $integration->id,
            'warehouse_id' => '701',
            'draft_id' => '1234',
            'timeslot_id' => 'expired-slot',
            'slot_date' => now()->addDay()->toDateString(),
            'time_from' => '10:00',
            'time_to' => '12:00',
            'datetime_from' => now()->addDay()->setTime(10, 0),
            'datetime_to' => now()->addDay()->setTime(12, 0),
            'is_available' => true,
            'fetched_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
        ]);
        Http::fake();

        $this->expectException(OzonPreconditionException::class);
        $this->expectExceptionMessage('Слот устарел');

        try {
            app(SupplyService::class)->bookTimeslot($supply, 'expired-slot');
        } finally {
            Http::assertNothingSent();
        }
    }
}
