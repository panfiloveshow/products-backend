<?php

namespace App\Services\UnitEconomics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class WildberriesSourceFreshnessService
{
    /**
     * Return only real, persisted WB snapshot timestamps that are fresh enough
     * for a monetary decision. Missing entries mean no fresh evidence exists.
     *
     * @return array{commission:?Carbon,tariff:?Carbon}
     */
    public function freshEvidence(int $integrationId, Carbon $checkedAt, int $maxAgeHours = 72): array
    {
        $cutoff = $checkedAt->copy()->subHours($maxAgeHours);
        $futureLimit = $checkedAt->copy()->addMinutes(5);

        $rows = DB::table('wildberries_tariff_snapshots')
            ->where('integration_id', $integrationId)
            ->whereIn('tariff_type', ['commission', 'box'])
            // The composite lookup index includes effective_date. Restricting it
            // avoids scanning the full multi-year snapshot history on every bid.
            ->where('effective_date', '>=', $cutoff->toDateString())
            ->whereBetween('fetched_at', [$cutoff, $futureLimit])
            ->selectRaw('tariff_type, MAX(fetched_at) AS observed_at')
            ->groupBy('tariff_type')
            ->pluck('observed_at', 'tariff_type');

        return [
            'commission' => $this->parse($rows->get('commission')),
            'tariff' => $this->parse($rows->get('box')),
        ];
    }

    private function parse(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
