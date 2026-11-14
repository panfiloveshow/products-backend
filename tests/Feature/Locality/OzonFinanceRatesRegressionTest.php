<?php

namespace Tests\Feature\Locality;

use App\Services\UnitEconomicsSynchronizer;
use App\Domains\Locality\Ingestion\FinanceTransactionSyncer;
use App\Models\Integration;
use App\Models\OzonFinanceTransaction;
use App\Services\Ozon\OzonActualRatesService;
use App\Services\UnitEconomicsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OzonFinanceRatesRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00'));
        Http::preventStrayRequests();
        foreach (['cache', 'skuLastMileCache', 'periodCache'] as $property) {
            if (property_exists(OzonActualRatesService::class, $property)) {
                (new \ReflectionProperty(OzonActualRatesService::class, $property))->setValue(null, []);
            }
        }
    }

    public function test_saved_calculation_refreshes_last_mile_total_and_per_unit_fields(): void
    {
        $integration = $this->integration();
        $data = ['integration_id' => $integration->id, 'sku' => 'saved-unit', 'marketplace' => 'ozon', 'price' => 1000, 'cost_price' => 100,
            'sales_count' => 7, 'fulfillment_type' => 'FBO', 'volume_liters' => 1, 'last_mile_cost' => 7.5];
        $row = app(UnitEconomicsService::class)->createOrUpdate($data);
        $this->assertEqualsWithDelta(52.5, (float) $row->last_mile_cost, 0.001);
        $this->assertEqualsWithDelta(7.5, (float) $row->last_mile_per_unit, 0.001);

        $data['last_mile_cost'] = 0;
        $updated = app(UnitEconomicsService::class)->createOrUpdate($data);
        $this->assertSame($row->id, $updated->id);
        $this->assertEquals(0, (float) $updated->last_mile_cost);
        $this->assertEquals(0, (float) $updated->last_mile_per_unit);
    }

    public function test_saved_calculation_rolls_over_to_a_new_period_without_a_duplicate_key(): void
    {
        $integration = $this->integration();
        $data = ['integration_id' => $integration->id, 'sku' => 'period-unit', 'marketplace' => 'ozon', 'price' => 1000, 'cost_price' => 100,
            'sales_count' => 2, 'fulfillment_type' => 'FBO', 'volume_liters' => 1, 'last_mile_cost' => 7.5];
        $row = app(UnitEconomicsService::class)->createOrUpdate($data);
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00'));
        $updated = app(UnitEconomicsService::class)->createOrUpdate($data);
        $this->assertSame($row->id, $updated->id);
        $this->assertSame('2026-11-01', $updated->period_start->toDateString());
        $this->assertDatabaseCount('unit_economics', 1);
    }

    public function test_daily_rows_are_summed_before_persistence_and_resync_does_not_duplicate(): void
    {
        $this->fakeDay([
            $this->reportRow(1000, 10, 100),
            $this->reportRow(900, 3, 30),
            $this->reportRow(900, 0, 0),
        ]);
        $syncer = new FinanceTransactionSyncer;
        $integration = $this->integration();
        $date = Carbon::parse('2026-10-26');

        $syncer->syncForIntegration($integration, $date, $date);
        $sale = OzonFinanceTransaction::where('operation_type', FinanceTransactionSyncer::SALE_OPERATION_TYPE)->sole();
        $this->assertSame(13, $sale->raw['quantity']);
        $this->assertSame('12700.00', $sale->accruals_for_sale);
        $this->assertSame('-130.00', $sale->sale_commission);
        $this->assertSame(25.0, (new OzonActualRatesService)->lastMilePerSku(901)['100']);

        $syncer->syncForIntegration($integration, $date, $date);
        $this->assertSame(1, OzonFinanceTransaction::where('operation_type', FinanceTransactionSyncer::SALE_OPERATION_TYPE)->count());
        $this->assertSame(13, $sale->fresh()->raw['quantity']);
        $this->assertSame(25.0, (new OzonActualRatesService)->lastMilePerSku(901)['100']);
    }

    public function test_zero_sales_and_returns_are_not_invented_and_stale_phantom_rows_are_removed(): void
    {
        $this->sale('2026-10-26', 1, 1000, 'realization_by_day', 'rlz:2026-10-26:OperationAgentDeliveredToCustomer:100');
        $row = $this->reportRow(1000, 0, 0);
        $row['return_commission'] = ['quantity' => 2, 'standard_fee' => 20];
        $this->fakeDay([$row], 0);

        $this->fee('2026-10-26', -25);
        $result = (new FinanceTransactionSyncer)->restoreRealizationDay($this->integration(), Carbon::parse('2026-10-26'));

        $this->assertSame('restored', $result['status']);
        $this->assertSame(0, $result['after_units']);
        $this->assertSame('-25.00', OzonFinanceTransaction::where('operation_type', 'LastMileCourier')->sole()->amount);
        $this->assertSame(0, OzonFinanceTransaction::where('operation_type', FinanceTransactionSyncer::SALE_OPERATION_TYPE)->count());
        $return = OzonFinanceTransaction::where('operation_type', FinanceTransactionSyncer::RETURN_OPERATION_TYPE)->sole();
        $this->assertSame(2, $return->raw['quantity']);
        $this->assertSame('-2000.00', $return->amount);
        $this->assertSame('20.00', $return->sale_commission);
    }

    public function test_empty_or_failed_daily_report_preserves_existing_sales(): void
    {
        $this->sale('2026-10-26', 10, 10000, 'realization_by_day');
        $this->fakeDay([], 0);
        $syncer = new FinanceTransactionSyncer;
        $date = Carbon::parse('2026-10-26');
        $syncer->syncForIntegration($this->integration(), $date, $date);
        $this->assertSame(10, OzonFinanceTransaction::sole()->raw['quantity']);

        Http::fake(['*' => Http::response(['message' => 'unavailable'], 500)]);
        $syncer->syncForIntegration($this->integration(), $date, $date);
        $this->assertSame(10, OzonFinanceTransaction::sole()->raw['quantity']);
    }

    public function test_malformed_nonempty_report_cannot_delete_saved_sales(): void
    {
        $this->sale('2026-10-26', 10, 10000, 'realization_by_day');
        $this->fakeDay([null], 0);
        $date = Carbon::parse('2026-10-26');

        (new FinanceTransactionSyncer)->syncForIntegration($this->integration(), $date, $date);

        $this->assertSame(10, OzonFinanceTransaction::sole()->raw['quantity']);
    }

    public function test_monthly_rates_use_only_the_same_full_month_even_after_it_leaves_the_daily_window(): void
    {
        $this->sale('2026-09-30', 30, 30000, 'realization_v2_month');
        $this->fee('2026-09-01', -250);
        $this->fee('2026-09-30', -500);
        $this->fee('2026-10-15', -7500);
        $this->fee('2026-09-15', -300, 'Acquiring');
        $service = new OzonActualRatesService;

        foreach (['2026-10-28', '2026-10-30'] as $date) {
            $this->travelTo(Carbon::parse($date));
            $this->assertSame(25.0, $service->lastMilePerSku(901)['100']);
            $rates = $service->forIntegration(901);
            $this->assertSame(30, $rates['units']);
            $this->assertSame(25.0, $rates['last_mile_avg']);
            $this->assertSame(1.0, $rates['acquiring_percent']);
            $this->assertSame('2026-09-01', $rates['period_from']);
            $this->assertSame('2026-09-30', $rates['period_to']);
            $this->assertSame('monthly', $rates['source']);
        }
    }

    public function test_daily_rates_exclude_monthly_sales_for_the_same_sku(): void
    {
        $this->sale('2026-09-30', 300, 300000, 'realization_v2_month');
        $this->sale('2026-10-26', 20, 20000, 'realization_by_day');
        $this->fee('2026-10-26', -500);
        $this->fee('2026-10-26', -200, 'Acquiring');

        $service = new OzonActualRatesService;
        $rates = $service->forIntegration(901);
        $this->assertSame(20, $rates['units']);
        $this->assertSame(25.0, $rates['last_mile_avg']);
        $this->assertSame(1.0, $rates['acquiring_percent']);
        $this->assertSame(25.0, $service->lastMilePerSku(901)['100']);
    }

    public function test_premium_blocked_store_uses_monthly_sales_without_overlapping_daily_rows(): void
    {
        $this->storedIntegration(true);
        $this->sale('2026-09-30', 30, 30000, 'realization_v2_month');
        $this->sale('2026-09-30', 5, 5000, 'realization_by_day');
        $this->fee('2026-09-01', -750);
        $this->fee('2026-10-26', -7500);
        $service = new OzonActualRatesService;

        $this->assertSame(30, $service->forIntegration(901)['units']);
        $this->assertSame('monthly', $service->forIntegration(901)['source']);
        $this->assertSame(25.0, $service->lastMilePerSku(901)['100']);
    }

    public function test_seven_day_window_and_legacy_embedded_fees_remain_supported(): void
    {
        $this->sale('2026-10-20', 3, 3000, 'realization_by_day');
        $this->fee('2026-10-20', -300);
        OzonFinanceTransaction::create(['integration_id' => 901, 'operation_id' => 'legacy:1',
            'operation_type' => FinanceTransactionSyncer::SALE_OPERATION_TYPE, 'operation_date' => '2026-10-21',
            'sku' => '100', 'amount' => 1000, 'accruals_for_sale' => 1000,
            'raw' => ['quantity' => 3, 'services' => [['name' => 'MarketplaceRedistributionLastMileCourier', 'price' => -30]]]]);
        $service = new OzonActualRatesService;

        $this->assertSame(10.0, $service->lastMilePerSku(901, 7)['100']);
        $rates = $service->forIntegration(901, 7);
        $this->assertSame(3, $rates['units']);
        $this->assertSame('2026-10-21', $rates['period_from']);
        $this->assertSame(7, $rates['days']);
    }

    public function test_blocked_daily_source_without_a_monthly_report_does_not_supply_partial_rates(): void
    {
        $this->storedIntegration(true);
        $this->sale('2026-09-30', 20, 20000, 'realization_by_day');
        $this->fee('2026-10-26', -7500);
        $service = new OzonActualRatesService;

        $this->assertSame([], $service->lastMilePerSku(901));
        $this->assertSame(0, $service->forIntegration(901)['units']);
        $this->assertNull($service->forIntegration(901)['last_mile_avg']);
    }

    public function test_invalid_key_uses_closed_month_instead_of_partial_daily_history(): void
    {
        $this->storedIntegration();
        DB::table('integrations')->where('id', 901)->update(['credential_health' => 'invalid']);
        $this->sale('2026-09-30', 30, 30000, 'realization_v2_month');
        $this->sale('2026-10-01', 5, 5000, 'realization_by_day');
        $this->fee('2026-09-01', -750);
        $this->fee('2026-10-26', -7500);

        $this->assertSame(25.0, (new OzonActualRatesService)->lastMilePerSku(901)['100']);
    }

    public function test_expired_api_key_is_not_misreported_as_a_premium_subscription_requirement(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Api-key has expired, generate a new one'], 403)]);
        $integration = $this->integration();
        try {
            (new FinanceTransactionSyncer)->restoreRealizationDay($integration, Carbon::parse('2026-10-26'));
            $this->fail('Expired credentials must fail restoration.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Ozon API key expired for integration 901', $e->getMessage());
        }
        $this->assertFalse($integration->settings['ozon_premium_plus_required']);
    }

    public function test_daily_window_contains_exactly_28_completed_days(): void
    {
        $this->sale('2026-09-29', 100, 100000, 'realization_by_day');
        $this->fee('2026-09-29', -10000);
        $this->sale('2026-09-30', 10, 10000, 'realization_by_day');
        $this->fee('2026-09-30', -250);
        $this->sale('2026-10-27', 10, 10000, 'realization_by_day');
        $this->fee('2026-10-27', -250);
        $this->sale('2026-10-28', 100, 100000, 'realization_by_day');
        $this->fee('2026-10-28', -10000);

        $service = new OzonActualRatesService;
        $rates = $service->forIntegration(901);
        $this->assertSame(20, $rates['units']);
        $this->assertSame('2026-09-30', $rates['period_from']);
        $this->assertSame('2026-10-27', $rates['period_to']);
        $this->assertSame(28, $rates['days']);
        $this->assertSame(25.0, $service->lastMilePerSku(901)['100']);
    }

    public function test_successful_resync_invalidates_rates_in_a_long_lived_worker(): void
    {
        $this->sale('2026-10-26', 3, 2700, 'realization_by_day', 'rlz:2026-10-26:OperationAgentDeliveredToCustomer:100');
        $this->fee('2026-10-26', -325);
        $service = new OzonActualRatesService;
        $this->assertSame(108.33, $service->lastMilePerSku(901)['100']);
        $this->fakeDay([$this->reportRow(1000, 10, 100), $this->reportRow(900, 3, 30)], 0);

        (new FinanceTransactionSyncer)->syncForIntegration($this->integration(), Carbon::parse('2026-10-26'), Carbon::parse('2026-10-26'));

        $this->assertSame(25.0, $service->lastMilePerSku(901)['100']);
    }

    public function test_recovery_command_records_verified_days_and_can_resume_without_reloading_them(): void
    {
        $this->storedIntegration();
        $this->sale('2026-10-26', 3, 2700, 'realization_by_day', 'rlz:2026-10-26:OperationAgentDeliveredToCustomer:100');
        $this->fee('2026-10-26', -325);
        $this->fakeDay([$this->reportRow(1000, 10, 100), $this->reportRow(900, 3, 30)], 0);
        $report = tempnam(sys_get_temp_dir(), 'ozon-recovery-');
        try {
            $options = ['--integration' => [901], '--from' => '2026-10-26', '--to' => '2026-10-26', '--report' => $report];
            $this->artisan('ozon:restore-realization', $options)->assertSuccessful();
            $row = json_decode(trim(file_get_contents($report)), true);
            $this->assertSame('restored', $row['status']);
            $this->assertSame(3, $row['before_units']);
            $this->assertSame(13, $row['after_units']);
            $this->artisan('ozon:restore-realization', $options + ['--resume' => true])->assertSuccessful();
            Http::assertSentCount(1);
            $this->assertSame('-325.00', OzonFinanceTransaction::where('operation_type', 'LastMileCourier')->sole()->amount);
        } finally {
            unlink($report);
        }
    }

    public function test_recovery_dry_run_does_not_call_ozon_or_modify_sales(): void
    {
        $this->storedIntegration();
        $this->sale('2026-10-26', 3, 2700, 'realization_by_day');
        Http::fake();
        $this->artisan('ozon:restore-realization', ['--integration' => [901], '--dry-run' => true])->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame(3, OzonFinanceTransaction::sole()->raw['quantity']);
        $this->artisan('ozon:restore-realization', ['--from' => '2026-10-28'])->assertFailed();
    }

    public function test_sync_calculator_passes_actual_last_mile_into_the_shared_calculator(): void
    {
        $service = app(UnitEconomicsService::class);
        $input = ['sku' => 'demo', 'price' => 1000, 'cost_price' => 300, 'sales_count' => 2,
            'fulfillment_type' => 'FBO', 'volume_liters' => 1, 'commission_percent' => 15];
        $default = $service->calculate('ozon', $input);
        $actual = $service->calculate('ozon', $input + ['last_mile_cost' => 10.8]);
        $zero = $service->calculate('ozon', $input + ['last_mile_cost' => 0]);

        $this->assertSame(50.0, $default['last_mile_cost']);
        $this->assertSame(21.6, $actual['last_mile_cost']);
        $this->assertSame(0.0, $zero['last_mile_cost']);
        $this->assertEqualsWithDelta(28.4, $default['total_costs'] - $actual['total_costs'], 0.01);
        $command = app(UnitEconomicsSynchronizer::class);
        $details = (new \ReflectionMethod($command, 'extractDetailedData'))->invoke($command, $input, $actual, 'ozon');
        $this->assertSame(10.8, $details['last_mile_per_unit']);
        $this->assertSame(21.6, $details['last_mile_cost']);
    }

    public function test_recovery_respects_daily_retention_and_restores_older_closed_months(): void
    {
        $this->storedIntegration();
        $this->sale('2026-08-01', 3, 2700, 'realization_by_day');
        $syncer = \Mockery::mock(FinanceTransactionSyncer::class);
        $syncer->shouldReceive('restoreMonthlyRealization')->twice()->withArgs(fn ($integration, Carbon $month) => in_array($month->format('Y-m'), ['2026-08', '2026-09'], true))->andReturn([1, 0]);
        $syncer->shouldReceive('restoreRealizationDay')->times(31)->withArgs(fn ($integration, Carbon $day) => $day->betweenIncluded(Carbon::parse('2026-09-26'), Carbon::parse('2026-10-26')))->andReturn(['status' => 'restored', 'before_units' => 1, 'after_units' => 2]);
        $this->app->instance(FinanceTransactionSyncer::class, $syncer);

        $this->artisan('ozon:restore-realization', ['--integration' => [901], '--to' => '2026-10-26'])->assertSuccessful();
    }

    public function test_ozon_retention_boundary_preserves_sales_and_is_reported_separately_from_failures(): void
    {
        $this->sale('2026-09-26', 3, 2700, 'realization_by_day');
        Http::fake(['*' => Http::response(['message' => 'the requested date must be no earlier than 32 days before the current day and no later'], 400)]);
        $result = (new FinanceTransactionSyncer)->restoreRealizationDay($this->integration(), Carbon::parse('2026-09-26'));

        $this->assertSame('outside_retention', $result['status']);
        $this->assertSame(3, OzonFinanceTransaction::sole()->raw['quantity']);
    }

    private function storedIntegration(bool $premiumRequired = false): void
    {
        DB::table('integrations')->insert(['id' => 901, 'name' => 'test', 'marketplace' => 'ozon',
            'credentials' => app('encrypter')->encryptString(json_encode(['client_id' => 'fake', 'api_key' => 'fake'])),
            'settings' => json_encode(['ozon_premium_plus_required' => $premiumRequired])]);
    }

    private function integration(): Integration
    {
        $integration = new class extends Integration
        {
            public function resolveCredentials(): array
            {
                return ['client_id' => 'fake', 'api_key' => 'fake'];
            }
        };
        $integration->id = 901;
        $integration->settings = ['ozon_premium_plus_required' => false];

        return $integration;
    }

    private function reportRow(float $price, int $quantity, float $commission): array
    {
        return ['item' => ['sku' => 100, 'offer_id' => 'demo'], 'seller_price_per_instance' => $price,
            'delivery_commission' => ['quantity' => $quantity, 'standard_fee' => $commission],
            'return_commission' => ['quantity' => 0, 'standard_fee' => 0]];
    }

    private function fakeDay(array $rows, float $lastMile = 325): void
    {
        Http::fake([
            'api-seller.ozon.ru/v1/finance/realization/by-day' => Http::response(['rows' => $rows]),
            'api-seller.ozon.ru/v1/finance/accrual/by-day' => Http::response(['accruals' => $lastMile > 0 ? [[
                'accrual_id' => 'fee1', 'item_fees' => ['fees' => [['sku' => 100,
                    'fees' => [['type_id' => 29, 'accrued' => ['amount' => -$lastMile]]]]]],
            ]] : []]),
        ]);
    }

    private function sale(string $date, int $quantity, float $revenue, string $source, ?string $operationId = null): void
    {
        OzonFinanceTransaction::create(['integration_id' => 901, 'operation_id' => $operationId ?? 'sale:'.$date.':'.$source,
            'operation_type' => FinanceTransactionSyncer::SALE_OPERATION_TYPE, 'operation_date' => $date,
            'sku' => '100', 'amount' => $revenue, 'accruals_for_sale' => $revenue,
            'raw' => ['source' => $source, 'quantity' => $quantity, 'month' => substr($date, 0, 7)]]);
    }

    private function fee(string $date, float $amount, string $type = 'LastMileCourier'): void
    {
        OzonFinanceTransaction::create(['integration_id' => 901, 'operation_id' => 'fee:'.$date.':'.$type,
            'operation_type' => $type, 'operation_date' => $date, 'sku' => '100', 'amount' => $amount]);
    }
}
