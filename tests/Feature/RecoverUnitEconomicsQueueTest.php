<?php

namespace Tests\Feature;

use App\Jobs\RecalculateUnitEconomicsCacheJob;
use App\Jobs\SyncSupplyStatusesJob;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecoverUnitEconomicsQueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dry_run_preserves_every_job_and_apply_moves_only_known_unreserved_jobs(): void
    {
        $supply = $this->job(SyncSupplyStatusesJob::class);
        $cache = $this->job(RecalculateUnitEconomicsCacheJob::class);
        $running = $this->job(SyncSupplyStatusesJob::class, time());
        $other = $this->job('App\\Jobs\\SyncProductsJob');
        $availableAt = DB::table('jobs')->where('id', $supply)->value('available_at');
        $before = DB::table('jobs')->orderBy('id')->get()->toJson();

        $this->artisan('ue:recover-queue')->assertSuccessful();
        $this->assertSame($before, DB::table('jobs')->orderBy('id')->get()->toJson());
        $this->artisan('ue:recover-queue', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('jobs', ['id' => $supply, 'queue' => 'supply-statuses', 'attempts' => 2, 'available_at' => $availableAt]);
        $this->assertDatabaseHas('jobs', ['id' => $cache, 'queue' => 'unit-economics', 'attempts' => 2]);
        $this->assertDatabaseHas('jobs', ['id' => $running, 'queue' => 'default']);
        $this->assertDatabaseHas('jobs', ['id' => $other, 'queue' => 'default']);
        $this->assertSame(4, DB::table('jobs')->count());
    }

    public function test_target_scope_and_explicit_attempt_reset_do_not_touch_other_work(): void
    {
        $supply = $this->job(SyncSupplyStatusesJob::class);
        $cache = $this->job(RecalculateUnitEconomicsCacheJob::class);

        $this->artisan('ue:recover-queue', ['--apply' => true, '--target' => 'supply-statuses', '--reset-attempts' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('jobs', ['id' => $supply, 'queue' => 'supply-statuses', 'attempts' => 0]);
        $this->assertDatabaseHas('jobs', ['id' => $cache, 'queue' => 'default', 'attempts' => 2]);
    }

    private function job(string $class, ?int $reserved = null): int
    {
        return DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $class, 'data' => ['commandName' => $class]], JSON_THROW_ON_ERROR),
            'attempts' => 2,
            'reserved_at' => $reserved,
            'available_at' => time() + 120,
            'created_at' => time() - 3600,
        ]);
    }
}
