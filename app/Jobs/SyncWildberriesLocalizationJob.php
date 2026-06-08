<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Services\LocalizationIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Расчёт ИЛ/ИРП Wildberries (за 13 недель / 91 день) с устойчивостью к рейт-лимиту WB.
 *
 * /api/v1/supplier/sales отдаёт 429 (~1 запрос/мин на ключ). Синхронный синк
 * глотал 429 → ИЛ/ИРП «то считались, то нет». Здесь на пустой результат
 * (вероятный троттл) делаем release(90) и повторяем позже, не блокируя воркер.
 * После исчерпания попыток сдаёмся тихо (не падаем в failed_jobs).
 */
class SyncWildberriesLocalizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;   // до ~12 попыток × 90с ≈ 18 мин на пробивание лимита
    public int $timeout = 120;

    public function __construct(public int $integrationId) {}

    public function handle(LocalizationIndexService $localization): void
    {
        $integration = Integration::find($this->integrationId);
        if (! $integration || $integration->marketplace !== 'wildberries') {
            return;
        }

        $result = $localization->calculateLocalizationIndex($integration);
        $orders = (int) ($result['total_orders'] ?? 0);

        if ($orders <= 0) {
            // Пусто — почти наверняка 429 (у активного магазина за 91д продажи есть).
            // Повторяем позже, не блокируя воркер. На последней попытке — сдаёмся тихо.
            if ($this->attempts() < $this->tries) {
                Log::info('WB localization: empty (likely 429 throttle), retry later', [
                    'integration_id' => $this->integrationId,
                    'attempt' => $this->attempts(),
                ]);
                $this->release(90);
            } else {
                Log::warning('WB localization: gave up after retries (no sales data)', [
                    'integration_id' => $this->integrationId,
                ]);
            }

            return;
        }

        $il = (float) ($result['localization_index'] ?? 1.0);
        $irp = (float) ($result['sales_distribution_index'] ?? 0.0);

        $settings = array_merge($integration->settings ?? [], [
            'wb_localization_index' => $il,
            'wb_sales_distribution_index' => $irp,
            'wb_localization_total_orders' => $orders,
        ]);
        $integration->update([
            'localization_index' => $il,
            'localization_checked_at' => now(),
            'settings' => $settings,
        ]);

        Log::info('WB localization saved', [
            'integration_id' => $this->integrationId,
            'il' => $il,
            'irp' => $irp,
            'orders' => $orders,
        ]);

        // Пересобираем кэш, чтобы ИЛ/ИРП попали в юнит-экономику.
        RecalculateUnitEconomicsCacheJob::dispatch($this->integrationId)->onQueue('unit-economics');
    }
}
