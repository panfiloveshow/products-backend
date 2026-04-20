<?php

namespace App\Domains\Locality\Ingestion;

use App\Models\OzonOrderUnitEconomics;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Единая точка чтения per-posting-item экономики за период.
 * Все калькуляторы/explain/recommendations идут через этот reader,
 * чтобы не плодить параллельные SQL к одной и той же таблице.
 */
class PostingEnrichmentReader
{
    public function queryForPeriod(int $integrationId, Carbon $from, Carbon $to, ?string $sku = null): Builder
    {
        return OzonOrderUnitEconomics::query()
            ->where('integration_id', $integrationId)
            ->whereBetween('order_date', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->when($sku !== null, fn (Builder $q) => $q->where('sku', $sku));
    }

    public function hasFreshData(int $integrationId, Carbon $asOf): bool
    {
        return OzonOrderUnitEconomics::query()
            ->where('integration_id', $integrationId)
            ->where('updated_at', '>=', $asOf->copy()->subHours(24))
            ->exists();
    }

    public function distinctSkus(int $integrationId, Carbon $from, Carbon $to): array
    {
        return $this->queryForPeriod($integrationId, $from, $to)
            ->whereNotNull('sku')
            ->distinct()
            ->pluck('sku')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}
