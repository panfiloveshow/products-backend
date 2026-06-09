<?php

namespace App\Console\Commands;

use App\Jobs\RecalculateUnitEconomicsCacheJob;
use App\Models\Integration;
use App\Services\Wildberries\WildberriesTariffRefresher;
use Illuminate\Console\Command;

/**
 * Обновляет тарифы складов WB (КС) для всех активных WB-интеграций.
 *
 * Запускается по расписанию (см. routes/console.php), чтобы КС не замораживался
 * между ручными синхронизациями. Нагрузка минимальна: ~1 набор tariffs/box-запросов
 * + 1 inventory-запрос на интеграцию в сутки.
 *
 * Примеры:
 *   php artisan wb:refresh-tariffs                  # все активные WB-интеграции
 *   php artisan wb:refresh-tariffs --integration=76 # только интеграция 76
 *   php artisan wb:refresh-tariffs --no-recalc      # без пересчёта кэша
 */
class RefreshWildberriesTariffsCommand extends Command
{
    protected $signature = 'wb:refresh-tariffs
        {--integration= : ID конкретной WB-интеграции (по умолчанию — все активные)}
        {--no-recalc : Не пересобирать кэш юнит-экономики после обновления}';

    protected $description = 'Обновляет КС складов WB (box-снапшоты + inventory_warehouses) и пересобирает кэш юнит-экономики';

    public function handle(WildberriesTariffRefresher $refresher): int
    {
        $query = Integration::query()->active()->where('marketplace', 'wildberries');

        if ($id = $this->option('integration')) {
            $query->where('id', (int) $id);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->warn('Активных WB-интеграций не найдено.');

            return self::SUCCESS;
        }

        $this->info("Обновление КС для {$integrations->count()} WB-интеграций…");

        foreach ($integrations as $integration) {
            $result = $refresher->refresh($integration);

            $this->line(sprintf(
                '  #%d %s: снапшотов %d, складов %d',
                $integration->id,
                $integration->name ?? '',
                $result['snapshots'],
                $result['warehouses'],
            ));

            // Пересобираем кэш, чтобы свежий КС попал на страницу юнит-экономики.
            if (! $this->option('no-recalc') && ($result['snapshots'] > 0 || $result['warehouses'] > 0)) {
                RecalculateUnitEconomicsCacheJob::dispatch($integration->id)->onQueue('unit-economics');
            }
        }

        $this->info('Готово.');

        return self::SUCCESS;
    }
}
