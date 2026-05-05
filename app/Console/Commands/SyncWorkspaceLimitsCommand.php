<?php

namespace App\Console\Commands;

use App\Services\LimitsSyncService;
use Illuminate\Console\Command;

class SyncWorkspaceLimitsCommand extends Command
{
    protected $signature = 'limits:sync-products {--workspace= : ID workspace для точечной синхронизации}';

    protected $description = 'Синхронизировать абсолютные значения внешних лимитов workspace в PlaceSales limits';

    public function handle(LimitsSyncService $limitsSync): int
    {
        $workspaceOption = $this->option('workspace');
        $workspaceIds = $workspaceOption !== null && $workspaceOption !== ''
            ? [(int) $workspaceOption]
            : $limitsSync->workspaceIdsWithLimitUsage();

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

            $productsResult = $limitsSync->syncWorkspaceProductsLimit($workspaceId);
            $autoplanningResult = $limitsSync->syncWorkspaceAutoplanningLimit($workspaceId);

            if (($productsResult['success'] ?? false) && ($autoplanningResult['success'] ?? false)) {
                $this->info(
                    "workspace={$workspaceId}: products={$productsResult['current_value']} synced; "
                    ."autoplanning={$autoplanningResult['current_value']} synced"
                );
                continue;
            }

            $failed++;
            $errors = array_filter([
                $productsResult['success'] ?? false ? null : 'products='.($productsResult['error'] ?? 'unknown error'),
                $autoplanningResult['success'] ?? false ? null : 'autoplanning='.($autoplanningResult['error'] ?? 'unknown error'),
            ]);
            $this->error("workspace={$workspaceId}: sync failed (".implode('; ', $errors).')');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
