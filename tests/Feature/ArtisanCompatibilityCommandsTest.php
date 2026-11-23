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
        $eventsDispatched = 0;
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Console\Events\ScheduledTaskStarting::class,
            function () use (&$eventsDispatched): void {
                $eventsDispatched++;
            }
        );

        // Bootstrap the console kernel so routes/console.php registers its schedule first,
        // then replace Schedule with an empty instance so this test only verifies option parsing.
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $this->app->instance(
            \Illuminate\Console\Scheduling\Schedule::class,
            new \Illuminate\Console\Scheduling\Schedule()
        );

        $this->artisan('schedule:run', ['--columns' => 'ignored', '--whisper' => true])
            ->assertSuccessful();

        $this->assertSame(0, $eventsDispatched, 'Test must not execute live scheduled jobs.');
    }
}
