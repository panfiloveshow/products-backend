<?php

declare(strict_types=1);

namespace App\Services\Manager;

/** API money in RUB: reject rounding, exponents, negative and unbounded amounts. */
final class DecimalMoney
{
    public static function minor(mixed $value): int
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || (is_float($value) && ! is_finite($value))
            || ! preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', (string) $value, $match)) {
            throw new ManagerDataException('Источник вернул непроверенную денежную сумму.', 422);
        }
        $minor = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
        if ($minor > 100_000_000_000) {
            throw new ManagerDataException('Цена выходит за допустимые пределы.', 422);
        }

        return $minor;
    }

    public static function format(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function normalize(mixed $value): string
    {
        return self::format(self::minor($value));
    }
}
