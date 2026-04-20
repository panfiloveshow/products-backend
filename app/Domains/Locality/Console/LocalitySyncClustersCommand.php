<?php

namespace App\Domains\Locality\Console;

use App\Domains\Locality\Ingestion\OzonClusterMapSyncer;
use App\Models\Integration;
use Illuminate\Console\Command;

class LocalitySyncClustersCommand extends Command
{
    protected $signature = 'locality:sync-clusters {--integration= : Integration ID (если не указан — все активные Ozon)}';

    protected $description = 'Обновить справочник кластеров Ozon через /v1/cluster/list';

    public function handle(OzonClusterMapSyncer $syncer): int
    {
        $integrationId = $this->option('integration');

        $query = Integration::query()->where('is_active', true)->where('marketplace', 'ozon');
        if ($integrationId !== null) {
            $query->where('id', (int) $integrationId);
        }

        $integrations = $query->get();
        if ($integrations->isEmpty()) {
            $this->warn('Нет активных Ozon-интеграций.');
            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            $this->info("Синхронизирую кластеры для integration_id={$integration->id}...");
            $result = $syncer->syncForIntegration($integration);
            $this->line(sprintf(
                '  inserted=%d updated=%d skipped=%d',
                $result->inserted,
                $result->updated,
                $result->skipped
            ));
        }

        return self::SUCCESS;
    }
}
