<?php

namespace Tests\Feature;

use App\Models\Integration;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    public function test_needs_sync_returns_true_when_never_synced(): void
    {
        $integration = new Integration([
            'name' => 'Test',
            'marketplace' => 'wildberries',
            'is_active' => true,
            'auto_sync_enabled' => true,
            'last_sync_at' => null,
        ]);

        $this->assertTrue($integration->needsSync());
    }

    public function test_needs_sync_returns_false_when_recently_synced(): void
    {
        $integration = new Integration([
            'name' => 'Test',
            'marketplace' => 'wildberries',
            'is_active' => true,
            'auto_sync_enabled' => true,
            'sync_interval_hours' => 6,
            'last_sync_at' => now()->subHours(2),
        ]);

        $this->assertFalse($integration->needsSync());
    }

    public function test_needs_sync_returns_true_when_interval_passed(): void
    {
        $integration = new Integration([
            'name' => 'Test',
            'marketplace' => 'wildberries',
            'is_active' => true,
            'auto_sync_enabled' => true,
            'sync_interval_hours' => 6,
            'last_sync_at' => now()->subHours(7),
        ]);

        $this->assertTrue($integration->needsSync());
    }

    public function test_needs_sync_returns_false_when_inactive(): void
    {
        $integration = new Integration([
            'name' => 'Test',
            'marketplace' => 'wildberries',
            'is_active' => false,
            'auto_sync_enabled' => true,
        ]);

        $this->assertFalse($integration->needsSync());
    }

    public function test_needs_sync_returns_false_when_auto_sync_disabled(): void
    {
        $integration = new Integration([
            'name' => 'Test',
            'marketplace' => 'wildberries',
            'is_active' => true,
            'auto_sync_enabled' => false,
        ]);

        $this->assertFalse($integration->needsSync());
    }
}
