<?php

namespace App\Console\Commands;

use App\Services\Manager\ManagerOzonPackingService;
use Illuminate\Console\Command;

final class DispatchManagerOzonPackingGroups extends Command
{
    protected $signature = 'manager:dispatch-ozon-packing {--limit=50}';
    protected $description = 'Dispatch already personally approved Ozon packing groups from their outbox';
    public function handle(ManagerOzonPackingService $operations): int
    {
        $count = $operations->dispatchPending((int) $this->option('limit'));
        $this->info('Queued approved Ozon packing groups: '.$count);
        return self::SUCCESS;
    }
}
