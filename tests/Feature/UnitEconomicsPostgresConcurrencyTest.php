<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\UnitEconomicsSyncRun;
use App\Models\UnitEconomicsSyncState;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnitEconomicsPostgresConcurrencyTest extends TestCase
{
    public function test_two_independent_database_connections_do_not_lose_coalesced_requests(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertStringStartsWith('codex_ue_', DB::getDatabaseName(), 'Only the isolated UE schema fixture may run this process test.');
        $integration = Integration::factory()->wildberries()->create(['id' => random_int(63000, 69000)]);
        $ids = [];
        try {
            // Fork only after closing the inherited socket; each child establishes
            // its own connection and commits real transactions, without test rollback.
            DB::disconnect();
            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) { throw new \RuntimeException('Could not fork database test process'); }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        for ($i = 0; $i < 10; $i++) {
                            app(UnitEconomicsSyncCoordinator::class)->request(Integration::findOrFail($integration->id));
                        }
                        DB::disconnect();
                        exit(0);
                    } catch (\Throwable $e) {
                        fwrite(STDERR, 'Concurrent request test failed: '.$e->getMessage().PHP_EOL);
                        exit(1);
                    }
                }
                $ids[] = $pid;
            }
            foreach ($ids as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            $this->assertSame(20, UnitEconomicsSyncState::findOrFail($integration->id)->requested_version);
            $this->assertSame(1, UnitEconomicsSyncRun::where('integration_id', $integration->id)->count());
            $this->assertSame(20, app(UnitEconomicsSyncCoordinator::class)->latest($integration->id)->requested_version);
        } finally {
            DB::purge();
            Integration::whereKey($integration->id)->delete();
        }
    }
}
