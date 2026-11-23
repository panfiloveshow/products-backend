<?php

namespace Tests\Feature;

use Tests\TestCase;

class ArtisanCompatibilityCommandsTest extends TestCase
{
    public function test_route_list_supports_columns_option(): void
    {
        $this->artisan('route:list', ['--columns' => 'method,uri'])
            ->assertSuccessful();
    }

    public function test_schedule_list_accepts_legacy_columns_option(): void
    {
        $this->artisan('schedule:list', ['--columns' => 'expression'])
            ->assertSuccessful();
    }

    public function test_schedule_run_accepts_legacy_columns_option(): void
    {
        $this->artisan('schedule:run', ['--columns' => 'ignored', '--whisper' => true])
            ->assertSuccessful();
    }
}
