<?php

namespace App\Observers;

use App\Jobs\RecalculateUnitEconomicsForSkuJob;
use App\Models\OzonSupplyFixation;

class OzonSupplyFixationObserver
{
    public function saved(OzonSupplyFixation $fixation): void
    {
        $this->dispatchRecalc($fixation);
    }

    public function deleted(OzonSupplyFixation $fixation): void
    {
        $this->dispatchRecalc($fixation);
    }

    private function dispatchRecalc(OzonSupplyFixation $fixation): void
    {
        $integrationId = (int) $fixation->integration_id;
        $sku = (string) $fixation->sku;
        if ($integrationId === 0 || $sku === '') {
            return;
        }

        RecalculateUnitEconomicsForSkuJob::dispatch($integrationId, $sku)->afterCommit();
    }
}
