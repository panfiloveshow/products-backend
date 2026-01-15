<?php

namespace App\Domains\UnitEconomics\Contracts;

use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;
use App\Domains\UnitEconomics\DTO\CalculationInput;

/**
 * Интерфейс калькулятора юнит-экономики
 */
interface UnitEconomicsCalculatorInterface
{
    /**
     * Рассчитать юнит-экономику для товара
     * 
     * @param CalculationInput $input Входные данные для расчёта
     * @return UnitEconomicsResult Результат расчёта
     */
    public function calculate(CalculationInput $input): UnitEconomicsResult;

    /**
     * Получить код маркетплейса
     */
    public function getMarketplace(): string;

    /**
     * Получить поддерживаемые схемы работы
     * @return string[]
     */
    public function getSupportedSchemes(): array;
}
