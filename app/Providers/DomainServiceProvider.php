<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Contracts
use App\Domains\Marketplace\Contracts\MarketplaceInterface;
use App\Domains\Marketplace\Contracts\ProductsApiInterface;
use App\Domains\Marketplace\Contracts\InventoryApiInterface;
use App\Domains\Marketplace\Contracts\TariffsProviderInterface;
use App\Domains\Marketplace\Contracts\CommissionsProviderInterface;
use App\Domains\UnitEconomics\Contracts\UnitEconomicsCalculatorInterface;

// Wildberries implementations
use App\Domains\Wildberries\Api\ProductsApi as WildberriesProductsApi;
use App\Domains\Wildberries\Api\InventoryApi as WildberriesInventoryApi;
use App\Domains\Wildberries\Tariffs\WildberriesTariffs;
use App\Domains\Wildberries\Tariffs\CommissionCalculator as WildberriesCommissions;
use App\Domains\Wildberries\UnitEconomics\WildberriesUnitEconomicsCalculator;

// Ozon implementations
use App\Domains\Ozon\Api\ProductsApi as OzonProductsApi;
use App\Domains\Ozon\Api\InventoryApi as OzonInventoryApi;
use App\Domains\Ozon\Tariffs\OzonTariffs;
use App\Domains\Ozon\Tariffs\CommissionCalculator as OzonCommissions;
use App\Domains\Ozon\UnitEconomics\OzonUnitEconomicsCalculator;

// YandexMarket implementations
use App\Domains\YandexMarket\Api\ProductsApi as YandexProductsApi;
use App\Domains\YandexMarket\Api\InventoryApi as YandexInventoryApi;
use App\Domains\YandexMarket\Tariffs\YandexMarketTariffs;
use App\Domains\YandexMarket\Tariffs\CommissionCalculator as YandexCommissions;
use App\Domains\YandexMarket\UnitEconomics\YandexMarketUnitEconomicsCalculator;

// Orchestrator
use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;

// Supplies services
use App\Domains\Supplies\Services\SupplyCalculationService;
use App\Domains\Supplies\Services\SupplyOptimizationService;
use App\Domains\Supplies\Services\SupplyRecommendationService;

/**
 * Service Provider для доменных сервисов
 * 
 * Регистрирует все маркетплейсы и их компоненты
 */
class DomainServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Singleton для оркестратора
        $this->app->singleton(UnitEconomicsOrchestrator::class, function ($app) {
            return new UnitEconomicsOrchestrator();
        });

        // Singletons для тарифов
        $this->app->singleton(WildberriesTariffs::class);
        $this->app->singleton(OzonTariffs::class);
        $this->app->singleton(YandexMarketTariffs::class);

        // Singletons для комиссий
        $this->app->singleton(WildberriesCommissions::class);
        $this->app->singleton(OzonCommissions::class);
        $this->app->singleton(YandexCommissions::class);

        // Singletons для калькуляторов
        $this->app->singleton(WildberriesUnitEconomicsCalculator::class);
        $this->app->singleton(OzonUnitEconomicsCalculator::class);
        $this->app->singleton(YandexMarketUnitEconomicsCalculator::class);

        // Алиасы для быстрого доступа
        $this->app->alias(UnitEconomicsOrchestrator::class, 'unit-economics');

        // Supplies services
        $this->app->singleton(SupplyCalculationService::class);
        $this->app->singleton(SupplyOptimizationService::class);
        $this->app->singleton(SupplyRecommendationService::class, function ($app) {
            return new SupplyRecommendationService(
                $app->make(SupplyCalculationService::class),
                $app->make(SupplyOptimizationService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            UnitEconomicsOrchestrator::class,
            WildberriesTariffs::class,
            OzonTariffs::class,
            YandexMarketTariffs::class,
            WildberriesCommissions::class,
            OzonCommissions::class,
            YandexCommissions::class,
            WildberriesUnitEconomicsCalculator::class,
            OzonUnitEconomicsCalculator::class,
            YandexMarketUnitEconomicsCalculator::class,
            SupplyCalculationService::class,
            SupplyOptimizationService::class,
            SupplyRecommendationService::class,
        ];
    }
}
