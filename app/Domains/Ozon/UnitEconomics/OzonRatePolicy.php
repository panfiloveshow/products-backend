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
     * Последняя миля: факт магазина, иначе тарифный потолок из конфига схемы.
     * Реально Ozon берёт 9-11 ₽ курьерской развозкой против тарифных 25 ₽.
     */
    public function lastMileCost(array $actualRates): ?float
    {
        return ($actualRates['last_mile_avg'] ?? null) !== null
            ? (float) $actualRates['last_mile_avg']
            : null;
    }

    /**
     * Хранение: фактический расход склада на проданную единицу. Без него сюда
     * попадала месячная сумма по остаткам — до 29 тыс ₽ «на единицу».
     * Схему учитывает калькулятор: платит только FBO.
     */
    public function storageCost(array $actualRates, float $fallback = 0.0): float
    {
        return ($actualRates['storage_per_unit'] ?? null) !== null
            ? (float) $actualRates['storage_per_unit']
            : $fallback;
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
     * ДРР: ручная настройка продавца приоритетнее факта. Факт — средний по
     * магазину (клики + «оплата за заказ»): per-SKU разбивки в транзакциях нет.
     */
    public function drrPercent(?float $manual, array $actualRates): float
    {
        if ($manual !== null && $manual > 0) {
            return $manual;
        }

        return (float) ($actualRates['ad_percent'] ?? 0);
    }
}
