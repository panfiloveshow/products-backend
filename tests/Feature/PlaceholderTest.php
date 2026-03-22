<?php

namespace Tests\Feature;

use Tests\TestCase;

class PlaceholderTest extends TestCase
{
    public function test_placeholder_endpoint_returns_svg(): void
    {
        $response = $this->get('/api/placeholder/56/56');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $response->assertSee('56x56', false);
    }
}
