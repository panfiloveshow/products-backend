<?php

namespace App\Console\Commands;

use App\Models\UnitEconomics;
use App\Models\UnitEconomicsSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Одноразовая миграция: переносит cost_price из unit_economics в unit_economics_settings
 * 
 * Проблема: себестоимость загруженная через файл сохранялась только в unit_economics,
 * но при пересчёте кэша UE берёт cost_price из unit_economics_settings (приоритет).
 * Из-за этого себестоимость терялась при пересчёте.
 */
class MigrateCostPriceToSettings extends Command
{
    protected $signature = 'unit-economics:migrate-cost-price {--dry-run : Только показать что будет перенесено}';
    protected $description = 'Переносит cost_price из unit_economics в unit_economics_settings (одноразовая миграция)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        
        // Находим все записи UnitEconomics с cost_price > 0, у которых нет записи в settings
        // или в settings cost_price = 0
        $records = DB::select("
            SELECT DISTINCT ue.integration_id, ue.sku, ue.cost_price
            FROM unit_economics ue
            LEFT JOIN unit_economics_settings ues 
                ON ues.integration_id = ue.integration_id AND ues.sku = ue.sku
            WHERE ue.cost_price > 0
              AND (ues.id IS NULL OR ues.cost_price IS NULL OR ues.cost_price = 0)
        ");
        
        $this->info("Найдено записей для миграции: " . count($records));
        
        if (empty($records)) {
            $this->info("Нечего мигрировать.");
            return 0;
        }
        
        if ($dryRun) {
            $this->table(
                ['integration_id', 'sku', 'cost_price'],
                array_map(fn($r) => [$r->integration_id, $r->sku, $r->cost_price], $records)
            );
            $this->warn("Dry run — изменения не применены.");
            return 0;
        }
        
        $migrated = 0;
        $errors = 0;
        
        foreach ($records as $record) {
            try {
                UnitEconomicsSettings::updateOrCreate(
                    [
                        'integration_id' => $record->integration_id,
                        'sku' => $record->sku,
                    ],
                    [
                        'cost_price' => $record->cost_price,
                    ]
                );
                $migrated++;
            } catch (\Exception $e) {
                $errors++;
                $this->error("Ошибка для SKU {$record->sku}: {$e->getMessage()}");
            }
        }
        
        $this->info("Мигрировано: {$migrated}, ошибок: {$errors}");
        
        Log::info('MigrateCostPriceToSettings completed', [
            'total' => count($records),
            'migrated' => $migrated,
            'errors' => $errors,
        ]);
        
        // Предлагаем пересчитать кэш
        if ($migrated > 0) {
            $integrationIds = collect($records)->pluck('integration_id')->unique();
            $this->info("Затронутые интеграции: " . $integrationIds->implode(', '));
            $this->info("Для пересчёта кэша запустите:");
            foreach ($integrationIds as $id) {
                $this->line("  php artisan tinker --execute=\"App\\Jobs\\RecalculateUnitEconomicsCacheJob::dispatch({$id})\"");
            }
        }
        
        return 0;
    }
}
