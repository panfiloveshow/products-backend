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
    private const STORAGE_OPERATION = 'OperationMarketplaceServiceStorage';
    /** Реклама: клики и «оплата за заказ». Per-SKU в транзакциях нет — только магазин целиком. */
    private const AD_OPERATIONS = [
        'OperationMarketplaceCostPerClick',
        'OperationPromotionWithCostPerOrder',
    ];

    /**
     * @return array{
     *   acquiring_percent: ?float,
     *   last_mile_avg: ?float,
     *   storage_per_unit: ?float,
     *   ad_percent: ?float,
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
            'storage_per_unit' => null,
            'ad_percent' => null,
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
        $storage = $sum(self::STORAGE_OPERATION);
        $lastMile = $this->lastMileTotal($integrationId, $from, $to);
        $ads = abs((float) DB::table('ozon_finance_transactions')
            ->where('integration_id', $integrationId)
            ->whereIn('operation_type', self::AD_OPERATIONS)
            ->whereBetween('operation_date', [$from, $to])
            ->sum('amount'));

        return [
            // Ставки-выбросы не пропускаем: Ozon не берёт больше 3% эквайринга,
            // а перекошенная выборка (пара операций на весь месяц) хуже дефолта.
            'acquiring_percent' => $acquiring > 0 ? min(3.0, round($acquiring / $revenue * 100, 2)) : null,
            'last_mile_avg' => $lastMile > 0 ? round($lastMile / $units, 2) : null,
            'storage_per_unit' => $storage > 0 ? round($storage / $units, 2) : null,
            // Средняя по магазину: per-SKU разбивки у рекламных списаний нет
            // (posting_number там — документ кампании, sku всегда пустой).
            'ad_percent' => $ads > 0 ? round($ads / $revenue * 100, 2) : null,
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
                    foreach ((array) ($row->raw['services'] ?? []) as $service) {
                        $name = (string) ($service['name'] ?? '');
                        if (str_contains($name, 'LastMile') || str_contains($name, 'HandoverPlace')) {
                            $total += abs((float) ($service['price'] ?? 0));
                        }
                    }
                }
            });

        return $total;
    }
}
