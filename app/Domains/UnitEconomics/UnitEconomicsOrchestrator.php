<?php

namespace App\Domains\UnitEconomics;

use App\Domains\UnitEconomics\Contracts\UnitEconomicsCalculatorInterface;
use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\DTO\UnitEconomicsResult;
use App\Domains\Wildberries\UnitEconomics\WildberriesUnitEconomicsCalculator;
use App\Domains\Ozon\UnitEconomics\OzonUnitEconomicsCalculator;
use App\Domains\YandexMarket\UnitEconomics\YandexMarketUnitEconomicsCalculator;

/**
 * Оркестратор юнит-экономики
 * 
 * Выбирает правильный калькулятор по маркетплейсу и делегирует расчёт
 */
class UnitEconomicsOrchestrator
{
    /**
     * @var array<string, UnitEconomicsCalculatorInterface>
     */
    private array $calculators = [];

    public function __construct()
    {
        // Регистрируем калькуляторы
        $this->registerCalculator(new WildberriesUnitEconomicsCalculator());
        $this->registerCalculator(new OzonUnitEconomicsCalculator());
        $this->registerCalculator(new YandexMarketUnitEconomicsCalculator());
    }

    /**
     * Зарегистрировать калькулятор
     */
    public function registerCalculator(UnitEconomicsCalculatorInterface $calculator): void
    {
        $this->calculators[$calculator->getMarketplace()] = $calculator;
    }

    /**
     * Рассчитать юнит-экономику
     */
    public function calculate(CalculationInput $input): UnitEconomicsResult
    {
        $marketplace = strtolower($input->marketplace);
        
        if (!isset($this->calculators[$marketplace])) {
            throw new \InvalidArgumentException("Unknown marketplace: {$marketplace}");
        }

        return $this->calculators[$marketplace]->calculate($input);
    }

    /**
     * Рассчитать для массива товаров
     * 
     * @param CalculationInput[] $inputs
     * @return UnitEconomicsResult[]
     */
    public function calculateBatch(array $inputs): array
    {
        $results = [];
        
        foreach ($inputs as $input) {
            try {
                $results[] = $this->calculate($input);
            } catch (\Exception $e) {
                // Логируем ошибку, но продолжаем
                \Log::warning('Failed to calculate unit economics', [
                    'sku' => $input->sku,
                    'marketplace' => $input->marketplace,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Получить калькулятор по маркетплейсу
     */
    public function getCalculator(string $marketplace): ?UnitEconomicsCalculatorInterface
    {
        return $this->calculators[strtolower($marketplace)] ?? null;
    }

    /**
     * Получить поддерживаемые маркетплейсы
     */
    public function getSupportedMarketplaces(): array
    {
        return array_keys($this->calculators);
    }

    /**
     * Получить поддерживаемые схемы для маркетплейса
     */
    public function getSupportedSchemes(string $marketplace): array
    {
        $calculator = $this->getCalculator($marketplace);
        return $calculator?->getSupportedSchemes() ?? [];
    }

    /**
     * Рассчитать для всех схем маркетплейса
     * 
     * @return array<string, UnitEconomicsResult> [scheme => result]
     */
    public function calculateAllSchemes(CalculationInput $input): array
    {
        $schemes = $this->getSupportedSchemes($input->marketplace);
        $results = [];

        foreach ($schemes as $scheme) {
            $schemeInput = new CalculationInput(
                sku: $input->sku,
                integrationId: $input->integrationId,
                marketplace: $input->marketplace,
                fulfillmentType: $scheme,
                price: $input->price,
                oldPrice: $input->oldPrice,
                length: $input->length,
                width: $input->width,
                height: $input->height,
                weight: $input->weight,
                costPrice: $input->costPrice,
                packagingCost: $input->packagingCost,
                additionalCosts: $input->additionalCosts,
                categoryId: $input->categoryId,
                commissionRate: $input->commissionRate,
                warehouseId: $input->warehouseId,
                redemptionRate: $input->redemptionRate,
                deliveryCoefficient: $input->deliveryCoefficient,
                warehouseCoefficient: $input->warehouseCoefficient,
                localizationIndex: $input->localizationIndex,
                sppPercent: $input->sppPercent,
                drrPercent: $input->drrPercent,
                ourSharePercent: $input->ourSharePercent,
                taxPercent: $input->taxPercent,
                vatPercent: $input->vatPercent,
                acquiringPercent: $input->acquiringPercent,
                storageCost: $input->storageCost,
                additionalCommissionPercent: $input->additionalCommissionPercent,
                tariffBreakdown: $input->tariffBreakdown,
                ownDeliveryCost: $input->ownDeliveryCost,
                ownReturnCost: $input->ownReturnCost,
                marketplaceCompensation: $input->marketplaceCompensation,
                acceptanceCost: $input->acceptanceCost,
                penaltyCost: $input->penaltyCost,
                productName: $input->productName,
            );

            try {
                $results[$scheme] = $this->calculate($schemeInput);
            } catch (\Exception $e) {
                \Log::warning("Failed to calculate scheme {$scheme}", [
                    'sku' => $input->sku,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }
}
