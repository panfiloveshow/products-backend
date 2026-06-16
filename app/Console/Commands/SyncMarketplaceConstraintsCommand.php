<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\AutoSupplyPlanning\MarketplaceConstraintSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Синхронизирует ограничения складов МП (доступность приёмки, коэффициенты)
 * из API в MarketplaceConstraintSnapshot — заменяет ручную загрузку файла ограничений.
 *
 * Запускается по расписанию (см. routes/console.php) под флагом MP_CONSTRAINTS_SCHEDULE.
 *
 * Примеры:
 *   php artisan mp:sync-constraints                          # все активные поддержанные интеграции
 *   php artisan mp:sync-constraints --integration=76         # только интеграция 76
 *   php artisan mp:sync-constraints --marketplace=wildberries # только WB
 */
class SyncMarketplaceConstraintsCommand extends Command
{
    protected $signature = 'mp:sync-constraints
        {--integration= : ID конкретной интеграции (по умолчанию — все активные)}
        {--marketplace= : Фильтр по маркетплейсу (ozon|wildberries)}';

    protected $description = 'Синхронизирует лимиты складов и коэффициенты приёмки МП из API в снапшоты ограничений';

    public function handle(MarketplaceConstraintSyncService $service): int
    {
        $supported = $service->supportedMarketplaces();

        if ($marketplace = $this->option('marketplace')) {
            if (! in_array($marketplace, $supported, true)) {
                $this->warn("Маркетплейс «{$marketplace}» пока не поддержан авто-синком ограничений. Поддержано: " . implode(', ', $supported));

                return self::SUCCESS;
            }
        }

        $query = Integration::query()->active()->whereIn('marketplace', $supported);

        if ($id = $this->option('integration')) {
            $query->where('id', (int) $id);
        }
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->info('Нет интеграций для синхронизации ограничений.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($integrations as $integration) {
            try {
                $snapshot = $service->syncIntegration($integration);
                $summary = $snapshot->summary_json ?? [];

                $this->line(sprintf(
                    '[%s] integration=%s status=%s warehouses=%d available=%d blocked=%d',
                    $integration->marketplace,
                    $integration->id,
                    $snapshot->sync_status,
                    (int) ($summary['warehouses_total'] ?? 0),
                    (int) ($summary['warehouses_available'] ?? 0),
                    (int) ($summary['warehouses_blocked'] ?? 0),
                ));

                if ($snapshot->sync_status === 'error') {
                    $failed++;
                    if ($snapshot->sync_error) {
                        $this->warn("  └ {$snapshot->sync_error}");
                    }
                } else {
                    $ok++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("integration={$integration->id}: {$e->getMessage()}");
                Log::error('mp:sync-constraints integration failed', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Готово. Успешно: {$ok}, с ошибкой: {$failed}.");

        return self::SUCCESS;
    }
}
