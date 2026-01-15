<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use Illuminate\Console\Command;

class CleanupStuckSyncs extends Command
{
    protected $signature = 'sync:cleanup {--minutes=30 : Minutes after which a sync is considered stuck}';
    
    protected $description = 'Cleanup stuck synchronizations that have been running for too long';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);
        
        $stuckSyncs = SyncLog::where('status', SyncLog::STATUS_RUNNING)
            ->where('created_at', '<', $threshold)
            ->get();
        
        if ($stuckSyncs->isEmpty()) {
            $this->info('No stuck synchronizations found.');
            return Command::SUCCESS;
        }
        
        $this->info("Found {$stuckSyncs->count()} stuck synchronization(s):");
        
        foreach ($stuckSyncs as $sync) {
            $sync->update([
                'status' => SyncLog::STATUS_FAILED,
                'error_message' => "Timeout - stuck for more than {$minutes} minutes",
                'completed_at' => now(),
            ]);
            
            $this->line("  - {$sync->marketplace} (integration_id: {$sync->integration_id}) - marked as failed");
            
            \Log::warning('Stuck sync cleaned up', [
                'sync_id' => $sync->id,
                'marketplace' => $sync->marketplace,
                'integration_id' => $sync->integration_id,
                'created_at' => $sync->created_at,
            ]);
        }
        
        $this->info("Cleaned up {$stuckSyncs->count()} stuck synchronization(s).");
        
        return Command::SUCCESS;
    }
}
