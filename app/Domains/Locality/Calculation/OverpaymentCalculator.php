<?php

namespace App\Domains\Locality\Calculation;

use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Domains\Ozon\UnitEconomics\MarkupReasonCode;

/**
 * Считает ПОТЕНЦИАЛЬНУЮ переплату за non-local продажи по таблице наценок Ozon
 * (4-8% от цены товара по кластеру назначения). Это то, что показывает Ozon
 * в кабинете «Планирование поставок → Локальность продаж».
 *
 * ВАЖНО: считаем потенциальную переплату независимо от того, применил ли Ozon наценку
 * в конкретный период. Если магазин пройдёт порог «≥50 FBO/7дн» — наценка начнёт
 * взиматься фактически; до этого момента переплата «скрыта», но экономически реальна.
 *
 * Формула per item:
 *   если shipping_cluster != destination_cluster (реально non-local)
 *   и заказ не cancelled/not_redeemed:
 *     potential_overpayment = sale_price × (markup_percent_by_destination / 100)
 *
 * Дополнительно возвращается фактическая переплата (то, что списал Ozon сейчас),
 * разница между ними показывает, сколько магазин пока «экономит» из-за лимитов.
 */
class OverpaymentCalculator
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
     * Потенциальная переплата по таблице наценок Ozon.
     *
     * @param iterable<object|array> $items
     * @return array{potential:float,actual:float,non_local_orders:int,avg_markup_percent:float}
     */
    public function compute(iterable $items): array
    {
        $potential = 0.0;
        $actual = 0.0;
        $nonLocalOrders = 0;
        $markupPctSum = 0.0;

        foreach ($items as $item) {
            $reason = $this->reasonCode($item);
            if (in_array($reason, self::excludedReasons(), true)) {
                continue;
            }

            // Фактическая (то что Ozon реально списывает с магазина сейчас)
            if ($this->markupApplied($item)) {
                $actual += $this->markupAmount($item);
            }

            $ship = $this->shippingCluster($item);
            $dest = $this->destinationCluster($item);
            if ($ship === null || $dest === null || $ship === $dest) {
                continue;
            }

            // Non-local: считаем потенциальную наценку по таблице Ozon
            $markupPct = $this->pricing->resolveDestinationMarkupPercent($dest);
            $price = $this->salePrice($item);
            if ($price <= 0 || $markupPct <= 0) {
                $nonLocalOrders++;
                continue;
            }

            $potential += $price * ($markupPct / 100);
            $markupPctSum += $markupPct;
            $nonLocalOrders++;
        }

        return [
            'potential' => round($potential, 2),
            'actual' => round($actual, 2),
            'non_local_orders' => $nonLocalOrders,
            'avg_markup_percent' => $nonLocalOrders > 0
                ? round($markupPctSum / $nonLocalOrders, 2)
                : 0.0,
        ];
    }

    /**
     * Упрощённый хелпер: только потенциальная сумма (backward-compatible вызов).
     */
    public function computePotential(iterable $items): float
    {
        return $this->compute($items)['potential'];
    }

    private function reasonCode(object|array $item): ?string
    {
        return is_array($item) ? ($item['markup_reason_code'] ?? null) : ($item->markup_reason_code ?? null);
    }

    private function markupApplied(object|array $item): bool
    {
        return (bool) (is_array($item) ? ($item['markup_applied'] ?? false) : ($item->markup_applied ?? false));
    }

    private function markupAmount(object|array $item): float
    {
        return (float) (is_array($item) ? ($item['non_local_markup_amount'] ?? 0) : ($item->non_local_markup_amount ?? 0));
    }

    private function salePrice(object|array $item): float
    {
        return (float) (is_array($item) ? ($item['sale_price'] ?? 0) : ($item->sale_price ?? 0));
    }

    private function shippingCluster(object|array $item): ?string
    {
        $v = is_array($item) ? ($item['shipping_cluster_name'] ?? null) : ($item->shipping_cluster_name ?? null);
        return ($v === null || $v === '') ? null : (string) $v;
    }

    private function destinationCluster(object|array $item): ?string
    {
        $v = is_array($item) ? ($item['destination_cluster_name'] ?? null) : ($item->destination_cluster_name ?? null);
        return ($v === null || $v === '') ? null : (string) $v;
    }
}
