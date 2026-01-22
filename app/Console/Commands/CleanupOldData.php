<?php

namespace App\Console\Commands;

use App\Models\InventoryHistory;
use App\Models\SyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupOldData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:old-data 
                            {--history-days=90 : Удалять inventory_history старше N дней}
                            {--sync-days=30 : Удалять sync_logs старше N дней}
                            {--dry-run : Только показать что будет удалено, не удалять}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Очистка старых данных: inventory_history (>90 дней), sync_logs (>30 дней)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $historyDays = (int) $this->option('history-days');
        $syncDays = (int) $this->option('sync-days');
        $dryRun = $this->option('dry-run');
        
        $this->info('=== Очистка старых данных ===');
        $this->info($dryRun ? '(DRY RUN - данные не будут удалены)' : '');
        
        // 1. Очистка inventory_history
        $historyDate = now()->subDays($historyDays);
        $historyCount = InventoryHistory::where('date', '<', $historyDate)->count();
        
        $this->info("inventory_history старше {$historyDays} дней: {$historyCount} записей");
        
        if (!$dryRun && $historyCount > 0) {
            $deleted = InventoryHistory::where('date', '<', $historyDate)->delete();
            $this->info("  Удалено: {$deleted}");
        }
        
        // 2. Очистка sync_logs
        $syncDate = now()->subDays($syncDays);
        $syncCount = SyncLog::where('created_at', '<', $syncDate)->count();
        
        $this->info("sync_logs старше {$syncDays} дней: {$syncCount} записей");
        
        if (!$dryRun && $syncCount > 0) {
            $deleted = SyncLog::where('created_at', '<', $syncDate)->delete();
            $this->info("  Удалено: {$deleted}");
        }
        
        // 3. Очистка кэша
        if (!$dryRun) {
            $this->call('cache:clear');
            $this->info('Кэш очищен');
        }
        
        $this->info('=== Готово ===');
        
        return Command::SUCCESS;
    }
}
