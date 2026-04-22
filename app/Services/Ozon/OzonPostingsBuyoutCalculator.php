<?php

namespace App\Services\Ozon;

use Illuminate\Support\Facades\DB;

/**
 * Считает фактический % выкупа по таблице postings за N дней.
 *
 * Источник истины — реальные заказы Ozon (posting_number), а не агрегаты
 * из /v1/analytics/data. Агрегаты часто занижены/кэшируются странно:
 * по SKU 2099/black1 API вернул 9 заказов за 30д, а в postings их 47.
 *
 * Группировка статусов:
 *   delivered                → выкупили
 *   cancelled                → отменили
 *   not_accepted             → не выкупили
 *   delivering / awaiting_*  → в пути, оптимистично считаем выкупленными
 *
 * Формула (как у виджета Ozon «Выкупы за 28 дней»):
 *   rate = (delivered + in_flight) / (delivered + cancelled + not_accepted + in_flight) * 100
 *
 * Возвраты Ozon отдаёт отдельным API (/v1/returns/*), здесь не учтены —
 * их доля по каталогу в среднем <5% и в postings.status они почти не встречаются.
 */
class OzonPostingsBuyoutCalculator
{
    private const IN_FLIGHT_STATUSES = ['delivering', 'awaiting_deliver', 'awaiting_packaging'];
    private const DELIVERED_STATUSES = ['delivered'];
    private const CANCELLED_STATUSES = ['cancelled'];
    private const NOT_REDEEMED_STATUSES = ['not_accepted'];

    /**
     * Посчитать выкуп для одного SKU за последние $days дней.
     *
     * @return array|null null — если постингов нет вообще (стоит fallback на /v1/analytics/data).
     */
    public function calculateForSku(int $integrationId, string $sku, int $days = 28): ?array
    {
        // Окно D-28…D-1, как в виджете Ozon «Выкупы по товару».
        $dateTo = now()->subDays(1)->endOfDay();
        $dateFrom = (clone $dateTo)->subDays($days - 1)->startOfDay();

        // Единый источник истины: ozon_order_unit_economics (order_date).
        // Status берём из postings (обновляется через refreshInFlightOzonPostings).
        // Period по oue.order_date (уже чистый после dedup-миграции 2026-04-22).
        // Это устраняет расхождение двух источников (postings.in_process_at vs
        // oue.order_date) — теперь локальность и выкуп считают одно и то же
        // множество заказов.
        $rows = DB::table('ozon_order_unit_economics AS oue')
            ->join('postings AS p', function ($join) {
                $join->on('p.id', '=', 'oue.posting_id')
                     ->where('p.marketplace', '=', 'ozon');
            })
            ->where('oue.integration_id', $integrationId)
            ->where('oue.sku', $sku)
            ->whereBetween('oue.order_date', [$dateFrom, $dateTo])
            // COUNT(DISTINCT oue.posting_number) — уникальные заказы Ozon.
            ->selectRaw('p.status, COUNT(DISTINCT oue.posting_number) AS qty, COUNT(*) AS postings_count')
            ->groupBy('p.status')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $delivered = 0;
        $cancelled = 0;
        $notRedeemed = 0;
        $inFlight = 0;
        $totalPostings = 0;

        foreach ($rows as $row) {
            $qty = (int) $row->qty;
            $totalPostings += (int) $row->postings_count;

            if (in_array($row->status, self::DELIVERED_STATUSES, true)) {
                $delivered += $qty;
            } elseif (in_array($row->status, self::CANCELLED_STATUSES, true)) {
                $cancelled += $qty;
            } elseif (in_array($row->status, self::NOT_REDEEMED_STATUSES, true)) {
                $notRedeemed += $qty;
            } elseif (in_array($row->status, self::IN_FLIGHT_STATUSES, true)) {
                $inFlight += $qty;
            }
        }

        $finalized = $delivered + $cancelled + $notRedeemed;
        $totalOrders = $finalized + $inFlight;
        if ($totalOrders === 0) {
            return null;
        }

        // Оптимистичная формула (как виджет Ozon «Выкупы за 28 дней»):
        // delivering считаем уже выкупленными. Ozon сам так делает в виджете,
        // даже когда его /v2/posting/fbo/get по этому же заказу ещё отдаёт
        // delivering. Статусы догонятся при следующем refresh / sync.
        $optimisticDelivered = $delivered + $inFlight;
        $rate = round(($optimisticDelivered / $totalOrders) * 100, 2);

        return [
            'redemption_rate' => $rate,
            'orders_count' => $totalOrders,
            'delivered_count' => $optimisticDelivered,
            'delivered_confirmed_count' => $delivered,
            'cancelled_count' => $cancelled,
            'cancellations_count' => $cancelled,
            'not_redeemed_count' => $notRedeemed,
            'in_flight_count' => $inFlight,
            'returns_count' => 0,
            'postings_count' => $totalPostings,
            'period_days' => $days,
            'source' => 'postings_28d',
            // Доверяем постингам при любом количестве заказов — пусть даже 1.
// Раньше ставили >=3, из-за чего 2 заказа считались «неполными»
// и API analytics перезаписывал их дефолтом 100%.
'has_full_data' => $totalOrders >= 1,
        ];
    }

    /**
     * Пакетный расчёт для всех SKU интеграции. Возвращает map [sku => result|null].
     */
    public function calculateForIntegration(int $integrationId, int $days = 28): array
    {
        // Окно D-28…D-1, как в виджете Ozon «Выкупы по товару».
        $dateTo = now()->subDays(1)->endOfDay();
        $dateFrom = (clone $dateTo)->subDays($days - 1)->startOfDay();

        // Тот же единый источник что и в calculateForSku — oue для period,
        // postings для актуального status.
        $rows = DB::table('ozon_order_unit_economics AS oue')
            ->join('postings AS p', function ($join) {
                $join->on('p.id', '=', 'oue.posting_id')
                     ->where('p.marketplace', '=', 'ozon');
            })
            ->where('oue.integration_id', $integrationId)
            ->whereBetween('oue.order_date', [$dateFrom, $dateTo])
            ->whereNotNull('oue.sku')
            ->selectRaw('oue.sku, p.status, COUNT(DISTINCT oue.posting_number) AS qty, COUNT(*) AS postings_count')
            ->groupBy('oue.sku', 'p.status')
            ->get();

        $perSku = [];
        foreach ($rows as $row) {
            $sku = $row->sku;
            if (! isset($perSku[$sku])) {
                $perSku[$sku] = [
                    'delivered' => 0,
                    'cancelled' => 0,
                    'not_redeemed' => 0,
                    'in_flight' => 0,
                    'postings' => 0,
                ];
            }
            $qty = (int) $row->qty;
            $perSku[$sku]['postings'] += (int) $row->postings_count;

            if (in_array($row->status, self::DELIVERED_STATUSES, true)) {
                $perSku[$sku]['delivered'] += $qty;
            } elseif (in_array($row->status, self::CANCELLED_STATUSES, true)) {
                $perSku[$sku]['cancelled'] += $qty;
            } elseif (in_array($row->status, self::NOT_REDEEMED_STATUSES, true)) {
                $perSku[$sku]['not_redeemed'] += $qty;
            } elseif (in_array($row->status, self::IN_FLIGHT_STATUSES, true)) {
                $perSku[$sku]['in_flight'] += $qty;
            }
        }

        $result = [];
        foreach ($perSku as $sku => $buckets) {
            $finalized = $buckets['delivered'] + $buckets['cancelled'] + $buckets['not_redeemed'];
            $totalOrders = $finalized + $buckets['in_flight'];
            if ($totalOrders === 0) {
                continue;
            }

            // Оптимистичная формула — см. calculateForSku().
            $optimisticDelivered = $buckets['delivered'] + $buckets['in_flight'];

            $result[$sku] = [
                'redemption_rate' => round(($optimisticDelivered / $totalOrders) * 100, 2),
                'orders_count' => $totalOrders,
                'delivered_count' => $optimisticDelivered,
                'delivered_confirmed_count' => $buckets['delivered'],
                'cancelled_count' => $buckets['cancelled'],
                'cancellations_count' => $buckets['cancelled'],
                'not_redeemed_count' => $buckets['not_redeemed'],
                'in_flight_count' => $buckets['in_flight'],
                'returns_count' => 0,
                'postings_count' => $buckets['postings'],
                'period_days' => $days,
                'source' => 'postings_28d',
                // Доверяем постингам при любом количестве заказов — пусть даже 1.
// Раньше ставили >=3, из-за чего 2 заказа считались «неполными»
// и API analytics перезаписывал их дефолтом 100%.
'has_full_data' => $totalOrders >= 1,
            ];
        }

        return $result;
    }
}
