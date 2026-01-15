<?php

namespace App\Console\Commands;

use App\Services\TurnoverCalculationService;
use Illuminate\Console\Command;

class RecalculateTurnover extends Command
{
    protected $signature = 'inventory:recalculate-turnover {--marketplace= : Конкретный маркетплейс}';
    protected $description = 'Пересчитывает оборачиваемость с учётом дней наличия товара';

    public function handle(TurnoverCalculationService $service): int
    {
        $marketplace = $this->option('marketplace');
        
        $this->info('Пересчёт оборачиваемости с учётом дней наличия...');
        
        if ($marketplace) {
            $this->info("Маркетплейс: {$marketplace}");
        }
        
        $result = $service->recalculateAll($marketplace);
        
        $this->info("Обновлено: {$result['updated']} записей");
        
        if ($result['errors'] > 0) {
            $this->warn("Ошибок: {$result['errors']}");
        }
        
        return self::SUCCESS;
    }
}
