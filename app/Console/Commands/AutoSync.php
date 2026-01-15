<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\SyncLog;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoSync extends Command
{
    protected $signature = 'sync:auto {--marketplace= : Конкретный маркетплейс} {--type=products : Тип синхронизации (products/inventory)}';
    protected $description = 'Автоматическая синхронизация всех активных интеграций';

    public function handle(ProductService $productService): int
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
            
            // Проверяем нет ли уже запущенной синхронизации
            $running = SyncLog::where('integration_id', $integration->id)
                ->where('sync_type', $syncType)
                ->running()
                ->exists();
            
            if ($running) {
                $this->line("  ⏭️  {$integration->name} ({$integration->marketplace}): уже запущена");
                $skipped++;
                continue;
            }
            
            try {
                $credentials = $integration->getDecryptedCredentials();
                
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
