<?php

namespace App\Domains\Ozon\UnitEconomics;

use App\Services\Ozon\OzonActualRatesService;

/**
 * Единое место, где решается, какое значение ставки Ozon попадёт в расчёт.
 *
 * Вход для калькулятора собирают два движка с разными источниками: синк
 * (`SyncUnitEconomicsCommand`) работает со свежими ответами API, а
 * `UnitEconomicsCacheService` — с сохранёнными данными. Формула у них общая,
 * а вот приоритеты значений раньше были продублированы, и правка в одном
 * движке молча не долетала до другого: карточка показывала тарифные 25 ₽
 * последней мили и 1.5% эквайринга, когда синк уже считал по факту.
 *
 * Здесь только политика приоритетов — источники остаются на стороне движков.
 */
class OzonRatePolicy
{
    public const DEFAULT_ACQUIRING_PERCENT = 1.5;

    public function __construct(
        private readonly OzonActualRatesService $actualRates = new OzonActualRatesService(),
    ) {
    }

    /**
     * Фактические ставки магазина по транзакциям. Пустой массив — не Ozon.
     *
     * @return array<string, mixed>
     */
    public function actualRates(string $marketplace, ?int $integrationId): array
    {
        if ($marketplace !== 'ozon' || ! $integrationId) {
            return [];
        }

        return $this->actualRates->forIntegration($integrationId);
    }

    /**
     * Эквайринг: факт по транзакциям → сохранённое значение → дефолт 1.5%.
     * Ozon списывает 0.71-1.08%, поэтому дефолт систематически завышал расход.
     */
    public function acquiringPercent(array $actualRates, ?float $stored = null, float $default = self::DEFAULT_ACQUIRING_PERCENT): float
    {
        if (($actualRates['acquiring_percent'] ?? null) !== null) {
            return (float) $actualRates['acquiring_percent'];
        }

        return $stored !== null && $stored > 0 ? $stored : $default;
    }

    /**
     * Последняя миля: факт по ЭТОМУ SKU (транзакции за 28 дней) → среднее по
     * магазину (когда у SKU меньше 3 продаж) → тарифный потолок из конфига схемы.
     * У Ozon миля зависит от цены товара, поэтому сначала всегда per-SKU факт.
     *
     * @param array<int, mixed> $skuCandidates числовые SKU Ozon товара (sku/fbo_sku/fbs_sku)
     */
    public function lastMileCost(array $actualRates, ?int $integrationId = null, array $skuCandidates = []): ?float
    {
        if ($integrationId !== null && $skuCandidates !== []) {
            $perSku = $this->actualRates->lastMilePerSku($integrationId);
            foreach ($skuCandidates as $candidate) {
                $key = (string) $candidate;
                if ($key !== '' && isset($perSku[$key])) {
                    return $perSku[$key];
                }
            }
        }

        return ($actualRates['last_mile_avg'] ?? null) !== null
            ? (float) $actualRates['last_mile_avg']
            : null;
    }

    /**
     * Хранение Ozon: ТОЛЬКО per-SKU факт из отчёта placement/by-products
     * (products.storage_cost_per_unit, пишет SyncStorageCostJob), иначе 0.
     * Размазанное среднее по магазину запрещено: платят единицы SKU (в НБК ЧЕКИ —
     * 4 из 50), а смир вешал одинаковую цифру на все строки. Транзакция хранения
     * приходит одной суммой на магазин в день — из неё per-SKU не собрать.
     * Не-Ozon движки приходят с пустыми actualRates и живут своим fallback
     * (WB/YM считают хранение из своих тарифов по объёму).
     */
    public function storageCost(array $actualRates, float $fallback = 0.0, ?float $perSkuPerUnit = null): float
    {
        if ($actualRates === []) {
            return $fallback;
        }

        return $perSkuPerUnit !== null && $perSkuPerUnit > 0 ? $perSkuPerUnit : 0.0;
    }

    /**
     * Ставка вознаграждения по схеме из ответа Ozon (`ozon_data.commissions`).
     *
     * У EXPRESS собственная ставка, и она заметно выше: по BR2101BK Ozon отдаёт
     * express 43% против rfbs 31%. Синк подставлял EXPRESS ставку RFBS и занижал
     * комиссию на 441 строке каталога, а кэш читал правильную — сверка двух
     * движков это и вскрыла.
     *
     * @param array<string, mixed> $commissions ozon_data.commissions
     * @param array<string, float|null> $overrides ставки из API цен, приоритетнее сохранённых
     */
    public function commissionPercentForScheme(string $scheme, array $commissions, array $overrides = []): ?float
    {
        $key = match (strtoupper($scheme)) {
            'FBO' => 'fbo',
            'FBS' => 'fbs',
            // realFBS и DBS Ozon отдаёт под ключом rfbs — так же их сводит кэш-сервис.
            'RFBS', 'REALFBS', 'DBS' => 'rfbs',
            'EXPRESS' => 'express',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $value = $overrides[$key]
            ?? $commissions[$key]['percent']
            // Ozon не всегда отдаёт ставку по каждой схеме: express добираем из
            // rfbs (обе — realFBS), остальные из fbs/fbo.
            ?? ($key === 'express' ? ($commissions['rfbs']['percent'] ?? null) : null)
            ?? $commissions['fbs']['percent']
            ?? $commissions['fbo']['percent']
            ?? null;

        return $value !== null ? (float) $value : null;
    }

    /**
     * ДРР: только ручная настройка продавца. Средний по магазину ad_percent
     * больше НЕ подставляем: он вешал одинаковый «рекламный налог» на каждый
     * товар, а после загрузки отчёта Performance per-SKU факт его снимал — и
     * маржа «росла» от самого факта синка. Per-SKU реклама приходит из отчёта
     * Performance (фронт + advertising-impact), не из этого дефолта.
     */
    public function drrPercent(?float $manual, array $actualRates): float
    {
        return $manual !== null && $manual > 0 ? $manual : 0.0;
    }
}
