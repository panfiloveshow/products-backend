<?php

namespace App\Console\Commands;

use App\Models\InventoryHistory;
use App\Models\InventoryWarehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SnapshotInventory extends Command
{
    protected $signature = 'inventory:snapshot {--marketplace= : Конкретный маркетплейс}';
    protected $description = 'Создаёт ежедневный снимок остатков для графиков динамики';

    public function handle(): int
    {
        $marketplace = $this->option('marketplace');
        $today = now()->toDateString();
        
        $this->info("Creating inventory snapshot for {$today}...");
        
        $query = InventoryWarehouse::query()
            ->select('sku', 'warehouse_id', 'marketplace', 'quantity', 'sales_30_days');
        
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }
        
        $saved = 0;
        $errors = 0;
        
        $query->chunk(500, function ($warehouses) use ($today, &$saved, &$errors) {
            foreach ($warehouses as $wh) {
                try {
                    InventoryHistory::updateOrCreate(
                        [
                            'sku' => $wh->sku,
                            'warehouse_id' => $wh->warehouse_id,
                            'date' => $today,
                        ],
                        [
                            'quantity' => $wh->quantity,
                            'sales' => $wh->sales_30_days ? (int) round($wh->sales_30_days / 30) : 0,
                        ]
                    );
                    $saved++;
                } catch (\Exception $e) {
                    $errors++;
                    Log::warning("Failed to save inventory history", [
                        'sku' => $wh->sku,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
        
        $this->info("Saved {$saved} records, {$errors} errors");
        
        Log::info("Inventory snapshot completed", [
            'date' => $today,
            'marketplace' => $marketplace ?? 'all',
            'saved' => $saved,
            'errors' => $errors,
        ]);
        
        return self::SUCCESS;
    }
}
