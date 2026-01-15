<?php

namespace App\Console\Commands;

use App\Models\InventoryHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupInventoryHistory extends Command
{
    protected $signature = 'inventory:cleanup {--days=90 : Хранить данные за последние N дней}';
    protected $description = 'Удаляет старые записи истории остатков';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->subDays($days)->toDateString();
        
        $this->info("Удаление записей истории старше {$days} дней (до {$cutoffDate})...");
        
        $deleted = InventoryHistory::where('date', '<', $cutoffDate)->delete();
        
        $this->info("Удалено {$deleted} записей");
        
        Log::info("Inventory history cleanup completed", [
            'days_kept' => $days,
            'cutoff_date' => $cutoffDate,
            'deleted' => $deleted,
        ]);
        
        return self::SUCCESS;
    }
}
