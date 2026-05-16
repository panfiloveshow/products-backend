<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\UnitEconomicsCache;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Console\Command;

class FixOzonNoSalesReturnLogistics extends Command
{
    protected $signature = 'unit-economics:fix-ozon-no-sales-returns
                            {--integration= : Limit to a single integration ID}
                            {--limit= : Maximum rows to recalculate}
                            {--dry-run : Only count affected rows}';

    protected $description = 'Recalculate Ozon RFBS/EXPRESS no-sales rows with missing expected return logistics';

    public function handle(UnitEconomicsCacheService $cacheService): int
    {
        $query = UnitEconomicsCache::query()
            ->where('marketplace', 'ozon')
            ->where('redemption_source', 'no_sales_28d')
            ->whereIn('fulfillment_type', ['RFBS', 'EXPRESS'])
            ->where('expected_return_cost', 0);

        if ($this->option('integration')) {
            $query->where('integration_id', (int) $this->option('integration'));
        }

        $total = (clone $query)->count();
        $this->info("Affected rows: {$total}");

        if ($this->option('dry-run') || $total === 0) {
            return self::SUCCESS;
        }

        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $processed = 0;
        $errors = 0;

        $query->select(['id', 'integration_id', 'product_id', 'sku', 'fulfillment_type'])
            ->orderBy('id')
            ->lazyById(200)
            ->each(function (UnitEconomicsCache $row) use ($cacheService, $limit, &$processed, &$errors) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }

                $product = $row->product_id
                    ? Product::find($row->product_id)
                    : Product::where('integration_id', $row->integration_id)
                        ->where('sku', $row->sku)
                        ->first();

                if (! $product) {
                    $errors++;
                    $this->warn("Product not found for sku={$row->sku}, integration_id={$row->integration_id}");

                    return null;
                }

                try {
                    $cacheService->calculateAndCache($product, $row->fulfillment_type);
                    $processed++;

                    if ($processed % 200 === 0) {
                        $this->line("Processed {$processed} rows...");
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("Failed sku={$row->sku}, scheme={$row->fulfillment_type}: {$e->getMessage()}");
                }

                return null;
            });

        $this->info("Done. Processed={$processed}, errors={$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
