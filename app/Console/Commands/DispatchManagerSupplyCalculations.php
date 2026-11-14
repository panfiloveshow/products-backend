<?php

namespace App\Console\Commands;

use App\Services\Manager\ManagerSupplyCalculationService;
use Illuminate\Console\Command;

final class DispatchManagerSupplyCalculations extends Command
{
    protected $signature = 'manager:supply-calculations:dispatch {--limit=50}';
    protected $description = 'Dispatch approved stored calculation requests; never create another plan';

    public function handle(ManagerSupplyCalculationService $operations): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) { $this->error('limit must be between 1 and 100'); return self::INVALID; }
        $this->info('Queue acknowledgements: '.$operations->dispatchPending($limit));

        return self::SUCCESS;
    }
}
