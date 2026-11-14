<?php

namespace App\Services\UnitEconomics;

/** Thresholds apply to ready work; intentional delay and external cooldown are separate. */
class UnitEconomicsHealthAlerts
{
    public function evaluate(array $snapshot): array
    {
        $issues = [];
        foreach ($snapshot['queues'] ?? [] as $queue) {
            $queue = (array) $queue;
            if (in_array($queue['queue'], ['default', 'unit-economics', 'price-refresh'], true)
                && ($queue['oldest_ready_seconds'] ?? 0) > 60) {
                $issues[] = ['severity' => 'error', 'code' => 'ready_queue_stalled', 'queue' => $queue['queue'], 'age_seconds' => $queue['oldest_ready_seconds']];
            }
        }
        if (($snapshot['response_to_backend_publication_p95_seconds'] ?? 0) > 120) {
            $issues[] = ['severity' => 'error', 'code' => 'price_publication_slow', 'seconds' => $snapshot['response_to_backend_publication_p95_seconds']];
        }
        foreach (['expired_open_runs', 'invalid_completed_runs'] as $field) {
            if (($snapshot[$field] ?? 0) > 0) {
                $issues[] = ['severity' => 'error', 'code' => $field, 'count' => $snapshot[$field]];
            }
        }

        return $issues;
    }
}
