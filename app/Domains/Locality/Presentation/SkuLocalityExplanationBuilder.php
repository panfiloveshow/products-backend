<?php

namespace App\Domains\Locality\Presentation;

/**
 * Собирает человекочитаемое объяснение «почему при высокой локальности есть
 * нелокальная наценка» по РЕАЛЬНЫМ цифрам конкретного SKU.
 *
 * Источник истины — бэкенд (один расчёт для всех экранов), чтобы фронт не
 * пересобирал текст из гросс-ставки и не расходился с юнит-экономикой.
 *
 * Три величины — три разных среза, и они НЕ должны совпадать:
 *   8–12%  — полная ставка кластера назначения за один фактический нелок. заказ;
 *   ~gross — средняя ставка по нелокальным заказам SKU (заголовок в таблице);
 *   eff    — та же наценка, размазанная по всей выручке SKU (идёт в юнит-экономику).
 */
class SkuLocalityExplanationBuilder
{
    /**
     * @return array{
     *   short:string,
     *   full:string,
     *   breakdown:list<array{value:string,label:string,scope:string}>,
     *   figures:array{
     *     local_share_percent:?float,
     *     non_local_share_percent:float,
     *     gross_markup_percent:float,
     *     effective_markup_percent:float,
     *     overpayment_rub:float
     *   }
     * }
     */
    public function build(
        ?float $localSharePercent,
        int $consideredOrders,
        int $nonLocalOrders,
        float $grossMarkupPercent,
        float $overpaymentRub,
        float $revenueRub,
    ): array {
        $nonLocalShare = $consideredOrders > 0
            ? round(($nonLocalOrders / $consideredOrders) * 100, 2)
            : 0.0;
        $effective = $revenueRub > 0
            ? round(($overpaymentRub / $revenueRub) * 100, 2)
            : 0.0;

        $figures = [
            'local_share_percent' => $localSharePercent,
            'non_local_share_percent' => $nonLocalShare,
            'gross_markup_percent' => round($grossMarkupPercent, 2),
            'effective_markup_percent' => $effective,
            'overpayment_rub' => round($overpaymentRub, 2),
        ];

        // Нет заказов, учитываемых в локальности.
        if ($consideredOrders <= 0) {
            return [
                'short' => 'Нет заказов, учитываемых в локальности, за период.',
                'full' => 'За выбранный период нет завершённых заказов, которые учитываются '
                    . 'в расчёте локальности (отменённые и невыкупленные исключаются).',
                'breakdown' => [],
                'figures' => $figures,
            ];
        }

        $localStr = $localSharePercent !== null ? $this->pct($localSharePercent) : '—';
        $nlStr = $this->pct($nonLocalShare);

        // Все продажи локальные.
        if ($nonLocalOrders <= 0) {
            return [
                'short' => "Все продажи локальные ({$localStr}) — нелокальной наценки нет.",
                'full' => "Локальность {$localStr}: все заказы отгружаются из кластера назначения, "
                    . 'поэтому Ozon не начисляет нелокальную наценку и переплаты за логистику нет.',
                'breakdown' => [],
                'figures' => $figures,
            ];
        }

        // Нелокальные заказы есть, но идут в кластеры с 0% наценкой.
        if ($overpaymentRub <= 0) {
            return [
                'short' => "Нелокальные заказы есть ({$nlStr}), но идут в кластеры с 0% наценкой — переплаты нет.",
                'full' => "Локальность {$localStr}, нелокальных заказов {$nlStr}. Они уходят в кластеры, "
                    . 'для которых ставка наценки Ozon равна 0% (постоянно или временно по акции), '
                    . 'поэтому переплаты за логистику не возникает.',
                'breakdown' => [],
                'figures' => $figures,
            ];
        }

        $grossStr = $this->pct($grossMarkupPercent);
        $effStr = $this->pct($effective);
        $rubStr = $this->rub($overpaymentRub);

        $short = "Локальность {$localStr}: наценка Ozon ~{$grossStr} начисляется только на {$nlStr} "
            . "нелокальных заказов → эффективно {$effStr} от выручки ({$rubStr}).";

        $full = "Высокая локальность и большая ставка наценки не противоречат друг другу — это про разное.\n\n"
            . "• Локальность {$localStr} — доля заказов, отгруженных из кластера назначения.\n"
            . "• Наценка ~{$grossStr} — средняя ставка Ozon по кластерам, куда уезжают нелокальные заказы "
            . "(по таблице 8–12%). Берётся только с {$nlStr} нелокальных заказов, а не со всей выручки.\n"
            . "• Эффективно {$effStr} — та же наценка, размазанная по всей выручке SKU "
            . "(≈ {$grossStr} × {$nlStr}). Именно эта величина ложится в логистику единицы и юнит-экономику.\n"
            . "• Переплата за логистику за период — {$rubStr}.";

        $breakdown = [
            [
                'value' => '8–12%',
                'label' => 'Ставка кластера назначения за один фактический нелокальный заказ',
                'scope' => 'один заказ',
            ],
            [
                'value' => "~{$grossStr}",
                'label' => 'Средняя ставка по нелокальным заказам SKU',
                'scope' => 'заголовок в таблице',
            ],
            [
                'value' => $effStr,
                'label' => 'Эффективно от выручки SKU — идёт в юнит-экономику единицы',
                'scope' => 'расчёт цены',
            ],
            [
                'value' => $rubStr,
                'label' => 'Переплата за логистику за период',
                'scope' => 'итог',
            ],
        ];

        return [
            'short' => $short,
            'full' => $full,
            'breakdown' => $breakdown,
            'figures' => $figures,
        ];
    }

    private function pct(float $value): string
    {
        $s = number_format($value, 2, ',', ' ');
        if (str_contains($s, ',')) {
            $s = rtrim(rtrim($s, '0'), ',');
        }

        return $s . '%';
    }

    private function rub(float $value): string
    {
        return number_format(round($value), 0, ',', ' ') . ' ₽';
    }
}
