<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Ozon\OzonOrderUnitEconomicsService;
use App\Services\Ozon\OzonSupplyFixationService;
use App\Services\Ozon\OzonSupplySyncService;
use App\Services\PostingService;
use App\Services\UnitEconomicsService;
use App\Services\UnitEconomicsSynchronizer;
use Illuminate\Console\Command;

class SyncUnitEconomicsCommand extends Command
{
    protected $signature = 'unit-economics:sync 
                            {--integration= : Integration ID to sync}
                            {--marketplace= : Marketplace to sync (wildberries, ozon, yandex)}
                            {--all : Sync all integrations}
                            {--enqueue : Queue eligible integration jobs instead of running the full sync in this process}
                            {--skip-cache-dispatch : Do not dispatch cache recalculation after syncing}';

    protected $description = 'Sync unit economics from real product data';

    public function handle(
        UnitEconomicsService $service,
        OzonSupplyFixationService $fixationService,
        OzonOrderUnitEconomicsService $orderUnitEconomicsService,
        OzonSupplySyncService $supplySyncService,
        PostingService $postingService
    ): int {
        $integrationId = $this->option('integration');
        $marketplace = $this->option('marketplace');
        $syncAll = $this->option('all');

        if ($this->option('enqueue')) {
            return $this->enqueueSync($integrationId, $marketplace, (bool) $syncAll);
        }

        if ($syncAll) {
            return $this->syncAll($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService);
        }

        if ($integrationId) {
            return $this->syncByIntegrationId($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService, (int) $integrationId);
        }

        if ($marketplace) {
            return $this->syncByMarketplace($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService, $marketplace);
        }

        $this->error('Please specify --integration=ID, --marketplace=NAME, or --all');

        return 1;
    }

    private function enqueueSync(?string $integrationId, ?string $marketplace, bool $all): int
    {
        if (! $all && ! $integrationId && ! $marketplace) {
            $this->error('Please specify --integration=ID, --marketplace=NAME, or --all');

            return self::FAILURE;
        }
        $integrations = \App\Models\Integration::where('is_active', true)->syncable()
            ->whereHas('products', fn ($q) => $q->where('price', '>', 0))
            ->when($integrationId, fn ($q) => $q->whereKey((int) $integrationId))
            ->when($marketplace, fn ($q) => $q->whereIn('marketplace', $this->marketplaceAliases($marketplace)))
            ->orderBy('id')->get();
        $coordinator = app(\App\Services\UnitEconomics\UnitEconomicsSyncCoordinator::class);
        $queued = [];
        foreach ($integrations as $integration) {
            $run = in_array($integration->marketplace, ['wildberries', 'ozon'], true) && $coordinator->enabled((int) $integration->id)
                ? $coordinator->start($integration, 'prices', ['prices', 'projection', 'enrichment']) : null;
            if ($run) {
                $coordinator->updateStage($run->id, 'enrichment', 'queued', ['available_at' => now()->utc()->toIso8601String()]);
            }
            \App\Jobs\SyncUnitEconomicsJob::dispatch((int) $integration->id, unitEconomicsRunId: $run?->id)
                ->onQueue(config('unit_economics_sync.enrichment_queue', 'unit-economics-enrichment'));
            $queued[] = ['integration_id' => $integration->id, 'run_id' => $run?->id];
        }
        $this->line(json_encode(['status' => 'queued', 'integrations' => $queued], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function syncAll(
        UnitEconomicsService $service,
        OzonSupplyFixationService $fixationService,
        OzonOrderUnitEconomicsService $orderUnitEconomicsService,
        OzonSupplySyncService $supplySyncService,
        PostingService $postingService
    ): int {
        $integrations = Product::select('integration_id', 'marketplace')
            ->whereNotNull('integration_id')
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->groupBy('integration_id', 'marketplace')
            ->get();

        $this->info("Found {$integrations->count()} integrations to sync");

        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($integrations as $int) {
            $this->line("Syncing integration_id={$int->integration_id} ({$int->marketplace})...");

            $result = $this->syncProducts($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService, $int->integration_id, $int->marketplace);

            $totalSynced += $result['synced'];
            $totalErrors += $result['errors'] + ($result['source_errors'] ?? 0);

            $this->info("  Synced: {$result['synced']}, Errors: {$result['errors']}");
        }

        $this->newLine();
        $this->info("Total synced: {$totalSynced}, Total errors: {$totalErrors}");

        return $totalErrors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function syncByIntegrationId(
        UnitEconomicsService $service,
        OzonSupplyFixationService $fixationService,
        OzonOrderUnitEconomicsService $orderUnitEconomicsService,
        OzonSupplySyncService $supplySyncService,
        PostingService $postingService,
        int $integrationId
    ): int {
        $marketplace = Product::where('integration_id', $integrationId)->value('marketplace');

        if (! $marketplace) {
            $this->error("No products found for integration_id={$integrationId}");

            return 1;
        }

        if ($marketplace === 'yandex') {
            $marketplace = 'yandex_market';
        }

        $this->info("Syncing integration_id={$integrationId} ({$marketplace})...");

        $result = $this->syncProducts($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService, $integrationId, $marketplace);

        $this->info("Synced: {$result['synced']}, Errors: {$result['errors']}");

        return $result['errors'] === 0 && ($result['source_errors'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function syncByMarketplace(
        UnitEconomicsService $service,
        OzonSupplyFixationService $fixationService,
        OzonOrderUnitEconomicsService $orderUnitEconomicsService,
        OzonSupplySyncService $supplySyncService,
        PostingService $postingService,
        string $marketplace
    ): int {
        if ($marketplace === 'yandex') {
            $marketplace = 'yandex_market';
        }

        $marketplaceAliases = $this->marketplaceAliases($marketplace);

        $integrations = Product::whereIn('marketplace', $marketplaceAliases)
            ->whereNotNull('integration_id')
            ->select('integration_id')
            ->distinct()
            ->pluck('integration_id');

        if ($integrations->isEmpty()) {
            $this->error("No integrations found for marketplace={$marketplace}");

            return 1;
        }

        $this->info("Found {$integrations->count()} integrations for {$marketplace}");

        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($integrations as $integrationId) {
            $this->line("Syncing integration_id={$integrationId}...");

            $result = $this->syncProducts($service, $fixationService, $orderUnitEconomicsService, $supplySyncService, $postingService, $integrationId, $marketplace);

            $totalSynced += $result['synced'];
            $totalErrors += $result['errors'] + ($result['source_errors'] ?? 0);
        }

        $this->info("Total synced: {$totalSynced}, Total errors: {$totalErrors}");

        return $totalErrors === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function syncProducts(
        UnitEconomicsService $service,
        OzonSupplyFixationService $fixationService,
        OzonOrderUnitEconomicsService $orderUnitEconomicsService,
        OzonSupplySyncService $supplySyncService,
        PostingService $postingService,
        int $integrationId,
        string $marketplace
    ): array {
        return app(UnitEconomicsSynchronizer::class)->syncProducts(
            $service, $fixationService, $orderUnitEconomicsService, $supplySyncService,
            $postingService, $integrationId, $marketplace, $this,
            (bool) $this->option('skip-cache-dispatch'),
        );
    }

    private function marketplaceAliases(string $marketplace): array
    {
        return in_array($marketplace, ['yandex', 'yandex_market'], true)
            ? ['yandex', 'yandex_market'] : [$marketplace];
    }
}
