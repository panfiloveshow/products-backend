<?php

namespace App\Console\Commands;

use App\Jobs\RecalculateUnitEconomicsCacheJob;
use App\Jobs\SyncSupplyStatusesJob;
use App\Jobs\SyncSupplyStatusJob;
use App\Jobs\SyncUnitEconomicsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecoverUnitEconomicsQueue extends Command
{
    protected $signature = 'ue:recover-queue
        {--source=default : Legacy database queue to inspect}
        {--target= : Restrict to one supported destination queue}
        {--limit=1000 : Maximum number of matching jobs}
        {--apply : Move the reviewed jobs; default is read-only}
        {--reset-attempts : Explicitly reset attempts after the underlying defect was fixed}';

    protected $description = 'Relocate known unreserved UE/supply jobs without deleting payloads or bypassing retry windows';

    public function handle(): int
    {
        $routes = [
            SyncSupplyStatusesJob::class => config('autoplanning.execution.status_queue', 'supply-statuses'),
            SyncSupplyStatusJob::class => config('autoplanning.execution.status_queue', 'supply-statuses'),
            SyncUnitEconomicsJob::class => 'unit-economics',
            RecalculateUnitEconomicsCacheJob::class => 'unit-economics',
        ];
        $target = $this->option('target');
        if ($target && ! in_array($target, $routes, true)) {
            $this->error('Unsupported destination queue.');

            return self::FAILURE;
        }
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 10000) {
            $this->error('Limit must be between 1 and 10000.');

            return self::FAILURE;
        }
        $connection = DB::connection(config('queue.connections.database.connection'));
        $table = config('queue.connections.database.table', 'jobs');
        $source = (string) $this->option('source');
        $apply = (bool) $this->option('apply');
        $rows = [];
        $moved = 0;

        $connection->table($table)->where('queue', $source)->whereNull('reserved_at')
            ->select(['id', 'queue', 'payload', 'attempts', 'available_at'])
            ->chunkById(100, function ($jobs) use ($connection, $table, $source, $routes, $target, $apply, $limit, &$rows, &$moved): bool {
                foreach ($jobs as $job) {
                    $payload = json_decode($job->payload, true);
                    $class = $payload['displayName'] ?? null;
                    $destination = is_string($class) ? ($routes[$class] ?? null) : null;
                    if (! $destination || $destination === $source || ($target && $destination !== $target)) {
                        continue;
                    }
                    $changed = false;
                    if ($apply) {
                        $values = ['queue' => $destination];
                        if ($this->option('reset-attempts')) {
                            $values['attempts'] = 0;
                        }
                        // A worker may reserve between SELECT and UPDATE. The
                        // guarded update never relocates that in-flight job.
                        $changed = $connection->table($table)->where('id', $job->id)
                            ->where('queue', $source)->whereNull('reserved_at')
                            ->where('payload', $job->payload)->update($values) === 1;
                        $moved += (int) $changed;
                    }
                    $rows[] = [
                        'id' => $job->id,
                        'class' => $class,
                        'from' => $source,
                        'to' => $destination,
                        'attempts_before' => $job->attempts,
                        'available_at' => $job->available_at,
                        'result' => $apply ? ($changed ? 'moved' : 'reserved_or_changed') : 'preview',
                    ];
                    if (count($rows) >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        $this->table(['id', 'class', 'from', 'to', 'attempts_before', 'available_at', 'result'], $rows);
        $this->info($apply ? "Moved {$moved} jobs." : 'Preview only; no jobs changed.');
        if ($apply) {
            Log::notice('UE queue recovery applied', ['source' => $source, 'reset_attempts' => (bool) $this->option('reset-attempts'), 'jobs' => $rows]);
        }

        return self::SUCCESS;
    }
}
