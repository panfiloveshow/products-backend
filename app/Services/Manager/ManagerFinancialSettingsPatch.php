<?php

declare(strict_types=1);

namespace App\Services\Manager;

final class ManagerFinancialSettingsPatch
{
    public const FIELDS = [
        'cost_price' => ['label' => 'Себестоимость, ₽', 'unit' => 'RUB'],
        'tax_percent' => ['label' => 'Налог, %', 'unit' => 'percent'],
        'vat_percent' => ['label' => 'НДС, %', 'unit' => 'percent'],
        'drr_percent' => ['label' => 'ДРР, %', 'unit' => 'percent'],
        'our_share_percent' => ['label' => 'Наша доля, %', 'unit' => 'percent'],
        'acceptance_cost' => ['label' => 'Приёмка на единицу, ₽', 'unit' => 'RUB'],
        'redemption_rate_override' => ['label' => 'Выкуп вручную, %', 'unit' => 'percent'],
    ];

    public function productBinding(\App\Models\Product $product): string
    {
        return \App\Services\UnitEconomics\CalculationFingerprint::productBinding($product);
    }

    public function normalize(array $values): array
    {
        if ($values === [] || array_diff(array_keys($values), array_keys(self::FIELDS)) !== []) throw new ManagerDataException('Нужны явно введённые финансовые поля.', 422);
        $normalized = [];
        foreach (self::FIELDS as $key => $field) {
            if (! array_key_exists($key, $values)) continue;
            if (! is_string($values[$key])) throw new ManagerDataException('Значения должны быть точными десятичными строками.', 422);
            $minor = DecimalMoney::minor($values[$key]);
            if ($field['unit'] === 'percent' && $minor > 10000) throw new ManagerDataException('Процент должен быть от 0 до 100.', 422);
            $normalized[$key] = DecimalMoney::format($minor);
        }

        return $normalized;
    }
}
