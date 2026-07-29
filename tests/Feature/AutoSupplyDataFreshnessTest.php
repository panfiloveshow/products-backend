<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\AutoSupplyPlanning\DataFreshnessRegistry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AutoSupplyDataFreshnessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_missing_blocking_sources_are_reported_fail_closed(): void
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => 808080,
            'work_space_id' => 80,
        ]);

        $health = app(DataFreshnessRegistry::class)->inspect($integration);

        $this->assertSame('blocked', $health['status']);
        $this->assertFalse($health['can_calculate']);
        $this->assertFalse($health['can_approve']);
        $this->assertSame('missing', $health['sources']['products']['status']);
        $this->assertSame('missing', $health['sources']['inventory']['status']);
        $this->assertSame('missing', $health['sources']['demand']['status']);
        $this->assertCount(3, $health['blocking_errors']);
        $this->assertSame(64, strlen($health['hash']));
    }

    public function test_expired_ozon_credentials_are_a_blocking_source(): void
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => 808081,
            'work_space_id' => 80,
            'credential_type' => 'oauth',
            'credentials' => ['oauth_access_token' => 'expired-token'],
            'credentials_expires_at' => now()->subMinute(),
        ]);

        $health = app(DataFreshnessRegistry::class)->inspect($integration);

        $this->assertSame('expired', $health['sources']['credentials']['status']);
        $this->assertTrue($health['sources']['credentials']['blocking']);
        $this->assertFalse($health['can_approve']);
        $this->assertContains(
            'credentials',
            collect($health['blocking_errors'])->pluck('source')->all()
        );
    }
}
