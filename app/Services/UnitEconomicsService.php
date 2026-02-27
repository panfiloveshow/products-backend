<?php

namespace App\Services;

use App\Models\UnitEconomics;
use Illuminate\Support\Facades\Cache;

class UnitEconomicsService
{
    public function calculate(string $marketplace, array $data): array
    {
        $price = $data['price'];
        $costPrice = $data['cost_price'];
        $salesCount = $data['sales_count'] ?? 1;

        $revenue = $price * $salesCount;

        $calculator = match ($marketplace) {
            'wildberries' => $this->calculateWB($data),
            'ozon' => $this->calculateOzon($data),
            'yandex_market' => $this->calculateYandex($data),
            default => throw new \InvalidArgumentException("Unknown marketplace: {$marketplace}"),
        };

        $totalCosts = $costPrice * $salesCount + $calculator['total_fees'];
        $grossProfit = $revenue - $totalCosts;
        $netProfit = $grossProfit - ($data['advertising_cost'] ?? 0);
        $marginPercent = $revenue > 0 ? ($netProfit / $revenue) * 100 : 0;
        $roiPercent = $totalCosts > 0 ? ($netProfit / $totalCosts) * 100 : 0;

        return array_merge([
            'revenue' => round($revenue, 2),
            'total_costs' => round($totalCosts, 2),
            'gross_profit' => round($grossProfit, 2),
            'net_profit' => round($netProfit, 2),
            'margin_percent' => round($marginPercent, 2),
            'roi_percent' => round($roiPercent, 2),
        ], $calculator['details']);
    }

    private function calculateWB(array $data): array
    {
        $price = $data['price'];
        $salesCount = $data['sales_count'] ?? 1;

        $commissionPercent = $data['wb_commission_percent'] ?? 15;
        $commissionAmount = ($price * $commissionPercent / 100) * $salesCount;

        $volumeLiters = $data['volume_liters'] ?? 0;
        $storageTariff = $data['storage_tariff'] ?? 0.5;
        $storageDays = $data['storage_days'] ?? 30;
        $storageCost = $volumeLiters * $storageTariff * $storageDays * $salesCount;

        $logisticsCost = ($data['logistics_cost'] ?? 50) * $salesCount;
        $acceptanceCost = $data['acceptance_cost'] ?? 0;
        $penaltyCost = $data['penalty_cost'] ?? 0;
        $returnLogisticsCost = $data['return_logistics_cost'] ?? 0;

        $sppPercent = $data['spp_percent'] ?? 0;
        $sppRub = ($price * $sppPercent / 100) * $salesCount;

        $ksPercent = $data['ks_percent'] ?? 0;
        $ksRub = ($price * $ksPercent / 100) * $salesCount;

        $totalFees = $commissionAmount + $storageCost + $logisticsCost + 
                     $acceptanceCost + $penaltyCost + $returnLogisticsCost + $sppRub + $ksRub;

        return [
            'total_fees' => $totalFees,
            'details' => [
                'wb_commission_percent' => $commissionPercent,
                'commission_amount' => round($commissionAmount, 2),
                'volume_liters' => $volumeLiters,
                'storage_tariff' => $storageTariff,
                'storage_days' => $storageDays,
                'storage_cost' => round($storageCost, 2),
                'logistics_cost' => round($logisticsCost, 2),
                'acceptance_cost' => round($acceptanceCost, 2),
                'penalty_cost' => round($penaltyCost, 2),
                'return_logistics_cost' => round($returnLogisticsCost, 2),
                'spp_percent' => $sppPercent,
                'spp_rub' => round($sppRub, 2),
                'ks_percent' => $ksPercent,
                'ks_rub' => round($ksRub, 2),
            ],
        ];
    }

    private function calculateOzon(array $data): array
    {
        $price = $data['price'];
        $salesCount = $data['sales_count'] ?? 1;
        $fulfillmentType = $data['fulfillment_type'] ?? 'FBO';

        $commissionPercent = $fulfillmentType === 'FBO' 
            ? ($data['fbo_commission_percent'] ?? 15)
            : ($data['fbs_commission_percent'] ?? 12);
        $commissionAmount = ($price * $commissionPercent / 100) * $salesCount;

        $lastMileCost = ($data['last_mile_cost'] ?? 40) * $salesCount;
        $returnCost = $data['return_cost'] ?? 0;
        $storageCost = $data['storage_cost'] ?? 0;

        $acquiringPercent = $data['acquiring_percent'] ?? 1.5;
        $acquiringAmount = ($price * $acquiringPercent / 100) * $salesCount;

        $packagingCost = ($data['packaging_cost'] ?? 5) * $salesCount;

        $totalFees = $commissionAmount + $lastMileCost + $returnCost + 
                     $storageCost + $acquiringAmount + $packagingCost;

        return [
            'total_fees' => $totalFees,
            'details' => [
                'fulfillment_type' => $fulfillmentType,
                'fbo_commission_percent' => $data['fbo_commission_percent'] ?? 15,
                'fbs_commission_percent' => $data['fbs_commission_percent'] ?? 12,
                'commission_amount' => round($commissionAmount, 2),
                'last_mile_cost' => round($lastMileCost, 2),
                'return_cost' => round($returnCost, 2),
                'storage_cost' => round($storageCost, 2),
                'acquiring_percent' => $acquiringPercent,
                'acquiring_amount' => round($acquiringAmount, 2),
                'packaging_cost' => round($packagingCost, 2),
            ],
        ];
    }

    private function calculateYandex(array $data): array
    {
        $price = $data['price'];
        $salesCount = $data['sales_count'] ?? 1;

        $referralFeePercent = $data['referral_fee_percent'] ?? 5;
        $referralFeeAmount = ($price * $referralFeePercent / 100) * $salesCount;

        $fbyPlacement = ($data['fby_placement'] ?? 0) * $salesCount;
        $fbyPickupTransfer = ($data['fby_pickup_transfer'] ?? 0) * $salesCount;
        $fbyDelivery = ($data['fby_delivery'] ?? 50) * $salesCount;
        $fbyMiddleMile = ($data['fby_middle_mile'] ?? 0) * $salesCount;
        $fbyTotal = $fbyPlacement + $fbyPickupTransfer + $fbyDelivery + $fbyMiddleMile;

        $fbsPlacement = ($data['fbs_placement'] ?? 0) * $salesCount;
        $fbsPickupTransfer = ($data['fbs_pickup_transfer'] ?? 0) * $salesCount;
        $fbsDelivery = ($data['fbs_delivery'] ?? 40) * $salesCount;
        $fbsMiddleMile = ($data['fbs_middle_mile'] ?? 0) * $salesCount;
        $fbsTotal = $fbsPlacement + $fbsPickupTransfer + $fbsDelivery + $fbsMiddleMile;

        $totalFees = $referralFeeAmount + $fbyTotal;

        return [
            'total_fees' => $totalFees,
            'details' => [
                'referral_fee_percent' => $referralFeePercent,
                'referral_fee_amount' => round($referralFeeAmount, 2),
                'fby_placement' => round($fbyPlacement, 2),
                'fby_pickup_transfer' => round($fbyPickupTransfer, 2),
                'fby_delivery' => round($fbyDelivery, 2),
                'fby_middle_mile' => round($fbyMiddleMile, 2),
                'fby_total' => round($fbyTotal, 2),
                'fbs_placement' => round($fbsPlacement, 2),
                'fbs_pickup_transfer' => round($fbsPickupTransfer, 2),
                'fbs_delivery' => round($fbsDelivery, 2),
                'fbs_middle_mile' => round($fbsMiddleMile, 2),
                'fbs_total' => round($fbsTotal, 2),
            ],
        ];
    }

    public function createOrUpdate(array $data): UnitEconomics
    {
        $calculated = $this->calculate($data['marketplace'], $data);

        return UnitEconomics::updateOrCreate(
            [
                'sku' => $data['sku'],
                'marketplace' => $data['marketplace'],
                'period_start' => $data['period_start'] ?? now()->startOfMonth()->toDateString(),
                'period_end' => $data['period_end'] ?? now()->endOfMonth()->toDateString(),
            ],
            [
                'integration_id' => $data['integration_id'] ?? null,
                'product_name' => $data['product_name'] ?? null,
                'price' => $data['price'],
                'cost_price' => $data['cost_price'],
                'sales_count' => $data['sales_count'] ?? 0,
                'revenue' => $calculated['revenue'],
                'total_costs' => $calculated['total_costs'],
                'gross_profit' => $calculated['gross_profit'],
                'net_profit' => $calculated['net_profit'],
                'margin_percent' => $calculated['margin_percent'],
                'roi_percent' => $calculated['roi_percent'],
                'marketplace_data' => $calculated,
            ]
        );
    }

    public function getStats(array $filters = []): array
    {
        $query = UnitEconomics::query();

        if (!empty($filters['marketplace'])) {
            $query->marketplace($filters['marketplace']);
        }

        return [
            'total_revenue' => round($query->sum('revenue'), 2),
            'total_costs' => round($query->sum('total_costs'), 2),
            'total_profit' => round($query->sum('net_profit'), 2),
            'average_margin' => round($query->avg('margin_percent'), 2),
            'average_roi' => round($query->avg('roi_percent'), 2),
            'total_sales' => $query->sum('sales_count'),
            'profitable_products' => (clone $query)->profitable()->count(),
            'unprofitable_products' => (clone $query)->unprofitable()->count(),
        ];
    }

    public function getStatsByMarketplace(string $marketplace): array
    {
        $query = UnitEconomics::marketplace($marketplace);

        return [
            'total_revenue' => round($query->sum('revenue'), 2),
            'total_costs' => round($query->sum('total_costs'), 2),
            'total_profit' => round($query->sum('net_profit'), 2),
            'average_margin' => round($query->avg('margin_percent'), 2),
            'average_roi' => round($query->avg('roi_percent'), 2),
            'total_sales' => $query->sum('sales_count'),
            'profitable_products' => (clone $query)->profitable()->count(),
            'unprofitable_products' => (clone $query)->unprofitable()->count(),
        ];
    }

    public function getOverallStats(): array
    {
        $stats = [];

        foreach (['wildberries', 'ozon', 'yandex_market'] as $marketplace) {
            $stats[$marketplace] = $this->getStatsByMarketplace($marketplace);
        }

        $stats['total'] = [
            'total_revenue' => array_sum(array_column($stats, 'total_revenue')),
            'total_profit' => array_sum(array_column($stats, 'total_profit')),
            'total_sales' => array_sum(array_column($stats, 'total_sales')),
        ];

        return $stats;
    }

    public function getMarketplaceComparison(): array
    {
        $comparison = [];

        foreach (['wildberries', 'ozon', 'yandex_market'] as $marketplace) {
            $stats = $this->getStatsByMarketplace($marketplace);
            $comparison[$marketplace] = [
                'revenue' => $stats['total_revenue'],
                'profit' => $stats['total_profit'],
                'margin' => $stats['average_margin'],
                'roi' => $stats['average_roi'],
                'products' => $stats['profitable_products'] + $stats['unprofitable_products'],
            ];
        }

        return $comparison;
    }

    public function getCommissions(string $marketplace): array
    {
        return Cache::remember("commissions_{$marketplace}", 3600, function () use ($marketplace) {
            return match ($marketplace) {
                'wildberries' => [
                    ['category' => 'Одежда', 'commission' => 15],
                    ['category' => 'Обувь', 'commission' => 15],
                    ['category' => 'Электроника', 'commission' => 10],
                    ['category' => 'Красота', 'commission' => 18],
                    ['category' => 'Дом и сад', 'commission' => 15],
                ],
                'ozon' => [
                    ['category' => 'Одежда', 'fbo' => 15, 'fbs' => 12],
                    ['category' => 'Обувь', 'fbo' => 15, 'fbs' => 12],
                    ['category' => 'Электроника', 'fbo' => 8, 'fbs' => 6],
                    ['category' => 'Красота', 'fbo' => 18, 'fbs' => 15],
                ],
                'yandex_market' => [
                    ['category' => 'Одежда', 'referral_fee' => 6],
                    ['category' => 'Электроника', 'referral_fee' => 4],
                    ['category' => 'Красота', 'referral_fee' => 8],
                ],
                default => [],
            };
        });
    }

    public function getTariffs(string $marketplace): array
    {
        return Cache::remember("tariffs_{$marketplace}", 3600, function () use ($marketplace) {
            return match ($marketplace) {
                'wildberries' => [
                    'storage' => ['per_liter_per_day' => 0.5],
                    'logistics' => ['base' => 50, 'per_kg' => 5],
                    'acceptance' => ['per_item' => 2],
                ],
                'ozon' => [
                    'last_mile' => ['base' => 40],
                    'storage' => ['per_liter_per_day' => 0.4],
                    'acquiring' => ['percent' => 1.5],
                ],
                'yandex_market' => [
                    'fby_delivery' => ['base' => 50],
                    'fbs_delivery' => ['base' => 40],
                    'storage' => ['per_liter_per_day' => 0.3],
                ],
                default => [],
            };
        });
    }

    public function bulkSave(array $items): array
    {
        $synced = 0;
        $errors = 0;

        foreach ($items as $item) {
            try {
                $this->createOrUpdate($item);
                $synced++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    public function getProductComparison(?int $integrationId = null): array
    {
        $query = UnitEconomics::query();
        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }

        return $query->select('sku', 'marketplace', 'price', 'cost_price', 'margin_percent', 'roi_percent', 'net_profit')
            ->orderByDesc('roi_percent')
            ->limit(100)
            ->get()
            ->toArray();
    }

    public function syncFromRealData(
        \App\Models\Integration $integration,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        ?array $localizationIndex = null
    ): array {
        $exitCode = \Illuminate\Support\Facades\Artisan::call('unit-economics:sync', [
            '--integration' => $integration->id,
        ]);

        return [
            'synced' => $exitCode === 0 ? 1 : 0,
            'errors' => $exitCode !== 0 ? 1 : 0,
        ];
    }
}
