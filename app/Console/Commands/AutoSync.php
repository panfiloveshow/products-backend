<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\LimitsSyncService;
use App\Services\ProductService;
use App\Services\SellicoApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoSync extends Command
{
    protected $signature = 'sync:auto {--marketplace= : Конкретный маркетплейс} {--type=products : Тип синхронизации (products/inventory)}';
    protected $description = 'Автоматическая синхронизация всех активных интеграций';

    public function handle(ProductService $productService, LimitsSyncService $limitsSync): int
    {
        $marketplace = $this->option('marketplace');
        $syncType = $this->option('type');
        
        $this->info("Запуск автоматической синхронизации ({$syncType})...");
        
        // Проверяем существует ли таблица integrations
        if (!\Illuminate\Support\Facades\Schema::hasTable('integrations')) {
            $this->warn('Таблица integrations не существует. Запустите миграции: php artisan migrate');
            return self::FAILURE;
        }
        
        // Получаем все активные интеграции с включённой автосинхронизацией
        $query = Integration::active()->autoSyncEnabled();
        
        if ($marketplace) {
            $query->marketplace($marketplace);
        }
        
        $integrations = $query->get();
        
        if ($integrations->isEmpty()) {
            $this->warn('Нет активных интеграций с включённой автосинхронизацией');
            return self::SUCCESS;
        }
        
        $started = 0;
        $skipped = 0;
        
        foreach ($integrations as $integration) {
            // Проверяем нужна ли синхронизация (по интервалу)
            if (!$integration->needsSync()) {
                $this->line("  ⏭️  {$integration->name} ({$integration->marketplace}): не требуется");
                $skipped++;
                continue;
            }
            
            // Проверяем нет ли уже запущенной синхронизации.
            // Fix H12: ранее зависший «running» блокировал автосинк навсегда.
            // Теперь stale-таймаут 2 часа: если running больше 2ч — считаем его зомби,
            // помечаем failed и стартуем новый прогон.
            $staleRunning = SyncLog::where('integration_id', $integration->id)
                ->where('sync_type', $syncType)
                ->running()
                ->where('started_at', '<', now()->subHours(2))
                ->get();

            if ($staleRunning->isNotEmpty()) {
                foreach ($staleRunning as $stale) {
                    $stale->update([
                        'status' => 'failed',
                        'finished_at' => now(),
                        'error_message' => 'Auto-marked failed: running > 2 hours (stale lock)',
                    ]);
                }
                Log::warning('AutoSync: stale running sync_logs marked failed', [
                    'integration_id' => $integration->id,
                    'count' => $staleRunning->count(),
                ]);
            }

            $running = SyncLog::where('integration_id', $integration->id)
                ->where('sync_type', $syncType)
                ->running()
                ->where('started_at', '>=', now()->subHours(2))
                ->exists();

            if ($running) {
                $this->line("  ⏭️  {$integration->name} ({$integration->marketplace}): уже запущена");
                $skipped++;
                continue;
            }
            
            try {
                // BUG FIX: Integration.credentials часто null — получаем из Sellico API
                $credentials = $integration->getDecryptedCredentials();
                if (empty($credentials)) {
                    $sellicoApi = app(SellicoApiService::class);
                    $sellicoResult = $sellicoApi->getIntegrationById($integration->id);
                    if ($sellicoResult['success'] && !empty($sellicoResult['credentials'])) {
                        $credentials = $sellicoResult['credentials'];
                    }
                }

                // Yandex: подставляем campaign_id из client_id если не задан
                if (in_array($integration->marketplace, ['yandex', 'yandex_market'], true)) {
                    if (empty($credentials['campaign_id'] ?? null) && !empty($credentials['client_id'] ?? null)) {
                        $credentials['campaign_id'] = $credentials['client_id'];
                    }
                }

                if (empty($credentials)) {
                    $this->warn("  ⚠️  {$integration->name}: credentials не найдены");
                    $skipped++;
                    continue;
                }

                if ($syncType === 'products' && (int) ($integration->work_space_id ?? 0) > 0) {
                    $limitCheck = $limitsSync->ensureLimitAvailable((int) $integration->work_space_id, 'products', 1);
                    if (! ($limitCheck['success'] ?? false)) {
                        $this->warn(
                            "  ⚠️  {$integration->name}: лимит товаров исчерпан "
                            ."({$limitCheck['current_value']}/{$limitCheck['limit']})"
                        );
                        $skipped++;
                        continue;
                    }
                }

                $syncLog = $productService->startSync(
                    $integration->marketplace,
                    $credentials,
                    $integration->id,
                    $syncType
                );
                
                // Обновляем статус интеграции
                $integration->updateSyncStatus('running');
                
                $this->line("  ✅ {$integration->name} ({$integration->marketplace}): запущена");
                $started++;
                
            } catch (\Exception $e) {
                $integration->updateSyncStatus('failed', $e->getMessage());
                
                $this->error("  ❌ {$integration->name} ({$integration->marketplace}): " . $e->getMessage());
                Log::error('AutoSync failed', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $this->newLine();
        $this->info("Запущено: {$started}, пропущено: {$skipped}");
        
        return self::SUCCESS;
    }
}
