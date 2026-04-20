<?php

namespace App\Domains\Ozon\Tariffs;

use App\Domains\Marketplace\Contracts\TariffsProviderInterface;

/**
 * Тарифы логистики Ozon
 * 
 * Актуальные тарифы с 10 декабря 2025
 * 
 * Схемы работы:
 * - FBO: Склад Ozon, логистика Ozon
 * - FBS: Ваш склад, логистика Ozon
 * - RFBS: realFBS Standard (своя логистика, вся Россия)
 * - EXPRESS: realFBS Express (экспресс 0-25 км от склада)
 */
class OzonTariffs implements TariffsProviderInterface
{
    private OzonPricingMatrix $pricing;

    public function __construct()
    {
        $this->pricing = new OzonPricingMatrix();
    }

    /**
     * FBO тарифы логистики — прогрессивная шкала (с 10.12.2025)
     * Для товаров от 301₽:
     * - 0-1л: 46.77₽ (первый литр)
     * - 1-3л: +10.17₽ за каждый доп. литр
     * - 3-190л: +15.25₽ за каждый доп. литр
     * - 190-1000л: +6.10₽ за каждый доп. литр
     * - >1000л: 7859.86₽ фиксировано
     * 
     * Для товаров до 300₽: 17.28₽/л
     */
    private const FBO_FIRST_LITER = 46.77;
    private const FBO_PER_LITER_1_3 = 10.17;
    private const FBO_PER_LITER_3_190 = 15.25;
    private const FBO_PER_LITER_190_1000 = 6.10;
    private const FBO_FIXED_OVER_1000 = 7859.86;
    private const FBO_CHEAP_RATE_PER_LITER = 17.28; // Для товаров до 300₽

    /**
     * FBS тарифы логистики по объёму (литры) для товаров от 301₽
     */
    private const FBS_VOLUME_TARIFFS = [
        ['max_volume' => 1.0, 'rate' => 81.34],
        ['max_volume' => 2.0, 'rate' => 99.64],
        ['max_volume' => 3.0, 'rate' => 117.94],
        ['max_volume' => 190.0, 'rate' => 117.94, 'per_liter_above' => 23.39, 'base_volume' => 3.0],
        ['max_volume' => 1000.0, 'rate' => 4494.04, 'per_liter_above' => 6.10, 'base_volume' => 190.0],
        ['max_volume' => PHP_FLOAT_MAX, 'rate' => 9432.87], // свыше 1000л — фикс
    ];

    /**
     * Обработка отправления FBS (за литр)
     */
    private const FBS_PROCESSING_TARIFFS = [
        ['max_volume' => 1000, 'rate' => 1.9],
        ['max_volume' => 4000, 'rate' => 1.7],
        ['max_volume' => 16000, 'rate' => 1.5],
        ['max_volume' => PHP_FLOAT_MAX, 'rate' => 1.3],
    ];

    /**
     * Базовая обработка FBS
     */
    private const FBS_PROCESSING_BASE = 20.0; // ₽ за отправление

    /**
     * Последняя миля (макс)
     */
    private const LAST_MILE_MAX = 25.0;

    /**
     * Эквайринг
     */
    private const ACQUIRING_RATE = 1.5; // 1.5%

    /**
     * Агентское вознаграждение (RFBS/EXPRESS)
     */
    private const AGENT_FEE = 20.0;

    /**
     * Возмещение за экспресс-доставку (EXPRESS своя служба)
     */
    private const EXPRESS_COMPENSATION = 199.0;

    /**
     * Обработка возврата
     */
    private const RETURN_PROCESSING = 15.0;

    /**
     * Получить тарифы логистики по схеме
     */
    public function getLogisticsTariffs(string $scheme): array
    {
        $scheme = strtoupper($scheme);
        $routes = $this->pricing->getConfig()['routes'] ?? [];
        $schemeCosts = $this->pricing->getSchemeCosts($scheme);

        if (!empty($routes)) {
            return [
                'scheme' => $scheme,
                'routing_mode' => 'route_matrix',
                'effective_from' => $this->pricing->getEffectiveFrom(),
                'routes' => array_map(
                    fn (array $route) => [
                        'label' => $route['label'] ?? null,
                        'is_local_sale' => $route['is_local_sale'] ?? null,
                        'non_local_markup_percent' => $route['non_local_markup_percent'] ?? null,
                        'tariffs' => $route[$scheme] ?? [],
                    ],
                    $routes
                ),
                'last_mile_max' => $schemeCosts['last_mile'] ?? 0,
                'processing_fee' => $schemeCosts['processing_fee'] ?? 0,
                'return_processing' => $schemeCosts['return_processing'] ?? 0,
            ];
        }

        return match (strtoupper($scheme)) {
            'FBO' => [
                'scheme' => 'FBO',
                'first_liter' => self::FBO_FIRST_LITER,
                'per_liter_1_3' => self::FBO_PER_LITER_1_3,
                'per_liter_3_190' => self::FBO_PER_LITER_3_190,
                'per_liter_190_1000' => self::FBO_PER_LITER_190_1000,
                'fixed_over_1000' => self::FBO_FIXED_OVER_1000,
                'cheap_rate_per_liter' => self::FBO_CHEAP_RATE_PER_LITER,
                'last_mile_max' => self::LAST_MILE_MAX,
                'acquiring_rate' => self::ACQUIRING_RATE,
                'has_coefficient' => true,
                'has_additional_percent' => true,
            ],
            'FBS' => [
                'scheme' => 'FBS',
                'volume_tariffs' => self::FBS_VOLUME_TARIFFS,
                'processing_base' => self::FBS_PROCESSING_BASE,
                'processing_tariffs' => self::FBS_PROCESSING_TARIFFS,
                'last_mile_max' => self::LAST_MILE_MAX,
                'acquiring_rate' => self::ACQUIRING_RATE,
                'has_coefficient' => false,
            ],
            'RFBS' => [
                'scheme' => 'RFBS',
                'agent_fee' => self::AGENT_FEE,
                'acquiring_rate' => self::ACQUIRING_RATE,
                'own_delivery' => true,
            ],
            'EXPRESS' => [
                'scheme' => 'EXPRESS',
                'agent_fee' => self::AGENT_FEE,
                'express_compensation' => self::EXPRESS_COMPENSATION,
                'acquiring_rate' => self::ACQUIRING_RATE,
                'own_delivery' => true,
            ],
            default => throw new \InvalidArgumentException("Unknown scheme: {$scheme}"),
        };
    }

    /**
     * Рассчитать стоимость логистики
     */
    public function calculateLogisticsCost(string $scheme, float $volume, float $weight, array $options = []): float
    {
        return match (strtoupper($scheme)) {
            'FBO' => $this->calculateFboLogistics($volume, $options),
            'FBS' => $this->calculateFbsLogistics($volume, $options),
            'RFBS' => $options['own_delivery_cost'] ?? 0, // Своя доставка
            'EXPRESS' => $options['own_delivery_cost'] ?? 0, // Своя доставка
            default => 0,
        };
    }

    /**
     * Получить коэффициенты для расчёта логистики.
     */
    public function getCoefficients(string $scheme, array $options = []): array
    {
        return match (strtoupper($scheme)) {
            'FBO' => [
                'delivery_coefficient' => (float) ($options['delivery_coefficient'] ?? $options['localization_index'] ?? 1.0),
                'additional_percent' => (float) ($options['additional_percent'] ?? $options['localization_additional_percent'] ?? 0.0),
            ],
            default => [
                'delivery_coefficient' => 1.0,
                'additional_percent' => 0.0,
            ],
        };
    }

    /**
     * Получить стоимость последней мили.
     */
    public function getLastMileCost(string $scheme, array $options = []): float
    {
        return match (strtoupper($scheme)) {
            'FBO', 'FBS' => min((float) ($options['last_mile'] ?? self::LAST_MILE_MAX), self::LAST_MILE_MAX),
            default => 0.0,
        };
    }

    /**
     * Получить стоимость обработки отправления.
     */
    public function getProcessingFee(string $scheme, float $volume): float
    {
        if (strtoupper($scheme) !== 'FBS') {
            return 0.0;
        }

        return self::FBS_PROCESSING_BASE + $this->getProcessingCost($volume);
    }

    /**
     * Получить стоимость обработки возврата.
     */
    public function getReturnProcessingFee(string $scheme): float
    {
        return in_array(strtoupper($scheme), ['FBO', 'FBS'], true) ? self::RETURN_PROCESSING : 0.0;
    }

    /**
     * Рассчитать FBO логистику (прогрессивная шкала с 10.12.2025)
     */
    private function calculateFboLogistics(float $volume, array $options = []): float
    {
        $coefficient = $options['delivery_coefficient'] ?? 1.0;
        $additionalPercent = $options['additional_percent'] ?? 0;
        $price = $options['price'] ?? 0;

        // Базовый тариф по прогрессивной шкале
        $baseCost = $this->calculateFboBaseCost(ceil($volume), $price);
        
        // Применяем коэффициент времени доставки
        $logisticsCost = $baseCost * $coefficient;
        
        // Доп. % от цены
        $additionalCost = $price * ($additionalPercent / 100);

        return $logisticsCost + $additionalCost;
    }

    /**
     * Базовый тариф FBO по прогрессивной шкале (с 10.12.2025)
     */
    private function calculateFboBaseCost(float $volume, float $price = 0): float
    {
        if ($volume <= 0) {
            return 0;
        }

        // Для товаров до 300₽ — фиксированный тариф за литр
        if ($price > 0 && $price <= 300) {
            return $volume * self::FBO_CHEAP_RATE_PER_LITER;
        }

        // Свыше 1000л — фиксированная ставка
        if ($volume > 1000) {
            return self::FBO_FIXED_OVER_1000;
        }

        $cost = self::FBO_FIRST_LITER; // Первый литр

        if ($volume > 1) {
            $liters_1_3 = min($volume - 1, 2);
            $cost += $liters_1_3 * self::FBO_PER_LITER_1_3;
        }

        if ($volume > 3) {
            $liters_3_190 = min($volume - 3, 187);
            $cost += $liters_3_190 * self::FBO_PER_LITER_3_190;
        }

        if ($volume > 190) {
            $liters_190_1000 = $volume - 190;
            $cost += $liters_190_1000 * self::FBO_PER_LITER_190_1000;
        }

        return round($cost, 2);
    }

    /**
     * Рассчитать FBS логистику
     */
    private function calculateFbsLogistics(float $volume, array $options = []): float
    {
        return $this->getVolumeCost(self::FBS_VOLUME_TARIFFS, $volume);
    }

    /**
     * Получить стоимость по таблице тарифов
     */
    private function getVolumeCost(array $tariffs, float $volume): float
    {
        foreach ($tariffs as $tier) {
            if ($volume <= $tier['max_volume']) {
                $cost = $tier['rate'];
                
                if (isset($tier['per_liter_above']) && isset($tier['base_volume'])) {
                    $extraLiters = max(0, $volume - $tier['base_volume']);
                    $cost += $extraLiters * $tier['per_liter_above'];
                } elseif (isset($tier['per_liter_above'])) {
                    $prevMaxVolume = 20;
                    $extraLiters = max(0, $volume - $prevMaxVolume);
                    $cost += $extraLiters * $tier['per_liter_above'];
                }
                
                return $cost;
            }
        }

        return end($tariffs)['rate'];
    }

    /**
     * Получить стоимость обработки FBS
     */
    private function getProcessingCost(float $volume): float
    {
        foreach (self::FBS_PROCESSING_TARIFFS as $tier) {
            if ($volume <= $tier['max_volume']) {
                return $volume * $tier['rate'];
            }
        }
        return $volume * 1.3;
    }

    /**
     * Рассчитать обратную логистику (возврат)
     * Ozon: базовый тариф БЕЗ коэффициента + 15₽ обработка
     */
    public function calculateReturnLogisticsCost(string $scheme, float $volume): float
    {
        return match (strtoupper($scheme)) {
            'FBO' => $this->calculateFboBaseCost(ceil($volume)),
            'FBS' => $this->getVolumeCost(self::FBS_VOLUME_TARIFFS, $volume),
            default => 0,
        };
    }

    /**
     * Получить агентское вознаграждение
     */
    public function getAgentFee(string $scheme): float
    {
        return in_array(strtoupper($scheme), ['RFBS', 'EXPRESS']) ? self::AGENT_FEE : 0;
    }

    /**
     * Получить возмещение за экспресс
     */
    public function getExpressCompensation(): float
    {
        return self::EXPRESS_COMPENSATION;
    }
}
