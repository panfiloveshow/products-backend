<?php

namespace App\Console\Commands;

use App\Jobs\ProjectMarketplacePricesJob;
use App\Jobs\RefreshMarketplacePricesJob;
use App\Models\Integration;
use App\Models\UnitEconomicsSyncRun;
use App\Services\UnitEconomics\UnitEconomicsSyncCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Default preview; apply only eligible active integrations or expired leases. */
class RefreshUnitEconomicsPrices extends Command
{
    protected $signature = 'ue:refresh-prices {--integration=*} {--stale-only} {--recover} {--limit=10} {--apply}';

    protected $description = 'Preview/dispatch confirmed price refresh or recover persisted price/projection leases';

    public function handle(UnitEconomicsSyncCoordinator $coordinator): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        if ($this->option('recover')) {
            return $this->recover($coordinator, $limit);
        }
        $candidates = Integration::where('is_active', true)->whereIn('marketplace', ['wildberries', 'ozon'])
            ->when($this->option('stale-only'), fn ($q) => $q->syncable()->has('products'))
            ->when($this->option('integration'), fn ($q) => $q->whereIn('id', $this->option('integration')))
            ->orderBy('id')->get()->filter(fn ($integration) => $coordinator->enabled((int) $integration->id))
            ->map(fn ($integration) => ['integration' => $integration, 'run' => $coordinator->latest((int) $integration->id)])
            ->sortBy(fn ($candidate) => strtotime($candidate['run']?->stages['prices']['queued_at'] ?? '1970-01-01'));
        $eligible = [];
        foreach ($candidates as $candidate) {
            $integration = $candidate['integration'];
            $run = $candidate['run'];
            $priceStage = $run?->stages['prices'] ?? [];
            if ($this->option('stale-only')) {
                // Automatic work cannot repeatedly requeue unresolved missing SKUs,
                // or bypass the external cooldown of an existing request.
                // A long external cooldown can finish much later than admission.
                // Start the automatic interval from the last real response then.
                $lastRequest = $priceStage['response_received_at'] ?? $priceStage['queued_at'] ?? null;
                if ($lastRequest && CarbonImmutable::parse($lastRequest)->greaterThan(now()->subMinutes(30))) {
                    continue;
                }
                if (in_array($priceStage['status'] ?? '', ['queued', 'running', 'waiting_external'], true)
                    && ! in_array($run?->status, ['completed', 'partial', 'failed', 'superseded'], true)) {
                    continue;
                }
            }
            $eligible[] = ['integration_id' => $integration->id, 'marketplace' => $integration->marketplace, 'action' => 'refresh_prices'];
            if ($this->option('apply')) {
                $coordinator->start($integration);
            }
            if (count($eligible) >= $limit) {
                break;
            }
        }
        $this->line(json_encode(['applied' => (bool) $this->option('apply'), 'actions' => $eligible], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function recover(UnitEconomicsSyncCoordinator $coordinator, int $limit): int
    {
        $actions = [];
        $runs = UnitEconomicsSyncRun::whereIn('status', ['queued', 'running', 'waiting_external'])
            ->when($this->option('integration'), fn ($q) => $q->whereIn('integration_id', $this->option('integration')))
            ->orderBy('updated_at')->limit($limit)->get();
        foreach ($runs as $run) {
            if (! $coordinator->enabled((int) $run->integration_id)) {
                continue;
            }
            $latest = $coordinator->latest((int) $run->integration_id);
            if ($latest?->id !== $run->id) {
                continue;
            }
            if ($run->deadline_at->isPast()) {
                $actions[] = ['run_id' => $run->id, 'integration_id' => $run->integration_id, 'action' => 'expire'];
                if ($this->option('apply')) {
                    $coordinator->expire($run->id);
                }

                continue;
            }
            foreach (['prices', 'projection'] as $name) {
                $stage = $run->stages[$name] ?? [];
                if (! in_array($stage['status'] ?? '', ['pending', 'queued', 'running', 'waiting_external'], true)) {
                    continue;
                }
                $retryAt = $stage['next_retry_at'] ?? null;
                if ($retryAt && CarbonImmutable::parse($retryAt)->isFuture()) {
                    continue;
                }
                $heartbeat = $stage['heartbeat_at'] ?? $stage['queued_at'] ?? $run->created_at;
                if (CarbonImmutable::parse($heartbeat)->greaterThan(now()->subMinutes(10))) {
                    continue;
                }
                // A rejected/rate-limited response still has an observation ID,
                // but cannot publish prices. Wait for at least one confirmed SKU.
                if ($name === 'projection' && (empty($stage['observation_id'])
                    || (int) ($run->stages['prices']['succeeded'] ?? 0) === 0)) {
                    continue;
                }
                $actions[] = ['run_id' => $run->id, 'integration_id' => $run->integration_id, 'action' => 'recover_'.$name];
                if ($this->option('apply')) {
                    if ($name === 'prices') {
                        RefreshMarketplacePricesJob::dispatch($run->id, (int) $run->integration_id);
                    } else {
                        ProjectMarketplacePricesJob::dispatch($run->id, (int) $run->integration_id, $run->requested_version,
                            $stage['observation_id'], $stage['cursor'] ?? null);
                    }
                }
            }
        }
        $this->line(json_encode(['applied' => (bool) $this->option('apply'), 'actions' => $actions], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
