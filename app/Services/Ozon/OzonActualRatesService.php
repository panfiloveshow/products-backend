<?php

namespace App\Services\Ozon;

use App\Models\Integration;
use App\Models\OzonFinanceTransaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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

    private static array $periodCache = [];

    public static function forgetIntegration(int $integrationId): void
    {
        foreach (['cache', 'skuLastMileCache', 'periodCache'] as $property) {
            foreach (array_keys(self::${$property}) as $key) {
                if (str_starts_with($key, $integrationId.':')) {
                    unset(self::${$property}[$key]);
                }
            }
        }
    }

    private const SALE_OPERATION = 'OperationAgentDeliveredToCustomer';

    /**
     * Legacy transaction/list и новый accrual/by-day называют эквайринг по-разному;
     * в 28-дневном окне могут встречаться оба (день пишется только одним источником,
     * так что двойного счёта нет).
     */
    private const ACQUIRING_OPERATIONS = [
        'MarketplaceRedistributionOfAcquiringOperation', // legacy /v3
        'Acquiring',                                     // accrual type_id=1
    ];

    /**
     * Последняя миля в новом синке — отдельные строки с operation_type из
     * словаря accrual/types (в legacy она лежала услугой внутри строки продажи,
     * см. lastMileOfRow).
     */
    private const LAST_MILE_OPERATIONS = [
        'LastMile', 'LastMileCourier', 'LastMilePickUpPoint',
        'DeliveryToHandoverPlaceByOzon', 'B2CDeliveryToHandoverPlaceByOzon',
        'B2CCourierClientReinvoice', 'B2CPickUpPointClientReinvoice',
    ];

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
        return self::$cache[$this->cacheKey($integrationId, $days)] ??= $this->compute($integrationId, $days);
    }

    /**
     * Дата в ключе: транзакции приходят ежедневно, а статический кэш живёт
     * столько же, сколько процесс воркера — без даты он раздавал бы ставки
     * прошлой недели до рестарта очереди.
     */
    private function cacheKey(int $integrationId, int $days): string
    {
        return $integrationId.':'.$days.':'.now()->toDateString();
    }

    private function compute(int $integrationId, int $days): array
    {
        $period = $this->ratePeriod($integrationId, $days);
        $from = $period['from'];
        $to = $period['to'];

        // units: legacy-строка = 1 постинг (quantity в raw нет → 1),
        // строка realization/by-day = SKU-день с raw.quantity штук.
        $sales = $this->salesQuery($integrationId, $period)
            ->selectRaw("coalesce(sum(cast(coalesce(nullif(raw->>'quantity', ''), '1') as integer)), 0) units, coalesce(sum(accruals_for_sale), 0) revenue")
            ->first();

        $units = (int) ($sales->units ?? 0);
        $revenue = (float) ($sales->revenue ?? 0);

        $empty = [
            'acquiring_percent' => null,
            'last_mile_avg' => null,
            'revenue' => $revenue,
            'units' => $units,
            'days' => (int) $from->diffInDays($to->copy()->startOfDay()) + 1,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'source' => $period['source'],
        ];

        // Меньше 20 продаж — выборка не репрезентативна, остаёмся на тарифных дефолтах.
        if ($units < 20 || $revenue <= 0) {
            return $empty;
        }

        $sum = fn (array $types): float => abs((float) DB::table('ozon_finance_transactions')
            ->where('integration_id', $integrationId)
            ->whereIn('operation_type', $types)
            ->whereBetween('operation_date', [$from, $to])
            ->sum('amount'));

        $acquiring = $sum(self::ACQUIRING_OPERATIONS);
        $lastMile = $this->lastMileTotal($this->salesQuery($integrationId, $period)) + $sum(self::LAST_MILE_OPERATIONS);

        return [
            // Ставки-выбросы не пропускаем: Ozon не берёт больше 3% эквайринга,
            // а перекошенная выборка (пара операций на весь месяц) хуже дефолта.
            'acquiring_percent' => $acquiring > 0 ? min(3.0, round($acquiring / $revenue * 100, 2)) : null,
            'last_mile_avg' => $lastMile > 0 ? round($lastMile / $units, 2) : null,
            'revenue' => $revenue,
            'units' => $units,
            'days' => $empty['days'],
            'period_from' => $empty['period_from'],
            'period_to' => $empty['period_to'],
            'source' => $period['source'],
        ];
    }

    /**
     * Последняя миля лежит не отдельной операцией, а услугой внутри операции продажи:
     * RedistributionLastMileCourier / RedistributionLastMilePVZ / DeliveryToHandoverPlaceOzon.
     */
    private function lastMileTotal(Builder $sales): float
    {
        $total = 0.0;

        $sales
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
        return self::$skuLastMileCache[$this->cacheKey($integrationId, $days)] ??= $this->computeLastMilePerSku($integrationId, $days);
    }

    private function computeLastMilePerSku(int $integrationId, int $days): array
    {
        $period = $this->ratePeriod($integrationId, $days);
        $from = $period['from'];
        $to = $period['to'];

        $sums = [];
        $counts = [];

        $this->salesQuery($integrationId, $period)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->select(['id', 'sku', 'raw'])
            ->chunkById(2000, function ($rows) use (&$sums, &$counts): void {
                foreach ($rows as $row) {
                    $sku = (string) $row->sku;
                    $sums[$sku] = ($sums[$sku] ?? 0.0) + $this->lastMileOfRow($row);
                    $counts[$sku] = ($counts[$sku] ?? 0) + max(1, (int) ($row->raw['quantity'] ?? 1));
                }
            });

        // Новый синк (accrual/by-day) пишет последнюю милю отдельными строками
        // со своим sku — суммируем их к legacy-значениям из raw.services.
        DB::table('ozon_finance_transactions')
            ->where('integration_id', $integrationId)
            ->whereIn('operation_type', self::LAST_MILE_OPERATIONS)
            ->whereBetween('operation_date', [$from, $to])
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->groupBy('sku')
            ->selectRaw('sku, abs(sum(amount)) total')
            ->get()
            ->each(function ($row) use (&$sums): void {
                $sku = (string) $row->sku;
                $sums[$sku] = ($sums[$sku] ?? 0.0) + (float) $row->total;
            });

        $map = [];
        foreach ($sums as $sku => $sum) {
            // 1-2 продажи — шум (акционная развозка, возвратная миля), не ставка.
            $units = $counts[$sku] ?? 0;
            if ($units >= 3 && $sum > 0) {
                $map[$sku] = round($sum / $units, 2);
            }
        }

        return $map;
    }

    /** Sales and fees must use the same granularity and calendar period. */
    private function ratePeriod(int $integrationId, int $days): array
    {
        $key = $this->cacheKey($integrationId, $days);
        if (isset(self::$periodCache[$key])) {
            return self::$periodCache[$key];
        }

        $period = [
            'from' => now()->subDays(max(1, $days))->startOfDay(),
            'to' => now()->subDay()->endOfDay(),
            'source' => 'daily',
        ];
        $monthly = OzonFinanceTransaction::query()
            ->where('integration_id', $integrationId)
            ->where('operation_type', self::SALE_OPERATION)
            ->where('raw->source', 'realization_v2_month')
            ->where('operation_date', '<', now()->startOfMonth())
            ->orderByDesc('operation_date')
            ->first(['operation_date']);

        $integration = Integration::find($integrationId);
        $blocked = (bool) data_get($integration?->settings, 'ozon_premium_plus_required', false)
            || in_array((string) $integration?->credential_health, ['invalid', 'expired', 'revoked'], true);
        if ($blocked && $monthly === null) {
            $period['source'] = 'unavailable';
        } elseif ($monthly !== null) {
            if ($blocked || ! $this->salesQuery($integrationId, $period)->exists()) {
                $month = Carbon::parse($monthly->operation_date);
                $period = ['from' => $month->copy()->startOfMonth(), 'to' => $month->copy()->endOfMonth(), 'source' => 'monthly'];
            }
        }

        return self::$periodCache[$key] = $period;
    }

    private function salesQuery(int $integrationId, array $period): Builder
    {
        $query = OzonFinanceTransaction::query()
            ->where('integration_id', $integrationId)
            ->where('operation_type', self::SALE_OPERATION)
            ->whereBetween('operation_date', [$period['from'], $period['to']]);

        if ($period['source'] === 'unavailable') {
            return $query->whereRaw('1 = 0');
        }

        if ($period['source'] === 'monthly') {
            return $query->where('raw->source', 'realization_v2_month');
        }

        return $query->where(fn (Builder $q) => $q->whereNull('raw->source')->orWhere('raw->source', '!=', 'realization_v2_month'));
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
