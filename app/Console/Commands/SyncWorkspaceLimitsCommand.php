<?php

namespace App\Console\Commands;

use App\Services\LimitsSyncService;
use Illuminate\Console\Command;

class SyncWorkspaceLimitsCommand extends Command
{
    protected $signature = 'limits:sync-products {--workspace= : ID workspace для точечной синхронизации}';

    protected $description = 'Синхронизировать абсолютное количество товаров workspace в PlaceSales limits';

    public function handle(LimitsSyncService $limitsSync): int
    {
        $workspaceOption = $this->option('workspace');
        $workspaceIds = $workspaceOption !== null && $workspaceOption !== ''
            ? [(int) $workspaceOption]
            : $limitsSync->workspaceIdsWithProductIntegrations();

        if ($workspaceIds === []) {
            $this->info('Нет workspace с товарами для синхронизации.');
            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($workspaceIds as $workspaceId) {
            if ($workspaceId <= 0) {
                $this->warn("Некорректный workspace_id: {$workspaceId}");
                $failed++;
                continue;
            }

            $result = $limitsSync->syncWorkspaceProductsLimit($workspaceId);

            if ($result['success'] ?? false) {
                $this->info("workspace={$workspaceId}: products={$result['current_value']} synced");
                continue;
            }

            $failed++;
            $this->error("workspace={$workspaceId}: sync failed ({$result['error']})");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
