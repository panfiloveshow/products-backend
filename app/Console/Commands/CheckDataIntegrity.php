<?php

namespace App\Console\Commands;

use App\Services\DataValidationService;
use Illuminate\Console\Command;

class CheckDataIntegrity extends Command
{
    protected $signature = 'data:check';
    protected $description = 'Проверяет целостность данных и выводит проблемы';

    public function handle(DataValidationService $validator): int
    {
        $this->info('Проверка целостности данных...');
        $this->newLine();
        
        $issues = $validator->checkDataIntegrity();
        
        if (empty($issues)) {
            $this->info('✅ Проблем не обнаружено');
            return self::SUCCESS;
        }
        
        $this->warn('Обнаружены проблемы:');
        
        foreach ($issues as $issue) {
            $icon = $issue['severity'] === 'error' ? '🔴' : '⚠️';
            $this->line("  {$icon} {$issue['type']}: {$issue['count']} записей");
        }
        
        return self::FAILURE;
    }
}
