<?php

namespace App\Domains\Locality\Legacy;

use App\Services\Ozon\OzonLocalityService;

/**
 * Изолирует новый bounded context от прямых вызовов legacy-сервисов.
 * Любая доработка legacy идёт строго через этот фасад — это делает точку смены дешёвой
 * в будущем (когда legacy окончательно мигрируем внутрь Domains/Locality).
 */
class LegacyLocalityFacade
{
    public function __construct(
        private readonly OzonLocalityService $localityService = new OzonLocalityService(),
    ) {
    }

    /** @return array<string,array> keyed by SKU */
    public function demandForIntegration(int $integrationId, int $periodDays = 28): array
    {
        return $this->localityService->resolveIntegrationLocality($integrationId, $periodDays);
    }

    /** @return array{clusters_summary: array, sales_profile: array, stock_profile: array, locality_rate: float, total_orders: int} */
    public function localityForSku(int $integrationId, string $sku, int $periodDays = 28): array
    {
        return $this->localityService->resolveSkuLocality($integrationId, $sku, $periodDays);
    }

    /** @return array<string,array> keyed by SKU */
    public function shippingRoutesForIntegration(int $integrationId, int $periodDays = 28): array
    {
        return $this->localityService->buildShippingRoutesForIntegration($integrationId, $periodDays);
    }

    public function countSellerFboOrders7Days(int $integrationId): int
    {
        return $this->localityService->countSellerFboOrders7Days($integrationId);
    }
}
