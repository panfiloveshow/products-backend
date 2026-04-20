<?php

namespace App\Domains\YandexMarket\Tariffs;

use App\Domains\Marketplace\Contracts\TariffsProviderInterface;

/**
 * Тарифы логистики Yandex Market
 * 
 * Схемы работы:
 * - FBY: Fulfillment by Yandex (склад Яндекса)
 * - FBS: Fulfillment by Seller (свой склад, логистика Яндекса)
 * - DBS: Delivery by Seller (своя доставка)
 * - Express: Экспресс-доставка
 */
class YandexMarketTariffs implements TariffsProviderInterface
{
    /**
     * FBY тарифы логистики по весу
     */
    private const FBY_WEIGHT_TARIFFS = [
        ['max_weight' => 0.2, 'rate' => 60],
        ['max_weight' => 0.5, 'rate' => 70],
        ['max_weight' => 1.0, 'rate' => 85],
        ['max_weight' => 2.0, 'rate' => 100],
        ['max_weight' => 5.0, 'rate' => 130],
        ['max_weight' => 10.0, 'rate' => 180],
        ['max_weight' => 15.0, 'rate' => 230],
        ['max_weight' => 25.0, 'rate' => 300],
        ['max_weight' => PHP_FLOAT_MAX, 'rate' => 300, 'per_kg_above' => 15],
    ];

    /**
     * FBS тарифы логистики по весу
     */
    private const FBS_WEIGHT_TARIFFS = [
        ['max_weight' => 0.2, 'rate' => 50],
        ['max_weight' => 0.5, 'rate' => 60],
        ['max_weight' => 1.0, 'rate' => 75],
        ['max_weight' => 2.0, 'rate' => 90],
        ['max_weight' => 5.0, 'rate' => 120],
        ['max_weight' => 10.0, 'rate' => 170],
        ['max_weight' => 15.0, 'rate' => 220],
        ['max_weight' => 25.0, 'rate' => 280],
        ['max_weight' => PHP_FLOAT_MAX, 'rate' => 280, 'per_kg_above' => 12],
    ];

    /**
     * Обработка FBS (приёмка)
     */
    private const FBS_PROCESSING_FEE = 25.0;

    /**
     * Обработка возврата (фиксированная ставка)
     */
    private const RETURN_PROCESSING_FEE = 50.0;

    /**
     * Тариф хранения FBY (₽ за литр в день)
     */
    private const FBY_STORAGE_RATE_PER_LITER_PER_DAY = 0.08;

    /**
     * Коэффициенты хранения по оборачиваемости (дней на складе)
     */
    private const STORAGE_TURNOVER_COEFFICIENTS = [
        ['max_days' => 60,  'coefficient' => 1.0],
        ['max_days' => 120, 'coefficient' => 1.5],
        ['max_days' => 180, 'coefficient' => 2.0],
        ['max_days' => PHP_INT_MAX, 'coefficient' => 3.0],
    ];

    /**
     * Эквайринг
     */
    private const ACQUIRING_RATE = 2.0; // 2%

    /**
     * Получить тарифы логистики по схеме
     */
    public function getLogisticsTariffs(string $scheme): array
    {
        return match (strtoupper($scheme)) {
            'FBY' => [
                'scheme' => 'FBY',
                'weight_tariffs' => self::FBY_WEIGHT_TARIFFS,
                'acquiring_rate' => self::ACQUIRING_RATE,
            ],
            'FBS' => [
                'scheme' => 'FBS',
                'weight_tariffs' => self::FBS_WEIGHT_TARIFFS,
                'processing_fee' => self::FBS_PROCESSING_FEE,
                'acquiring_rate' => self::ACQUIRING_RATE,
            ],
            'DBS' => [
                'scheme' => 'DBS',
                'own_delivery' => true,
                'acquiring_rate' => self::ACQUIRING_RATE,
            ],
            'EXPRESS' => [
                'scheme' => 'EXPRESS',
                'weight_tariffs' => self::FBS_WEIGHT_TARIFFS,
                'express_multiplier' => 1.5,
                'acquiring_rate' => self::ACQUIRING_RATE,
            ],
            default => throw new \InvalidArgumentException("Unknown scheme: {$scheme}"),
        };
    }

    /**
     * Рассчитать стоимость логистики
     */
    public function calculateLogisticsCost(string $scheme, float $volume, float $weight, array $options = []): float
    {
        // Yandex Market использует вес (объёмный или фактический, что больше)
        $volumetricWeight = $volume / 5; // 1 литр = 0.2 кг объёмного веса
        $calculatedWeight = max($weight, $volumetricWeight);

        return match (strtoupper($scheme)) {
            'FBY' => $this->calculateFbyLogistics($calculatedWeight),
            'FBS' => $this->calculateFbsLogistics($calculatedWeight),
            'DBS' => $options['own_delivery_cost'] ?? 0,
            'EXPRESS' => $this->calculateExpressLogistics($calculatedWeight),
            default => 0,
        };
    }

    /**
     * Рассчитать FBY логистику
     */
    private function calculateFbyLogistics(float $weight): float
    {
        return $this->getWeightCost(self::FBY_WEIGHT_TARIFFS, $weight);
    }

    /**
     * Рассчитать FBS логистику
     */
    private function calculateFbsLogistics(float $weight): float
    {
        $logistics = $this->getWeightCost(self::FBS_WEIGHT_TARIFFS, $weight);
        return $logistics + self::FBS_PROCESSING_FEE;
    }

    /**
     * Рассчитать Express логистику
     */
    private function calculateExpressLogistics(float $weight): float
    {
        $baseCost = $this->getWeightCost(self::FBS_WEIGHT_TARIFFS, $weight);
        return $baseCost * 1.5; // Экспресс-множитель
    }

    /**
     * Получить стоимость по весу
     */
    private function getWeightCost(array $tariffs, float $weight): float
    {
        foreach ($tariffs as $tier) {
            if ($weight <= $tier['max_weight']) {
                $cost = $tier['rate'];
                
                if (isset($tier['per_kg_above'])) {
                    $baseWeight = 25;
                    $extraKg = max(0, $weight - $baseWeight);
                    $cost += $extraKg * $tier['per_kg_above'];
                }
                
                return $cost;
            }
        }

        return end($tariffs)['rate'];
    }

    /**
     * Получить коэффициенты
     */
    public function getCoefficients(string $scheme, array $options = []): array
    {
        return [
            'delivery_coefficient' => 1.0,
        ];
    }

    /**
     * Рассчитать обратную логистику (возврат)
     */
    public function calculateReturnLogisticsCost(string $scheme, float $weight): float
    {
        // Yandex Market: обратная логистика = базовый тариф
        return match (strtoupper($scheme)) {
            'FBY' => $this->getWeightCost(self::FBY_WEIGHT_TARIFFS, $weight),
            'FBS' => $this->getWeightCost(self::FBS_WEIGHT_TARIFFS, $weight),
            default => 0,
        };
    }

    /**
     * Стоимость обработки возврата (фиксированная)
     */
    public function getReturnProcessingFee(): float
    {
        return self::RETURN_PROCESSING_FEE;
    }

    /**
     * Рассчитать стоимость хранения FBY (за месяц)
     */
    public function calculateStorageCost(float $volumeLiters, int $turnoverDays = 30): float
    {
        if ($volumeLiters <= 0) {
            return 0;
        }

        $coefficient = 1.0;
        foreach (self::STORAGE_TURNOVER_COEFFICIENTS as $tier) {
            if ($turnoverDays <= $tier['max_days']) {
                $coefficient = $tier['coefficient'];
                break;
            }
        }

        return round($volumeLiters * self::FBY_STORAGE_RATE_PER_LITER_PER_DAY * 30 * $coefficient, 2);
    }
}
