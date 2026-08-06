<?php

namespace App\Services\Ozon;

use App\Models\OzonFinanceTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Фактические удельные ставки Ozon по магазину — из уже загруженных
 * `ozon_finance_transactions`, без единого обращения к API.
 *
 * Зачем: тарифные дефолты систематически врут. Проверка трёх магазинов за 28 дней
 * (2026-08-05) показала:
 *   - последняя миля: в расчёте 25 ₽ на каждый заказ, по факту 9-11 ₽
 *     (RedistributionLastMileCourier 7-13 ₽; ровно 25 ₽ только у DeliveryToHandoverPlaceOzon);
 *   - эквайринг: в расчёте дефолт 1.5%, по факту 0.71-1.08%;
 *   - хранение: в расчёте бралось из остатков и попадало во все схемы включая FBS,
 *     по факту 0.6-6.8% выручки и только по FBO.
 *
 * Раньше эквайринг по факту для Ozon дёргали через API (`getAcquiringBySku`) и
 * отключили из-за OOM. Здесь тот же результат — одним SQL по локальной таблице.
 */
class OzonActualRatesService
{
    /**
     * Ставки одинаковы для всего магазина, а синк зовёт их на каждый SKU —
     * держим результат на время процесса.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $cache = [];

    private const SALE_OPERATION = 'OperationAgentDeliveredToCustomer';
    private const ACQUIRING_OPERATION = 'MarketplaceRedistributionOfAcquiringOperation';

    /**
     * @return array{
     *   acquiring_percent: ?float,
     *   last_mile_avg: ?float,
     *   revenue: float,
     *   units: int,
     *   days: int
     * }
     */
    public function forIntegration(int $integrationId, int $days = 28): array
    {
        return self::$cache[$integrationId.':'.$days] ??= $this->compute($integrationId, $days);
    }

    private function compute(int $integrationId, int $days): array
    {
        $from = now()->subDays($days + 1)->startOfDay();
        $to = now()->subDays(1)->endOfDay();

        $sales = DB::table('ozon_finance_transactions')
            ->where('integration_id', $integrationId)
            ->where('operation_type', self::SALE_OPERATION)
            ->whereBetween('operation_date', [$from, $to])
            ->selectRaw('count(*) units, coalesce(sum(accruals_for_sale), 0) revenue')
            ->first();

        $units = (int) ($sales->units ?? 0);
        $revenue = (float) ($sales->revenue ?? 0);

        $empty = [
            'acquiring_percent' => null,
            'last_mile_avg' => null,
            'revenue' => $revenue,
            'units' => $units,
            'days' => $days,
        ];

        // Меньше 20 продаж — выборка не репрезентативна, остаёмся на тарифных дефолтах.
        if ($units < 20 || $revenue <= 0) {
            return $empty;
        }

        $sum = fn (string $type): float => abs((float) DB::table('ozon_finance_transactions')
            ->where('integration_id', $integrationId)
            ->where('operation_type', $type)
            ->whereBetween('operation_date', [$from, $to])
            ->sum('amount'));

        $acquiring = $sum(self::ACQUIRING_OPERATION);
        $lastMile = $this->lastMileTotal($integrationId, $from, $to);

        return [
            // Ставки-выбросы не пропускаем: Ozon не берёт больше 3% эквайринга,
            // а перекошенная выборка (пара операций на весь месяц) хуже дефолта.
            'acquiring_percent' => $acquiring > 0 ? min(3.0, round($acquiring / $revenue * 100, 2)) : null,
            'last_mile_avg' => $lastMile > 0 ? round($lastMile / $units, 2) : null,
            'revenue' => $revenue,
            'units' => $units,
            'days' => $days,
        ];
    }

    /**
     * Последняя миля лежит не отдельной операцией, а услугой внутри операции продажи:
     * RedistributionLastMileCourier / RedistributionLastMilePVZ / DeliveryToHandoverPlaceOzon.
     */
    private function lastMileTotal(int $integrationId, \DateTimeInterface $from, \DateTimeInterface $to): float
    {
        $total = 0.0;

        OzonFinanceTransaction::query()
            ->where('integration_id', $integrationId)
            ->where('operation_type', self::SALE_OPERATION)
            ->whereBetween('operation_date', [$from, $to])
            ->select(['id', 'raw'])
            ->chunkById(2000, function ($rows) use (&$total): void {
                foreach ($rows as $row) {
                    $total += $this->lastMileOfRow($row);
                }
            });

        return $total;
    }

    /** @var array<string, array<string, float>> integration:days => numericSku => ₽/шт */
    private static array $skuLastMileCache = [];

    /**
     * Последняя миля по КАЖДОМУ SKU: у Ozon она зависит от цены товара, поэтому
     * среднее по магазину заведомо врёт для дешёвых и дорогих позиций. Колонка
     * sku в транзакции продажи — числовой SKU Ozon (ozon_data.sku/fbo_sku/fbs_sku).
     *
     * @return array<string, float> numericSku => средняя последняя миля ₽/шт
     */
    public function lastMilePerSku(int $integrationId, int $days = 28): array
    {
        return self::$skuLastMileCache[$integrationId.':'.$days] ??= $this->computeLastMilePerSku($integrationId, $days);
    }

    private function computeLastMilePerSku(int $integrationId, int $days): array
    {
        $from = now()->subDays($days + 1)->startOfDay();
        $to = now()->subDays(1)->endOfDay();

        $sums = [];
        $counts = [];

        OzonFinanceTransaction::query()
            ->where('integration_id', $integrationId)
            ->where('operation_type', self::SALE_OPERATION)
            ->whereBetween('operation_date', [$from, $to])
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->select(['id', 'sku', 'raw'])
            ->chunkById(2000, function ($rows) use (&$sums, &$counts): void {
                foreach ($rows as $row) {
                    $sku = (string) $row->sku;
                    $sums[$sku] = ($sums[$sku] ?? 0.0) + $this->lastMileOfRow($row);
                    $counts[$sku] = ($counts[$sku] ?? 0) + 1;
                }
            });

        $map = [];
        foreach ($sums as $sku => $sum) {
            // 1-2 продажи — шум (акционная развозка, возвратная миля), не ставка.
            if ($counts[$sku] >= 3 && $sum > 0) {
                $map[$sku] = round($sum / $counts[$sku], 2);
            }
        }

        return $map;
    }

    private function lastMileOfRow(OzonFinanceTransaction $row): float
    {
        $total = 0.0;
        foreach ((array) ($row->raw['services'] ?? []) as $service) {
            $name = (string) ($service['name'] ?? '');
            if (str_contains($name, 'LastMile') || str_contains($name, 'HandoverPlace')) {
                $total += abs((float) ($service['price'] ?? 0));
            }
        }

        return $total;
    }
}
