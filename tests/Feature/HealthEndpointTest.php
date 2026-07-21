<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_exposes_deployed_release(): void
    {
        config()->set('app.release', 'test-release-sha');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'products-backend')
            ->assertJsonPath('release', 'test-release-sha')
            ->assertJsonStructure(['time']);
    }
}
