<?php

namespace App\Domains\Locality\Calculation;

use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Domains\Ozon\UnitEconomics\MarkupReasonCode;
use App\Models\OzonOrderUnitEconomics;
use App\Models\Product;

/**
 * Потеря маржи из-за non-local продажи = потенциальная переплата (по таблице Ozon)
 *                                    + дельта базового тарифа (если логистика дороже
 *                                      при non-local маршруте vs local).
 *
 * Считается для каждого non-local заказа (shipping != destination, non-cancelled),
 * независимо от того, применяет ли Ozon наценку в текущий момент.
 */
class LostMarginCalculator
{
    private static ?array $excludedReasonsCache = null;

    private static function excludedReasons(): array
    {
        return self::$excludedReasonsCache ??= MarkupReasonCode::excludedValues();
    }

    public function __construct(
        private readonly OzonPricingMatrix $pricing = new OzonPricingMatrix(),
    ) {
    }

    /**
     * @return array{amount:float, degraded:bool}
     */
    public function computePerItem(OzonOrderUnitEconomics $item, ?Product $product = null): array
    {
        if (in_array($item->markup_reason_code, self::excludedReasons(), true)) {
            return ['amount' => 0.0, 'degraded' => false];
        }

        $ship = $item->shipping_cluster_name;
        $dest = $item->destination_cluster_name;
        if ($ship === null || $dest === null || $ship === $dest) {
            return ['amount' => 0.0, 'degraded' => false];
        }

        $salePrice = (float) ($item->sale_price ?? 0);
        $orderDate = $item->order_date ?? null;
        $markupPct = (float) $this->pricing->resolveDestinationMarkupPercent((string) $dest, $orderDate ? (string) $orderDate : null);
        $potentialMarkup = $salePrice > 0 && $markupPct > 0
            ? round($salePrice * ($markupPct / 100), 2)
            : 0.0;

        $tariffDelta = 0.0;
        $degraded = false;
        try {
            $hypothetical = $this->pricing->resolveClusterLogistics(
                'FBO',
                (float) ($item->volume_liters ?? 0),
                $salePrice,
                (string) $dest,
                (string) $dest,
                $orderDate ? (string) $orderDate : null,
            );
            $hypoBase = (float) ($hypothetical['base_cost'] ?? 0);
            $currentBase = (float) $item->base_logistics_tariff;
            if ($hypoBase > 0 && $currentBase > 0) {
                $tariffDelta = max(0.0, $currentBase - $hypoBase);
            } else {
                $degraded = true;
            }
        } catch (\Throwable) {
            $degraded = true;
        }

        return [
            'amount' => round($potentialMarkup + $tariffDelta, 2),
            'degraded' => $degraded,
        ];
    }

    /**
     * @param iterable<OzonOrderUnitEconomics> $items
     * @return array{amount:float, degraded_items:int, total_items:int}
     */
    public function computeForItems(iterable $items): array
    {
        $total = 0.0;
        $degraded = 0;
        $count = 0;

        foreach ($items as $item) {
            $count++;
            $result = $this->computePerItem($item);
            $total += $result['amount'];
            if ($result['degraded']) {
                $degraded++;
            }
        }

        return [
            'amount' => round($total, 2),
            'degraded_items' => $degraded,
            'total_items' => $count,
        ];
    }
}
