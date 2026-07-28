<?php

namespace Tests\Unit;

use App\Domains\Wildberries\UnitEconomics\WildberriesCommissionResolver;
use PHPUnit\Framework\TestCase;

class WildberriesCommissionResolverTest extends TestCase
{
    /**
     * Снапшот комиссий в формате getCommissions() после фикса маппинга:
     * fbo = paidStorageKgvp («Склад WB»), fbs = kgvpMarketplace («Маркетплейс»),
     * dbs = kgvpSupplier («Витрина/Курьер WB»), fbs_express = EDBS.
     */
    private array $subjectCommission = [
        'fbo' => 25.5,
        'fbs' => 30.0,
        'dbs' => 4.5,
        'fbs_express' => 3.0,
        'pickup' => 8.0,
        'booking' => 7.0,
        'paid_storage' => 25.5,
    ];

    public function test_resolves_scheme_specific_commission_from_snapshot(): void
    {
        $expected = [
            'FBO' => 25.5,
            'FBS' => 30.0,
            'EDBS' => 3.0,
            'DBS' => 4.5,
            // Курьер WB (DBW) делит колонку комиссии с Витриной (DBS)
            'DBW' => 4.5,
        ];

        foreach ($expected as $scheme => $percent) {
            $result = WildberriesCommissionResolver::resolveWithSource([], $this->subjectCommission, $scheme);
            $this->assertSame($percent, $result['value'], "схема {$scheme}");
            $this->assertSame('wb_commission_snapshot_scheme', $result['source'], "схема {$scheme}");
        }
    }

    public function test_falls_back_to_fbo_when_scheme_key_missing(): void
    {
        $result = WildberriesCommissionResolver::resolveWithSource([], ['fbo' => 25.5], 'DBS');

        $this->assertSame(25.5, $result['value']);
        $this->assertSame('wb_commission_snapshot_fbo', $result['source']);
    }

    public function test_falls_back_to_wb_data_scheme_commission_without_snapshot(): void
    {
        $wbData = [
            'commissions' => [
                'fbo' => ['percent' => 25.5],
                'dbs' => ['percent' => 4.5],
            ],
        ];

        $result = WildberriesCommissionResolver::resolveWithSource($wbData, null, 'DBS');

        $this->assertSame(4.5, $result['value']);
        $this->assertSame('wb_product_commission_scheme', $result['source']);
    }
}
