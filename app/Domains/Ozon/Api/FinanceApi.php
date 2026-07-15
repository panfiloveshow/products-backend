<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Log;

/**
 * Финансовые методы Ozon Seller API.
 */
class FinanceApi
{
    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Фактические цены продажи по SKU из помесячного отчёта о реализации
     * (/v2/finance/realization) за последний закрытый месяц.
     *
     * Зачем: Ozon ставит доп. скидки за счёт продавца («Баллы за скидки»), и товар
     * реально продаётся дешевле цены продавца. Для налога УСН база — фактическая
     * цена продажи («реализовано со скидкой»), а не цена продавца. Транзакции и
     * аналитика отдают полную цену, факт есть только в отчёте о реализации.
     *
     * @return array{by_offer_id: array<string, float>, by_sku: array<string, float>, month: string|null}
     *   Средняя фактическая цена продажи за единицу по артикулу и по Ozon SKU.
     */
    public function getActualSalePrices(): array
    {
        $result = ['by_offer_id' => [], 'by_sku' => [], 'month' => null];

        // Последний закрытый месяц; если отчёт ещё не готов (начало месяца) — на месяц раньше.
        foreach ([1, 2] as $monthsBack) {
            $period = now()->subMonthsNoOverflow($monthsBack);
            try {
                $response = $this->client->post('/v2/finance/realization', [
                    'month' => $period->month,
                    'year' => $period->year,
                ]);
            } catch (\Throwable $e) {
                Log::info('Ozon realization v2 unavailable', ['month' => $period->format('Y-m'), 'error' => $e->getMessage()]);
                continue;
            }

            $rows = data_get($response, 'result.rows', $response['rows'] ?? []);
            if (! is_array($rows) || $rows === []) {
                continue;
            }

            // Суммируем по товару: продано на сумму (со скидкой) и штук — средняя факт. цена.
            $totals = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $offerId = trim((string) data_get($row, 'item.offer_id', ''));
                $sku = trim((string) data_get($row, 'item.sku', ''));
                $amount = (float) data_get($row, 'delivery_commission.amount', 0);
                $quantity = (int) data_get($row, 'delivery_commission.quantity', 0);
                if ($quantity <= 0 || $amount <= 0) {
                    continue;
                }
                $key = $offerId !== '' ? $offerId : $sku;
                if ($key === '') {
                    continue;
                }
                if (! isset($totals[$key])) {
                    $totals[$key] = ['amount' => 0.0, 'quantity' => 0, 'sku' => $sku];
                }
                $totals[$key]['amount'] += $amount;
                $totals[$key]['quantity'] += $quantity;
            }

            foreach ($totals as $offerId => $t) {
                $avg = round($t['amount'] / max(1, $t['quantity']), 2);
                $result['by_offer_id'][(string) $offerId] = $avg;
                if ($t['sku'] !== '') {
                    $result['by_sku'][(string) $t['sku']] = $avg;
                }
            }
            $result['month'] = $period->format('Y-m');

            return $result;
        }

        return $result;
    }
}
