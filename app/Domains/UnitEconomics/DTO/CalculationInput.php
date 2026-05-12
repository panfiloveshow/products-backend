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
        public readonly ?float $volumeWeight = null, // Объёмный вес, кг (для Ozon = volume_liters / 5)

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
        public readonly ?int $turnoverDays = null,
        public readonly ?int $sales7Days = null,
        public readonly ?string $routeKey = null,
        public readonly ?string $routeLabel = null,
        public readonly ?bool $isLocalSale = null,
        public readonly ?float $nonLocalMarkupPercent = null,
        public readonly ?string $tariffSource = null,
        public readonly ?string $tariffEffectiveFrom = null,
        public readonly ?string $priceSegment = null,
        public readonly ?string $routeResolutionStatus = null,
        public readonly ?string $localityResolutionStatus = null,
        public readonly ?string $calculationConfidence = null,
        public readonly ?string $profileSource = null,
        public readonly ?string $dominantClusterId = null,
        public readonly ?float $dominantClusterShare = null,
        public readonly ?float $expectedLocalityRate = null,
        public readonly ?float $weightedNonLocalMarkupPercent = null,
        public readonly array $clustersSummary = [],
        public readonly array $salesProfile = [],
        public readonly array $stockProfile = [],
        public readonly ?float $weightedLogisticsCost = null,
        public readonly ?string $orderDate = null,
        public readonly ?string $shippingClusterId = null,
        public readonly ?string $shippingClusterName = null,
        public readonly ?string $destinationClusterId = null,
        public readonly ?string $destinationClusterName = null,
        public readonly ?bool $fixationApplied = null,
        public readonly ?int $fixationId = null,
        public readonly ?string $fixationBaseDate = null,
        public readonly ?string $fixedUntil = null,
        public readonly ?string $tariffVersionUsed = null,
        public readonly ?string $markupVersionUsed = null,
        public readonly ?bool $markupApplied = null,
        public readonly ?string $markupReasonCode = null,
        public readonly ?string $markupReasonLabel = null,
        public readonly ?string $markupExceptionStatus = null,
        public readonly ?string $calculationMode = null,
        public readonly ?string $redemptionSource = null,
        public readonly ?int $ordersCount = null,
        public readonly ?int $deliveredCount = null,
        public readonly ?int $cancelledCount = null,
        public readonly ?int $notRedeemedCount = null,
        public readonly ?int $inFlightCount = null,
        public readonly ?int $returnsCount = null,

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
     * Получить тарифицируемый объём в литрах.
     *
     * В Ozon volume_weight хранится в кг и исторически считается как volume_liters / 5.
     * Для тарифной матрицы приводим его обратно к литрам и берём более консервативное
     * значение — max(габариты в литрах, volume_weight × 5).
     */
    public function getChargeableVolumeInLiters(): float
    {
        $volumeLiters = $this->getVolumeInLiters();
        $volumeByWeight = $this->volumeWeight !== null ? max(0.0, $this->volumeWeight * 5) : 0.0;

        return max($volumeLiters, $volumeByWeight);
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
            volumeWeight: isset($data['volume_weight']) ? (float) $data['volume_weight'] : null,
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
            turnoverDays: isset($data['turnover_days']) ? (int) $data['turnover_days'] : null,
            sales7Days: isset($data['sales_7_days']) ? (int) $data['sales_7_days'] : null,
            routeKey: $data['route_key'] ?? null,
            routeLabel: $data['route_label'] ?? null,
            isLocalSale: isset($data['is_local_sale']) ? (bool) $data['is_local_sale'] : null,
            nonLocalMarkupPercent: isset($data['non_local_markup_percent']) ? (float) $data['non_local_markup_percent'] : null,
            tariffSource: $data['tariff_source'] ?? null,
            tariffEffectiveFrom: $data['tariff_effective_from'] ?? null,
            priceSegment: $data['price_segment'] ?? null,
            routeResolutionStatus: $data['route_resolution_status'] ?? null,
            localityResolutionStatus: $data['locality_resolution_status'] ?? null,
            calculationConfidence: $data['calculation_confidence'] ?? null,
            profileSource: $data['profile_source'] ?? null,
            dominantClusterId: $data['dominant_cluster_id'] ?? null,
            dominantClusterShare: isset($data['dominant_cluster_share']) ? (float) $data['dominant_cluster_share'] : null,
            expectedLocalityRate: isset($data['expected_locality_rate']) ? (float) $data['expected_locality_rate'] : null,
            weightedNonLocalMarkupPercent: isset($data['weighted_non_local_markup_percent']) ? (float) $data['weighted_non_local_markup_percent'] : null,
            clustersSummary: isset($data['clusters_summary']) && is_array($data['clusters_summary']) ? $data['clusters_summary'] : [],
            salesProfile: isset($data['sales_profile']) && is_array($data['sales_profile']) ? $data['sales_profile'] : [],
            stockProfile: isset($data['stock_profile']) && is_array($data['stock_profile']) ? $data['stock_profile'] : [],
            weightedLogisticsCost: isset($data['weighted_logistics_cost']) ? (float) $data['weighted_logistics_cost'] : null,
            orderDate: $data['order_date'] ?? null,
            shippingClusterId: $data['shipping_cluster_id'] ?? null,
            shippingClusterName: $data['shipping_cluster_name'] ?? null,
            destinationClusterId: $data['destination_cluster_id'] ?? null,
            destinationClusterName: $data['destination_cluster_name'] ?? null,
            fixationApplied: isset($data['fixation_applied']) ? (bool) $data['fixation_applied'] : null,
            fixationId: isset($data['fixation_id']) ? (int) $data['fixation_id'] : null,
            fixationBaseDate: $data['fixation_base_date'] ?? null,
            fixedUntil: $data['fixed_until'] ?? null,
            tariffVersionUsed: $data['tariff_version_used'] ?? null,
            markupVersionUsed: $data['markup_version_used'] ?? null,
            markupApplied: isset($data['markup_applied']) ? (bool) $data['markup_applied'] : null,
            markupReasonCode: $data['markup_reason_code'] ?? null,
            markupReasonLabel: $data['markup_reason_label'] ?? null,
            markupExceptionStatus: $data['markup_exception_status'] ?? null,
            calculationMode: $data['calculation_mode'] ?? null,
            redemptionSource: $data['redemption_source'] ?? null,
            ordersCount: isset($data['orders_count']) ? (int) $data['orders_count'] : null,
            deliveredCount: isset($data['delivered_count']) ? (int) $data['delivered_count'] : null,
            cancelledCount: isset($data['cancelled_count']) ? (int) $data['cancelled_count'] : null,
            notRedeemedCount: isset($data['not_redeemed_count']) ? (int) $data['not_redeemed_count'] : null,
            inFlightCount: isset($data['in_flight_count']) ? (int) $data['in_flight_count'] : null,
            returnsCount: isset($data['returns_count']) ? (int) $data['returns_count'] : null,
            productName: $data['product_name'] ?? null,
        );
    }
}
