<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\IntegrationController;
use App\Http\Requests\Integration\StoreIntegrationRequest;
use App\Models\Integration;
use App\Models\InventoryAlert;
use App\Services\IntegrationAccessService;
use App\Services\SellicoApiService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationReconciliationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reconciliation_is_always_scheduled_every_minute_without_overlapping(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
        $events = array_values(array_filter(app(Schedule::class)->events(), fn ($event) => str_contains($event->command ?? '', 'integrations:reconcile --apply')));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    private function fakeSnapshot(array $payload, int $status = 200, bool $withManifest = true): void
    {
        Cache::put('sellico_service_access_token', 'test-service-token', 60);
        Http::preventStrayRequests();
        $body = $withManifest && array_is_list($payload) ? ['scope' => 'all', 'complete' => true, 'integrations' => $payload] : $payload;
        Http::fake(['*/get-integrations*' => Http::response($body, $status)]);
    }

    public function test_global_snapshot_keeps_inactive_integrations_and_discards_credentials(): void
    {
        $this->fakeSnapshot([['id' => 9001, 'work_space_id' => 10, 'status' => 'inactive', 'api_key' => 'must-not-be-retained']]);
        $result = app(SellicoApiService::class)->getIntegrationSnapshot();
        $this->assertSame(['success' => true, 'integrations' => [['id' => 9001, 'work_space_id' => 10]]], $result);
    }

    public function test_snapshot_carries_only_a_valid_opaque_credential_version(): void
    {
        $version = str_repeat('a', 64);
        $this->fakeSnapshot([['id' => 9001, 'work_space_id' => 10, 'credentials_version' => $version, 'api_key' => 'must-not-be-retained']]);
        $result = app(SellicoApiService::class)->getIntegrationSnapshot();
        $this->assertSame($version, $result['integrations'][0]['credentials_version']);
        $this->assertArrayNotHasKey('api_key', $result['integrations'][0]);
    }

    public function test_invalid_credential_version_rejects_the_snapshot(): void
    {
        $this->fakeSnapshot([['id' => 9001, 'work_space_id' => 10, 'credentials_version' => 'invalid']]);
        $this->assertFalse(app(SellicoApiService::class)->getIntegrationSnapshot()['success']);
    }

    public function test_global_reconciliation_removes_deleted_workspaces_and_related_alerts_but_keeps_current_stores(): void
    {
        Integration::factory()->ozon()->create(['id' => 9001, 'work_space_id' => 10]);
        Integration::factory()->ozon()->create(['id' => 9002, 'work_space_id' => 99]);
        InventoryAlert::create(['integration_id' => 9002, 'sku' => 'old', 'type' => 'critical', 'message' => 'old', 'action' => 'monitor']);
        $this->fakeSnapshot([['id' => 9001, 'work_space_id' => 10, 'status' => 'inactive']]);

        $this->artisan('integrations:reconcile --apply')->assertSuccessful();
        $this->assertDatabaseHas('integrations', ['id' => 9001]);
        $this->assertDatabaseMissing('integrations', ['id' => 9002]);
        $this->assertDatabaseMissing('inventory_alerts', ['integration_id' => 9002]);
        $this->artisan('integrations:reconcile --apply')->assertSuccessful();
    }

    public function test_unavailable_or_paginated_snapshot_cannot_delete_anything(): void
    {
        Integration::factory()->ozon()->create(['id' => 9002, 'work_space_id' => 99]);
        $this->fakeSnapshot(['data' => [], 'next_page_url' => '/next']);
        $this->artisan('integrations:reconcile --apply')->assertFailed();
        $this->assertDatabaseHas('integrations', ['id' => 9002]);
    }

    public function test_authoritative_empty_global_inventory_removes_the_last_deleted_integration(): void
    {
        Integration::factory()->ozon()->create(['id' => 9002, 'work_space_id' => 99]);
        $this->fakeSnapshot([]);
        $this->artisan('integrations:reconcile --apply')->assertSuccessful();
        $this->assertDatabaseMissing('integrations', ['id' => 9002]);
    }

    public function test_malformed_snapshot_is_rejected_instead_of_dropping_invalid_rows(): void
    {
        $this->fakeSnapshot([['id' => 9001, 'work_space_id' => 10], ['id' => 9002]]);
        $this->assertFalse(app(SellicoApiService::class)->getIntegrationSnapshot()['success']);
    }

    public function test_deleted_integration_cannot_be_recreated_by_lazy_access(): void
    {
        config(['services.sellico.skip_permission_check' => true]);
        $client = $this->mock(SellicoApiService::class);
        $client->shouldReceive('getIntegrationById')->with(9002, null)->once()->andReturn(['success' => false]);
        $result = app(IntegrationAccessService::class)->ensureAccessibleIntegration(Request::create('/'), 9002, 'ozon');
        $this->assertSame(404, $result['status']);
        $this->assertDatabaseMissing('integrations', ['id' => 9002]);
    }

    public function test_records_without_a_workspace_are_removed_when_absent_from_canonical_inventory(): void
    {
        Integration::factory()->ozon()->create(['id' => 9002, 'work_space_id' => null]);
        $this->fakeSnapshot([]);
        $this->artisan('integrations:reconcile --apply')->assertSuccessful();
        $this->assertDatabaseMissing('integrations', ['id' => 9002]);
    }

    public function test_complete_global_inventory_allows_more_than_five_confirmed_deletions(): void
    {
        for ($id = 9001; $id <= 9007; $id++) {
            Integration::factory()->ozon()->create(['id' => $id, 'work_space_id' => 10]);
        }
        $this->fakeSnapshot([]);
        $this->artisan('integrations:reconcile --apply')->assertSuccessful();
        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_server_error_cannot_be_mistaken_for_an_empty_inventory(): void
    {
        Integration::factory()->ozon()->create(['id' => 9002, 'work_space_id' => 99]);
        $this->fakeSnapshot([], 503);
        $this->artisan('integrations:reconcile --apply')->assertFailed();
        $this->assertDatabaseHas('integrations', ['id' => 9002]);
    }

    public function test_legacy_local_creation_cannot_recreate_an_integration_outside_sellico(): void
    {
        $request = StoreIntegrationRequest::create('/api/integrations', 'POST', ['name' => 'old shop', 'marketplace' => 'ozon', 'credentials' => ['api_key' => 'old']]);
        $response = app(IntegrationController::class)->store($request);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_unconfirmed_empty_response_cannot_authorize_deletion(): void
    {
        Integration::factory()->ozon()->create(['id' => 9002, 'work_space_id' => 99]);
        $this->fakeSnapshot([], 200, false);
        $this->artisan('integrations:reconcile --apply')->assertFailed();
        $this->assertDatabaseHas('integrations', ['id' => 9002]);
    }

    public function test_new_integration_created_in_sellico_can_still_be_imported(): void
    {
        config(['services.sellico.skip_permission_check' => true]);
        $client = $this->mock(SellicoApiService::class);
        $client->shouldReceive('getIntegrationById')->with(9001, null)->once()->andReturn([
            'success' => true, 'integration' => ['id' => 9001, 'work_space_id' => 10, 'type' => 'OZON', 'name' => 'Current shop'],
            'credentials' => ['client_id' => '123', 'api_key' => 'valid-key'],
        ]);
        $result = app(IntegrationAccessService::class)->ensureAccessibleIntegration(Request::create('/'), 9001, 'ozon');
        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('integrations', ['id' => 9001, 'work_space_id' => 10]);
    }

    public function test_explicit_id_scope_does_not_delete_other_missing_integrations(): void
    {
        Integration::factory()->ozon()->create(['id' => 9001, 'work_space_id' => 10]);
        Integration::factory()->ozon()->create(['id' => 9002, 'work_space_id' => 10]);
        $this->fakeSnapshot([]);
        $this->artisan('integrations:reconcile --apply --integration=9002')->assertSuccessful();
        $this->assertDatabaseHas('integrations', ['id' => 9001]);
        $this->assertDatabaseMissing('integrations', ['id' => 9002]);
    }

    public function test_invalid_id_scope_cannot_expand_into_unbounded_deletion(): void
    {
        Integration::factory()->ozon()->create(['id' => 9001, 'work_space_id' => 10]);
        $this->artisan('integrations:reconcile --apply --integration=bad')->assertFailed();
        $this->assertDatabaseHas('integrations', ['id' => 9001]);
    }

    public function test_invalid_workspace_scope_cannot_delete_unassigned_records(): void
    {
        Integration::factory()->ozon()->create(['id' => 9002, 'work_space_id' => null]);
        $this->artisan('integrations:reconcile --apply --workspace=bad')->assertFailed();
        $this->assertDatabaseHas('integrations', ['id' => 9002]);
    }

    public function test_database_failure_rolls_back_integration_deletion_and_fails_the_command(): void
    {
        Integration::factory()->ozon()->create(['id' => 9002, 'work_space_id' => 10]);
        InventoryAlert::create(['integration_id' => 9002, 'sku' => 'guarded', 'type' => 'critical', 'message' => 'guarded', 'action' => 'monitor']);
        DB::statement("CREATE TRIGGER block_reconciliation BEFORE DELETE ON inventory_alerts BEGIN SELECT RAISE(ABORT, 'Deletion unavailable'); END");
        $this->fakeSnapshot([]);
        $this->artisan('integrations:reconcile --apply')->assertFailed();
        $this->assertDatabaseHas('integrations', ['id' => 9002]);
        $this->assertDatabaseHas('inventory_alerts', ['integration_id' => 9002]);
    }
}
