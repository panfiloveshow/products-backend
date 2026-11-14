<?php

namespace Tests\Feature;

use App\Domains\Locality\Jobs\SyncFinanceTransactionsJob;
use App\Jobs\SyncUnitEconomicsJob;
use App\Models\Integration;
use App\Models\InventoryAlert;
use App\Services\IntegrationCredentialMirrorService;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

class IntegrationCredentialMirrorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function integration(array $overrides = []): Integration
    {
        return Integration::factory()->ozon()->create(array_merge(['id' => 9501, 'work_space_id' => 10,
            'credentials' => ['api_key' => 'old-key', 'client_id' => '123'], 'credential_health' => 'invalid',
            'last_validation_error' => 'Api-key has expired', 'settings' => ['custom' => 'preserved']], $overrides));
    }

    private function remote(string $key = 'new-key', int $workspace = 10): void
    {
        $this->mock(SellicoApiService::class)->shouldReceive('getIntegrationById')->once()->with(9501, 10)->andReturn([
            'success' => true, 'integration' => ['id' => 9501, 'work_space_id' => $workspace],
            'credentials' => ['api_key' => $key, 'client_id' => '123', 'performance_client_secret' => 'runtime-only'],
        ]);
    }

    public function test_changed_canonical_key_is_mirrored_encrypted_and_finance_precedes_recalculation(): void
    {
        Bus::fake();
        $i = $this->integration();
        $alert = InventoryAlert::create(['integration_id' => $i->id, 'sku' => '__OZON_CREDENTIAL__', 'type' => 'critical', 'message' => 'old key expired', 'action' => 'monitor']);
        $this->remote();
        $this->assertTrue(app(IntegrationCredentialMirrorService::class)->sync($i, str_repeat('a', 64)));
        $fresh = $i->fresh();
        $this->assertSame('new-key', $fresh->credentials['api_key']);
        $this->assertTrue($alert->fresh()->is_resolved);
        $this->assertArrayNotHasKey('performance_client_secret', $fresh->credentials);
        $this->assertStringNotContainsString('new-key', $fresh->getRawOriginal('credentials'));
        $this->assertSame('unknown', $fresh->credential_health);
        $this->assertNull($fresh->last_validation_error);
        $this->assertSame('preserved', $fresh->settings['custom']);
        $this->assertSame(str_repeat('a', 64), $fresh->settings['sellico_credentials_version']);
        Bus::assertChained([SyncFinanceTransactionsJob::class, SyncUnitEconomicsJob::class]);
    }

    public function test_current_version_does_not_fetch_keys_or_enqueue_duplicate_syncs(): void
    {
        Bus::fake();
        $version = str_repeat('a', 64);
        $i = $this->integration(['settings' => ['sellico_credentials_version' => $version]]);
        $this->mock(SellicoApiService::class)->shouldNotReceive('getIntegrationById');
        $this->assertFalse(app(IntegrationCredentialMirrorService::class)->sync($i, $version));
        Bus::assertNothingDispatched();
    }

    public function test_unchanged_bad_key_is_not_marked_healthy_or_requeued(): void
    {
        Bus::fake();
        $i = $this->integration();
        $this->remote('old-key');
        $this->assertFalse(app(IntegrationCredentialMirrorService::class)->sync($i, str_repeat('b', 64)));
        $this->assertSame('invalid', $i->fresh()->credential_health);
        Bus::assertNothingDispatched();
    }

    public function test_wrong_workspace_cannot_replace_credentials(): void
    {
        Bus::fake();
        $i = $this->integration();
        $this->remote('new-key', 99);
        try {
            app(IntegrationCredentialMirrorService::class)->sync($i, str_repeat('a', 64));
            $this->fail('Wrong workspace was accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('old-key', $i->fresh()->credentials['api_key']);
        }
        Bus::assertNothingDispatched();
    }

    public function test_queue_failure_rolls_back_the_key_and_version_so_the_next_run_can_retry(): void
    {
        $i = $this->integration();
        $this->remote();
        Bus::shouldReceive('chain')->once()->andThrow(new RuntimeException('Queue unavailable'));
        try {
            app(IntegrationCredentialMirrorService::class)->sync($i, str_repeat('a', 64));
            $this->fail('Queue failure was ignored');
        } catch (RuntimeException $e) {
            $this->assertSame('old-key', $i->fresh()->credentials['api_key']);
        }
        $this->assertArrayNotHasKey('sellico_credentials_version', $i->fresh()->settings);
    }

    public function test_other_marketplaces_are_not_rewritten_by_the_ozon_credential_mirror(): void
    {
        Bus::fake();
        $i = $this->integration(['marketplace' => 'wildberries']);
        $this->mock(SellicoApiService::class)->shouldNotReceive('getIntegrationById');
        $this->assertFalse(app(IntegrationCredentialMirrorService::class)->sync($i, str_repeat('a', 64)));
        $this->assertSame('old-key', $i->fresh()->credentials['api_key']);
        Bus::assertNothingDispatched();
    }
}
