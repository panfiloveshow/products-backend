<?php

namespace Tests\Unit;

use App\Services\Manager\DecimalMoney;
use App\Services\Manager\ManagerDataException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManagerPriceMoneyTest extends TestCase
{
    public static function amounts(): array
    {
        return [['0', 0, '0.00'], ['12.3', 1230, '12.30'], [1081.38, 108138, '1081.38'], ['1000000000', 100000000000, '1000000000.00'], ['0.01', 1, '0.01']];
    }

    #[DataProvider('amounts')]
    public function test_amounts_keep_kopecks_without_rounding(mixed $value, int $minor, string $formatted): void
    {
        self::assertSame($minor, DecimalMoney::minor($value));
        self::assertSame($formatted, DecimalMoney::normalize($value));
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($value) => [$value], [null, false, true, '', [], '-1', '1e3', '00.20', '0.001', '1,20', '1000000000.01', INF, NAN, ' 10']);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_or_inexact_amounts_are_rejected(mixed $value): void
    {
        $this->expectException(ManagerDataException::class);
        DecimalMoney::minor($value);
    }
}
