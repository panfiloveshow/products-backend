<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use Illuminate\Console\Command;

class SyncStatus extends Command
{
    protected $signature = 'sync:status {--days=1 : Количество дней для отображения}';
    protected $description = 'Показывает статус синхронизаций по маркетплейсам';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $since = now()->subDays($days);
        
        $this->info("Статус синхронизаций за последние {$days} дн.");
        $this->newLine();
        
        // Статистика по маркетплейсам
        $stats = SyncLog::where('created_at', '>=', $since)
            ->selectRaw('marketplace, sync_type, status, COUNT(*) as count')
            ->groupBy('marketplace', 'sync_type', 'status')
            ->get();
        
        $marketplaces = $stats->pluck('marketplace')->unique();
        
        foreach ($marketplaces as $marketplace) {
            $this->info("📦 " . strtoupper($marketplace));
            
            $mpStats = $stats->where('marketplace', $marketplace);
            $syncTypes = $mpStats->pluck('sync_type')->unique();
            
            foreach ($syncTypes as $syncType) {
                $typeStats = $mpStats->where('sync_type', $syncType);
                $completed = $typeStats->where('status', 'completed')->sum('count');
                $failed = $typeStats->where('status', 'failed')->sum('count');
                $running = $typeStats->where('status', 'running')->sum('count');
                
                $status = $failed > 0 ? '❌' : ($running > 0 ? '🔄' : '✅');
                $this->line("  {$status} {$syncType}: {$completed} успешных, {$failed} ошибок, {$running} в процессе");
            }
            
            // Последняя синхронизация
            $lastSync = SyncLog::where('marketplace', $marketplace)
                ->where('status', 'completed')
                ->latest()
                ->first();
            
            if ($lastSync) {
                $ago = $lastSync->updated_at->diffForHumans();
                $this->line("  ⏱️  Последняя: {$ago}");
            }
            
            $this->newLine();
        }
        
        // Предупреждения
        $this->checkWarnings();
        
        return self::SUCCESS;
    }
    
    private function checkWarnings(): void
    {
        $warnings = [];
        
        // Проверяем зависшие синхронизации
        $stuck = SyncLog::where('status', 'running')
            ->where('created_at', '<', now()->subMinutes(30))
            ->count();
        
        if ($stuck > 0) {
            $warnings[] = "⚠️  {$stuck} зависших синхронизаций (>30 мин)";
        }
        
        // Проверяем давно не синхронизировались
        $marketplaces = ['wildberries', 'ozon', 'yandex'];
        foreach ($marketplaces as $mp) {
            $lastSync = SyncLog::where('marketplace', $mp)
                ->where('status', 'completed')
                ->latest()
                ->first();
            
            if (!$lastSync || $lastSync->updated_at < now()->subHours(24)) {
                $warnings[] = "⚠️  {$mp}: не синхронизировался >24ч";
            }
        }
        
        // Проверяем ошибки за последний час
        $recentErrors = SyncLog::where('status', 'failed')
            ->where('created_at', '>=', now()->subHour())
            ->count();
        
        if ($recentErrors > 0) {
            $warnings[] = "🔴 {$recentErrors} ошибок за последний час";
        }
        
        if (!empty($warnings)) {
            $this->warn("Предупреждения:");
            foreach ($warnings as $warning) {
                $this->line("  {$warning}");
            }
        } else {
            $this->info("✅ Все синхронизации работают нормально");
        }
    }
}
