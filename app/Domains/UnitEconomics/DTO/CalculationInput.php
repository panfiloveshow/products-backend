<?php

namespace App\Domains\UnitEconomics\DTO;

/**
 * Входные данные для расчёта юнит-экономики
 */
class CalculationInput
{
    public function __construct(
        // Идентификаторы
        public readonly string $sku,
        public readonly int $integrationId,
        public readonly string $marketplace,
        public readonly string $fulfillmentType,  // FBO, FBS, RFBS, EXPRESS
        
        // Цены
        public readonly float $price,
        public readonly ?float $oldPrice = null,
        
        // Габариты
        public readonly float $length = 0,       // см
        public readonly float $width = 0,        // см  
        public readonly float $height = 0,       // см
        public readonly float $weight = 0,       // кг
        
        // Себестоимость
        public readonly ?float $costPrice = null,
        public readonly ?float $packagingCost = null,
        public readonly ?float $additionalCosts = null,
        
        // Категория для комиссии
        public readonly ?string $categoryId = null,
        public readonly ?float $commissionRate = null,  // Если известна заранее
        
        // Доп. параметры
        public readonly ?string $warehouseId = null,
        public readonly ?float $redemptionRate = null,  // % выкупа (для расчёта возвратов)
        public readonly ?float $deliveryCoefficient = null,  // Коэффициент времени доставки (Ozon FBO)
        public readonly ?float $warehouseCoefficient = null,  // КС (коэффициент склада) — множитель логистики WB (1.0 = 100%, 1.4 = 140%)
        public readonly ?float $localizationIndex = null,  // ИЛ (индекс локализации) — множитель логистики WB (1.0 = без изменений)
        public readonly ?float $sppPercent = null,
        public readonly ?float $drrPercent = null,
        public readonly ?float $ourSharePercent = null,
        public readonly ?float $taxPercent = null,
        public readonly ?float $vatPercent = null,
        public readonly ?float $acquiringPercent = null,
        public readonly ?float $storageCost = null,
        public readonly ?float $additionalCommissionPercent = null,
        public readonly array $tariffBreakdown = [],
        public readonly ?float $ownDeliveryCost = null,
        public readonly ?float $ownReturnCost = null,
        public readonly ?float $marketplaceCompensation = null,
        public readonly ?float $acceptanceCost = null,
        public readonly ?float $penaltyCost = null,
        
        // Название товара (для отображения)
        public readonly ?string $productName = null,
    ) {}

    /**
     * Рассчитать объём в литрах
     */
    public function getVolumeInLiters(): float
    {
        return ($this->length * $this->width * $this->height) / 1000;
    }

    /**
     * Рассчитать объёмный вес (для сравнения с физическим)
     */
    public function getVolumetricWeight(): float
    {
        // Стандартный делитель 5000 для большинства маркетплейсов
        return ($this->length * $this->width * $this->height) / 5000;
    }

    /**
     * Получить расчётный вес (больший из физического и объёмного)
     */
    public function getCalculatedWeight(): float
    {
        return max($this->weight, $this->getVolumetricWeight());
    }

    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sku: $data['sku'],
            integrationId: $data['integration_id'],
            marketplace: $data['marketplace'],
            fulfillmentType: $data['fulfillment_type'] ?? 'FBO',
            price: (float) ($data['price'] ?? 0),
            oldPrice: isset($data['old_price']) ? (float) $data['old_price'] : null,
            length: (float) ($data['length'] ?? 0),
            width: (float) ($data['width'] ?? 0),
            height: (float) ($data['height'] ?? 0),
            weight: (float) ($data['weight'] ?? 0),
            costPrice: isset($data['cost_price']) ? (float) $data['cost_price'] : null,
            packagingCost: isset($data['packaging_cost']) ? (float) $data['packaging_cost'] : null,
            additionalCosts: isset($data['additional_costs']) ? (float) $data['additional_costs'] : null,
            categoryId: $data['category_id'] ?? null,
            commissionRate: isset($data['commission_rate']) ? (float) $data['commission_rate'] : null,
            warehouseId: $data['warehouse_id'] ?? null,
            redemptionRate: isset($data['redemption_rate']) ? (float) $data['redemption_rate'] : null,
            deliveryCoefficient: isset($data['delivery_coefficient']) ? (float) $data['delivery_coefficient'] : null,
            warehouseCoefficient: isset($data['warehouse_coefficient']) ? (float) $data['warehouse_coefficient'] : null,
            localizationIndex: isset($data['localization_index']) ? (float) $data['localization_index'] : null,
            sppPercent: isset($data['spp_percent']) ? (float) $data['spp_percent'] : null,
            drrPercent: isset($data['drr_percent']) ? (float) $data['drr_percent'] : null,
            ourSharePercent: isset($data['our_share_percent']) ? (float) $data['our_share_percent'] : null,
            taxPercent: isset($data['tax_percent']) ? (float) $data['tax_percent'] : null,
            vatPercent: isset($data['vat_percent']) ? (float) $data['vat_percent'] : null,
            acquiringPercent: isset($data['acquiring_percent']) ? (float) $data['acquiring_percent'] : null,
            storageCost: isset($data['storage_cost']) ? (float) $data['storage_cost'] : null,
            additionalCommissionPercent: isset($data['additional_commission_percent']) ? (float) $data['additional_commission_percent'] : null,
            tariffBreakdown: isset($data['tariff_breakdown']) && is_array($data['tariff_breakdown']) ? $data['tariff_breakdown'] : [],
            ownDeliveryCost: isset($data['own_delivery_cost']) ? (float) $data['own_delivery_cost'] : null,
            ownReturnCost: isset($data['own_return_cost']) ? (float) $data['own_return_cost'] : null,
            marketplaceCompensation: isset($data['marketplace_compensation']) ? (float) $data['marketplace_compensation'] : null,
            acceptanceCost: isset($data['acceptance_cost']) ? (float) $data['acceptance_cost'] : null,
            penaltyCost: isset($data['penalty_cost']) ? (float) $data['penalty_cost'] : null,
            productName: $data['product_name'] ?? null,
        );
    }
}
