<?php

namespace App\Console\Commands;

use App\Services\UnitEconomicsCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Массовый сброс ручных оверрайдов % выкупа на дефолт.
 *
 * Появилась после решения «дефолт WB без данных 80 → 50» (2026-08-17): в части
 * магазинов оверрайды 80 были проставлены руками до решения и перекрывали новый
 * дефолт. Сбрасываем только заданное значение (по умолчанию 80), чтобы не
 * задеть осознанные оверрайды с другими цифрами.
 */
class ClearRedemptionOverridesCommand extends Command
{
    protected $signature = 'ue:clear-redemption-overrides
        {integration : ID интеграции}
        {--value=80 : Сбрасывать только оверрайды с этим значением}
        {--dry-run : Показать затронутые SKU без изменений}';

    protected $description = 'Сбросить ручные оверрайды % выкупа (redemption_rate_override) на дефолт и пересчитать кэш ЮЭ';

    public function handle(UnitEconomicsCacheService $cacheService): int
    {
        $integrationId = (int) $this->argument('integration');
        $value = (float) $this->option('value');

        $rows = DB::table('unit_economics_settings')
            ->where('integration_id', $integrationId)
            ->where('redemption_rate_override', $value)
            ->get(['id', 'sku', 'redemption_rate_override', 'updated_at']);

        if ($rows->isEmpty()) {
            $this->info("Оверрайдов со значением {$value} у интеграции {$integrationId} нет.");

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line("  {$row->sku}: {$row->redemption_rate_override} (updated {$row->updated_at})");
        }

        if ($this->option('dry-run')) {
            $this->info("Dry-run: {$rows->count()} оверрайдов осталось без изменений.");

            return self::SUCCESS;
        }

        $updated = DB::table('unit_economics_settings')
            ->where('integration_id', $integrationId)
            ->where('redemption_rate_override', $value)
            ->update(['redemption_rate_override' => null, 'updated_at' => now()]);

        foreach ($rows as $row) {
            $cacheService->onSettingsChanged($integrationId, $row->sku);
        }

        $this->info("Сброшено {$updated} оверрайдов, пересчёт кэша запущен для {$rows->count()} SKU.");

        return self::SUCCESS;
    }
}
