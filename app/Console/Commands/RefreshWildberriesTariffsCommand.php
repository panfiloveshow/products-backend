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

        // Тарифы WB одинаковы для всех продавцов (склад/категория), поэтому тянем
        // их ОДИН раз — иначе запросы подряд упираются в 429 WB. Источником ключа
        // берём ЛЮБУЮ активную WB-интеграцию (не только отфильтрованную): если у
        // целевой #76 свой ключ в 429, тарифы возьмём с другого — они те же.
        $fetchSources = Integration::query()->active()->where('marketplace', 'wildberries')->get();

        $shared = ['snapshots' => [], 'coefMap' => []];
        foreach ($fetchSources as $candidate) {
            $shared = $refresher->fetchSharedTariffData($candidate);
            if (! empty($shared['snapshots']) || ! empty($shared['coefMap'])) {
                $this->line("  Тарифы получены с интеграции #{$candidate->id}");
                break;
            }
        }

        if (empty($shared['snapshots']) && empty($shared['coefMap'])) {
            $this->error('Не удалось получить тарифы WB ни с одного ключа (вероятно 429). Повторите позже.');

            return self::FAILURE;
        }

        foreach ($integrations as $integration) {
            $result = $refresher->applyShared($integration, $shared['snapshots'], $shared['coefMap']);

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
