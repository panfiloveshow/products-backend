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
        $apiScheme = match ($scheme) {
            'FBS' => 'fbs',
            'EDBS' => 'fbs_express',
            'DBS' => 'pickup',
            'DBW' => 'booking',
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

        foreach ($candidates as [$value, $source]) {
            if ($value !== null && is_numeric($value)) {
                return ['value' => (float) $value, 'source' => $source];
            }
        }

        return ['value' => 15.0, 'source' => 'default'];
    }
}
