<?php

namespace App\Console\Commands;

use App\Services\Ozon\OzonSupplySyncService;
use App\Services\PostingService;
use Illuminate\Console\Command;

class SyncOzonOperationalDataCommand extends Command
{
    protected $signature = 'ozon:sync-operational
        {--integration= : ID интеграции Ozon}
        {--date-from= : Дата начала для postings (YYYY-MM-DD)}
        {--status= : Статус postings для FBS sync}
        {--skip-supplies : Не синкать supplies}
        {--skip-postings : Не синкать postings}';

    protected $description = 'Синхронизировать Ozon operational данные: поставки и postings';

    public function handle(OzonSupplySyncService $supplySyncService, PostingService $postingService): int
    {
        $integrationId = (int) $this->option('integration');
        if ($integrationId <= 0) {
            $this->error('Нужно передать --integration=');
            return self::FAILURE;
        }

        if (! $this->option('skip-supplies')) {
            $this->info("Синхронизация Ozon supplies для integration_id={$integrationId}...");
            $suppliesResult = $supplySyncService->syncForIntegration($integrationId);
            $this->line(json_encode($suppliesResult, JSON_UNESCAPED_UNICODE));
        }

        if (! $this->option('skip-postings')) {
            $this->info("Синхронизация Ozon postings для integration_id={$integrationId}...");
            $postingsResult = $postingService->sync(
                (string) $integrationId,
                $this->option('status') ? (string) $this->option('status') : null,
                $this->option('date-from') ? (string) $this->option('date-from') : null
            );
            $this->line(json_encode($postingsResult, JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
