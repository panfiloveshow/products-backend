<?php

namespace App\Console\Commands;

use App\Models\UnitEconomicsSyncRun;
use App\Services\UnitEconomics\UnitEconomicsHealthAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UnitEconomicsSyncHealth extends Command
{
    protected $signature = 'ue:sync-health {--integration=} {--hours=24} {--fail-on-degraded} {--log}';

    protected $description = 'JSON snapshot of ready queue ages, persisted stage latency, cooldowns and failures';

    public function handle(): int
    {
        $now = time();
        $queues = DB::table('jobs')->selectRaw('queue, count(*) total, '
            .'count(*) FILTER (WHERE reserved_at IS NULL AND available_at <= ?) ready, '
            .'count(*) FILTER (WHERE reserved_at IS NULL AND available_at > ?) scheduled, '
            .'max(? - available_at) FILTER (WHERE reserved_at IS NULL AND available_at <= ?) oldest_ready_seconds', [$now, $now, $now, $now])
            ->groupBy('queue')->get();
        $runs = UnitEconomicsSyncRun::where('created_at', '>=', now()->subHours(max(1, min(168, (int) $this->option('hours')))))
            ->when($this->option('integration'), fn ($q) => $q->where('integration_id', (int) $this->option('integration')))
            ->get();
        $queueWaits = [];
        $publicationLags = [];
        foreach ($runs as $run) {
            foreach ($run->stages as $stage) {
                if (isset($stage['queue_wait_seconds'])) {
                    $queueWaits[] = (float) $stage['queue_wait_seconds'];
                }
                if (isset($stage['response_to_publication_seconds'])) {
                    $publicationLags[] = (float) $stage['response_to_publication_seconds'];
                }
            }
        }
        $percentile = function (array $values): ?float {
            if ($values === []) {
                return null;
            }
            sort($values);

            return $values[max(0, (int) ceil(count($values) * 0.95) - 1)];
        };
        $observation = "CASE WHEN products.marketplace = 'wildberries' THEN products.wb_data->>'price_observed_at' ELSE products.ozon_data->>'price_observed_at' END";
        $sources = DB::table('products')->join('integrations', 'integrations.id', '=', 'products.integration_id')
            ->where('integrations.is_active', true)->whereIn('products.marketplace', ['wildberries', 'ozon'])
            ->when($this->option('integration'), fn ($q) => $q->where('products.integration_id', (int) $this->option('integration')))
            ->selectRaw("products.integration_id, products.marketplace, count(*) total, count({$observation}) observed, min({$observation}) oldest_observed_at")
            ->groupBy('products.integration_id', 'products.marketplace')->get();
        foreach ($sources as $source) {
            $source->unknown = $source->total - $source->observed;
            $source->oldest_age_seconds = null;
            if ($source->oldest_observed_at) {
                try {
                    $source->oldest_age_seconds = max(0, now()->getTimestamp() - CarbonImmutable::parse($source->oldest_observed_at)->getTimestamp());
                } catch (\Throwable) {
                    $source->invalid_observation = true;
                }
            }
        }
        $snapshot = ['at' => gmdate('c'), 'queues' => $queues, 'runs' => $runs->count(),
            'price_sources' => $sources,
            'run_status_counts' => $runs->countBy('status'), 'ready_wait_p95_seconds' => $percentile($queueWaits),
            'ready_wait_samples' => count($queueWaits), 'response_to_backend_publication_p95_seconds' => $percentile($publicationLags),
            'publication_samples' => count($publicationLags),
            'expired_open_runs' => $runs->filter(fn ($r) => in_array($r->status, ['queued', 'running', 'waiting_external'], true) && $r->deadline_at->isPast())->count(),
            'waiting_external' => $runs->where('status', 'waiting_external')->map(fn ($r) => ['integration_id' => $r->integration_id,
                'run_id' => $r->id, 'next_retry_at' => $r->next_retry_at?->toIso8601String()])->values(),
            'invalid_completed_runs' => $runs->where('status', 'completed')->filter(fn ($r) => collect($r->required_stages)->contains(fn ($name) => ($r->stages[$name]['status'] ?? '') !== 'completed'))->count(),
            'scope_note' => 'Queue ages are current unstarted work. P95 uses persisted stages; external cooldown is separate. UI render latency requires browser evidence.'];
        $snapshot['issues'] = app(UnitEconomicsHealthAlerts::class)->evaluate($snapshot);
        $snapshot['healthy'] = $snapshot['issues'] === [];
        $this->line(json_encode($snapshot, JSON_UNESCAPED_UNICODE));
        if ($this->option('log')) {
            $key = 'ue:sync-health:issue-signature';
            // Log a changed incident once; unchanged snapshots remain available in the cron log.
            $signature = hash('sha256', json_encode(array_map(fn ($i) => [$i['code'], $i['queue'] ?? null], $snapshot['issues'])));
            if (Cache::get($key) !== $signature) {
                if (! $snapshot['healthy']) {
                    Log::error('Unit economics operational thresholds exceeded', ['issues' => $snapshot['issues']]);
                } elseif (Cache::has($key)) {
                    Log::info('Unit economics operational thresholds recovered');
                }
                Cache::put($key, $signature, now()->addDay());
            }
        }

        return $this->option('fail-on-degraded') && ! $snapshot['healthy'] ? self::FAILURE : self::SUCCESS;
    }
}
