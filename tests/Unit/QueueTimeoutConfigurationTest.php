<?php

namespace Tests\Unit;

use App\Jobs\SyncUnitEconomicsJob;
use Tests\TestCase;

class QueueTimeoutConfigurationTest extends TestCase
{
    public function test_database_retry_after_exceeds_unit_economics_job_timeout(): void
    {
        $job = new SyncUnitEconomicsJob(1);

        $this->assertGreaterThan(
            $job->timeout,
            (int) config('queue.connections.database.retry_after')
        );
    }
}
