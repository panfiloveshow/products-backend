<?php

namespace App\Domains\Wildberries\UnitEconomics;

final class WildberriesCommissionResolver
{
    public static function resolve(
        array $wbData,
        ?array $subjectCommission,
        string $fulfillmentType,
        array $integrationSettings = []
    ): float {
        return self::resolveWithSource($wbData, $subjectCommission, $fulfillmentType, $integrationSettings)['value'];
    }

    /** @return array{value: float, source: string} */
    public static function resolveWithSource(
        array $wbData,
        ?array $subjectCommission,
        string $fulfillmentType,
        array $integrationSettings = []
    ): array {
        $scheme = strtoupper($fulfillmentType);
        $wbDataScheme = match ($scheme) {
            'FBS' => 'fbs',
            'EDBS' => 'edbs',
            'DBS' => 'dbs',
            'DBW' => 'dbw',
            default => 'fbo',
        };
        // DBS и DBW у WB — одна колонка комиссии «Витрина/Курьер WB» (kgvpSupplier).
        $apiScheme = match ($scheme) {
            'FBS' => 'fbs',
            'EDBS' => 'fbs_express',
            'DBS' => 'dbs',
            'DBW' => 'dbs',
            default => 'fbo',
        };

        $candidates = [
            [data_get($subjectCommission, $apiScheme), 'wb_commission_snapshot_scheme'],
            [data_get($subjectCommission, 'fbo'), 'wb_commission_snapshot_fbo'],
            [data_get($wbData, "commissions.{$wbDataScheme}.percent"), 'wb_product_commission_scheme'],
            [data_get($wbData, 'commissions.fbo.percent'), 'wb_product_commission_fbo'],
            [data_get($wbData, 'commissions.fbs.percent'), 'wb_product_commission_fbs'],
            [$wbData['commission_percent'] ?? null, 'wb_product_commission_legacy'],
            [$integrationSettings['wb_commission_percent'] ?? null, 'integration_setting'],
            [15, 'default'],
        ];

        // Платные опции WB (например, расширенный контент) добавляют п.п. ко ВСЕМ
        // комиссиям кабинета — настройка интеграции, поверх любого источника.
        $extraPp = (float) ($integrationSettings['wb_commission_extra_pp'] ?? 0);

        foreach ($candidates as [$value, $source]) {
            if ($value !== null && is_numeric($value)) {
                return [
                    'value' => (float) $value + $extraPp,
                    'source' => $extraPp !== 0.0 ? $source.'+extra_pp' : $source,
                ];
            }
        }

        return ['value' => 15.0 + $extraPp, 'source' => $extraPp !== 0.0 ? 'default+extra_pp' : 'default'];
    }
}
