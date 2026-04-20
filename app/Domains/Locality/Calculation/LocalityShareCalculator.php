<?php

namespace App\Domains\Locality\Calculation;

/**
 * Считает долю локальных продаж по ФАКТИЧЕСКОЙ маршрутизации (shipping_cluster vs destination_cluster),
 * а НЕ по markup_reason_code. Это принципиально:
 * reason_code может быть 'fbo_lt_50_orders_7d' или 'zero_markup_cluster' у non-local заказов,
 * если Ozon решил не применять наценку — но физически продажа всё равно non-local.
 *
 * Правило:
 *   - cancelled_order / not_redeemed → исключаем из знаменателя (физически не состоялось).
 *   - если known(shipping) && known(destination):
 *       shipping == destination → local
 *       shipping != destination → non_local
 *   - если shipping/destination unknown, но reason=local_cluster → fallback к reason (редкие случаи).
 *   - иначе не учитываем (нет достоверной информации).
 */
class LocalityShareCalculator
{
    private const EXCLUDED_REASONS = ['cancelled_order', 'not_redeemed'];

    /**
     * @param iterable<object|array{
     *   markup_reason_code?:?string,
     *   markup_applied?:bool,
     *   shipping_cluster_name?:?string,
     *   destination_cluster_name?:?string
     * }> $items
     * @return array{local:int,non_local:int,total_considered:int,excluded:int,share_percent:?float}
     */
    public function compute(iterable $items): array
    {
        $local = 0;
        $nonLocal = 0;
        $excluded = 0;

        foreach ($items as $item) {
            $reason = $this->reasonCode($item);
            if (in_array($reason, self::EXCLUDED_REASONS, true)) {
                $excluded++;
                continue;
            }

            $ship = $this->shippingCluster($item);
            $dest = $this->destinationCluster($item);

            if ($ship !== null && $dest !== null) {
                if ($ship === $dest) {
                    $local++;
                } else {
                    $nonLocal++;
                }
                continue;
            }

            if ($reason === 'local_cluster') {
                $local++;
            } elseif ($reason === 'non_local_markup_applied') {
                $nonLocal++;
            }
        }

        $total = $local + $nonLocal;
        $share = $total > 0 ? round(($local / $total) * 100, 2) : null;

        return [
            'local' => $local,
            'non_local' => $nonLocal,
            'total_considered' => $total,
            'excluded' => $excluded,
            'share_percent' => $share,
        ];
    }

    private function reasonCode(object|array $item): ?string
    {
        if (is_array($item)) {
            return $item['markup_reason_code'] ?? null;
        }
        return $item->markup_reason_code ?? null;
    }

    private function shippingCluster(object|array $item): ?string
    {
        $value = is_array($item)
            ? ($item['shipping_cluster_name'] ?? null)
            : ($item->shipping_cluster_name ?? null);
        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function destinationCluster(object|array $item): ?string
    {
        $value = is_array($item)
            ? ($item['destination_cluster_name'] ?? null)
            : ($item->destination_cluster_name ?? null);
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
