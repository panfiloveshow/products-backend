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

    public function test_route_list_command_declares_as_command_attribute_for_lazy_loading(): void
    {
        $attrs = (new \ReflectionClass(\App\Console\Commands\RouteListCommand::class))
            ->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class);

        $this->assertCount(1, $attrs, 'RouteListCommand must declare #[AsCommand] so Artisan keeps lazy loading.');
        $this->assertSame('route:list', $attrs[0]->newInstance()->name);
    }

    public function test_console_routes_read_schedule_flags_from_config_not_env(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));
        $this->assertIsString($source);
        $codeOnly = preg_replace('#//[^\n]*|/\*[\s\S]*?\*/#', '', $source) ?? '';

        $this->assertDoesNotMatchRegularExpression(
            '/\benv\s*\(/',
            $codeOnly,
            'routes/console.php must use config(), never env(), so flags work under config:cache.'
        );

        foreach (['ozon_storage', 'wb_tariff', 'mp_constraints', 'integrations_dedup', 'inventory_sync'] as $key) {
            $this->assertIsBool(config("schedules.{$key}"));
        }
    }
}
