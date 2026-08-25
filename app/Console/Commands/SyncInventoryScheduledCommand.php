<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Регулярно обновляет остатки и продажи ПО СКЛАДАМ из API маркетплейса
 * (Ozon: FBO+FBS остатки + /v1/analytics/data продажи → inventory_warehouses),
 * заменяя ручную загрузку CSV-отчёта Ozon в разделе автопланирования.
 *
 * На проде запускается системным cron (/etc/cron.d/inventory-sync), т.к.
 * Laravel schedule:run здесь не настроен (см. routes/console.php). Также
 * зарегистрирована в routes/console.php под флагом INVENTORY_SYNC_SCHEDULE.
 *
 * Дедупликация и троттлинг — на стороне InventoryService::startSync
 * (SyncStartGuard: lock + пропуск, если синк уже идёт). Джоб уходит в очередь.
 *
 * Примеры:
 *   php artisan inventory:sync-scheduled                       # все активные интеграции
 *   php artisan inventory:sync-scheduled --integration=13      # только интеграция 13
 *   php artisan inventory:sync-scheduled --marketplace=ozon    # только Ozon
 */
class SyncInventoryScheduledCommand extends Command
{
    protected $signature = 'inventory:sync-scheduled
        {--integration= : ID конкретной интеграции (по умолчанию — все активные)}
        {--marketplace= : Фильтр по маркетплейсу (ozon|wildberries)}
        {--stagger=30 : Задержка между постановками в очередь, секунд (бережём API МП)}';

    protected $description = 'Обновляет остатки и продажи по складам из API в inventory_warehouses (замена ручного CSV-отчёта)';

    /** ponytail: площадки, где SyncInventoryJob умеет per-warehouse остатки+продажи. Yandex добавить здесь, когда понадобится. */
    private const SUPPORTED = ['ozon', 'wildberries'];

    public function handle(InventoryService $service): int
    {
        $marketplace = $this->option('marketplace');
        if ($marketplace && ! in_array($marketplace, self::SUPPORTED, true)) {
            $this->warn("Маркетплейс «{$marketplace}» не поддержан. Поддержано: " . implode(', ', self::SUPPORTED));

            return self::SUCCESS;
        }

        $query = Integration::query()->syncable()->whereIn('marketplace', self::SUPPORTED);
        if ($id = $this->option('integration')) {
            $query->where('id', (int) $id);
        }
        if ($marketplace) {
            $query->where('marketplace', $marketplace);
        }

        $integrations = $query->get();
        if ($integrations->isEmpty()) {
            $this->info('Нет активных интеграций для синка остатков.');

            return self::SUCCESS;
        }

        $stagger = max(0, (int) $this->option('stagger'));
        $queued = 0;
        $i = 0;

        foreach ($integrations as $integration) {
            try {
                // Ключи берём из самой интеграции (как OzonMarketplace::fromIntegration).
                // Пустой массив НЕЛЬЗЯ: фабрика отдаёт их клиенту как есть → 401 Client-Id empty.
                $syncLog = $service->startSync(
                    $integration->marketplace,
                    $integration->getDecryptedCredentials(),
                    $integration->id,
                    $stagger > 0 ? $i * $stagger : null
                );

                $this->line(sprintf(
                    '[%s] integration=%s sync_log=%s status=%s',
                    $integration->marketplace,
                    $integration->id,
                    $syncLog->id,
                    $syncLog->status,
                ));
                $queued++;
                $i++;
            } catch (Throwable $e) {
                $this->error("integration={$integration->id}: {$e->getMessage()}");
                Log::error('inventory:sync-scheduled integration failed', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Поставлено в очередь синков: {$queued} из {$integrations->count()}.");

        return self::SUCCESS;
    }
}
