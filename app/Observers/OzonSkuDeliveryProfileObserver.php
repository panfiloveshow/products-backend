<?php

namespace App\Observers;

use App\Jobs\RecalculateUnitEconomicsForSkuJob;
use App\Models\OzonSkuDeliveryProfile;

class OzonSkuDeliveryProfileObserver
{
    public function saved(OzonSkuDeliveryProfile $profile): void
    {
        $this->dispatchRecalc($profile);
    }

    public function deleted(OzonSkuDeliveryProfile $profile): void
    {
        $this->dispatchRecalc($profile);
    }

    private function dispatchRecalc(OzonSkuDeliveryProfile $profile): void
    {
        $integrationId = (int) $profile->integration_id;
        $sku = (string) $profile->sku;
        if ($integrationId === 0 || $sku === '') {
            return;
        }

        RecalculateUnitEconomicsForSkuJob::dispatch($integrationId, $sku)->afterCommit();
    }
}
