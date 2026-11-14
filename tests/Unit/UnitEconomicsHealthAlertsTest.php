<?php

namespace Tests\Unit;

use App\Services\UnitEconomics\UnitEconomicsHealthAlerts;
use PHPUnit\Framework\TestCase;

class UnitEconomicsHealthAlertsTest extends TestCase
{
    public function test_scheduled_external_wait_does_not_trigger_a_ready_queue_incident(): void
    {
        $issues = (new UnitEconomicsHealthAlerts)->evaluate(['queues' => [
            ['queue' => 'price-refresh', 'scheduled' => 8, 'oldest_ready_seconds' => null],
        ], 'waiting_external' => [['next_retry_at' => '2099-01-01T00:00:00Z']]]);
        $this->assertSame([], $issues);
    }

    public function test_a_real_ready_backlog_and_slow_publication_are_detected(): void
    {
        $issues = (new UnitEconomicsHealthAlerts)->evaluate(['queues' => [
            ['queue' => 'price-refresh', 'ready' => 1, 'oldest_ready_seconds' => 61],
        ], 'response_to_backend_publication_p95_seconds' => 121]);
        $this->assertSame(['ready_queue_stalled', 'price_publication_slow'], array_column($issues, 'code'));
        $this->assertSame('price-refresh', $issues[0]['queue']);
    }

    public function test_expired_runs_and_false_completion_are_detected(): void
    {
        $issues = (new UnitEconomicsHealthAlerts)->evaluate(['expired_open_runs' => 1, 'invalid_completed_runs' => 1]);
        $this->assertSame(['expired_open_runs', 'invalid_completed_runs'], array_column($issues, 'code'));
    }
}
