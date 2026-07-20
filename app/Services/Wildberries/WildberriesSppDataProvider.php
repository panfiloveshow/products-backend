<?php

namespace App\Services\Wildberries;

use App\Domains\Wildberries\WildberriesMarketplace;
use App\Models\Integration;
use Illuminate\Support\Collection;

class WildberriesSppDataProvider
{
    /**
     * @return array{
     *   report_available:bool,
     *   report_source:?string,
     *   spp_by_sku:array<string,float>,
     *   spp_by_nm_id:array<string,float>,
     *   card_spp_by_nm_id:array<string,float>
     * }
     */
    public function fetch(Integration $integration, Collection $products, int $attempt = 1): array
    {
        $marketplace = WildberriesMarketplace::fromIntegration($integration);

        // One strict Statistics request per attempt. The shared client cooldown
        // prevents this job, localization and full UE sync from colliding.
        // This targeted sync deliberately uses orders on every attempt: a new
        // order contains SPP before it can appear in the completed sales report.
        // Repeating sales here could finish a retry with incomplete coverage.
        $reportSource = 'orders';
        $report = $marketplace->getOrdersReport(30);

        $reportAvailable = is_array($report);
        $sppMaps = $reportAvailable
            ? $marketplace->buildSppMapsFromReport($report)
            : ['by_sku' => [], 'by_nm_id' => []];

        $nmIds = $products
            ->map(fn ($product) => $this->nmId($product->wb_data ?? []))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // products.price is already the current size-specific seller price.
        // Calling Prices API on every report retry adds no value for the common
        // path and was independently exhausting WB's global seller limiter.
        $sellerPrices = $products->reduce(function (array $result, $product): array {
            $nmId = $this->nmId($product->wb_data ?? []);
            $price = (float) ($product->price ?? 0);
            if ($nmId !== null && $price > 0 && ! array_key_exists($nmId, $result)) {
                $result[$nmId] = $price;
            }

            return $result;
        }, []);

        return [
            'report_available' => $reportAvailable,
            'report_source' => $reportAvailable ? $reportSource : null,
            'spp_by_sku' => $sppMaps['by_sku'],
            'spp_by_nm_id' => $sppMaps['by_nm_id'],
            'card_spp_by_nm_id' => $marketplace->getDisplayedSppByNmIds($nmIds, $sellerPrices),
        ];
    }

    private function nmId(array $wbData): ?string
    {
        $value = $wbData['nmID'] ?? $wbData['nmId'] ?? null;

        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
