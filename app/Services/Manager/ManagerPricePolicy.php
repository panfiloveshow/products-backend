<?php

declare(strict_types=1);

namespace App\Services\Manager;

/** Checks an existing forecast; it does not calculate expenses or authorize a write. */
final class ManagerPricePolicy
{
    public function normalize(?array $constraints): ?array
    {
        if ($constraints === null) {
            return null;
        }
        $expected = ['max_change_percent', 'min_margin_percent', 'min_profit_rub'];
        $keys = array_keys($constraints);
        sort($keys);
        if ($keys !== $expected) {
            throw new ManagerDataException('Укажите все три ограничения цены без дополнительных полей.', 422);
        }
        $normalized = [];
        foreach (['min_margin_percent', 'min_profit_rub', 'max_change_percent'] as $field) {
            if (! is_string($constraints[$field])) {
                throw new ManagerDataException('Ограничения цены нужны строками с точностью до двух знаков.', 422);
            }
            $minor = DecimalMoney::minor($constraints[$field]);
            if (($field === 'min_margin_percent' && $minor > 10_000) || ($field === 'max_change_percent' && $minor > 100_000)) {
                throw new ManagerDataException('Ограничение цены выходит за поддерживаемые пределы.', 422);
            }
            $normalized[$field] = DecimalMoney::format($minor);
        }

        return $normalized;
    }

    public function unavailable(?array $constraints): array
    {
        return $this->result('unavailable', $this->normalize($constraints), ['forecast_unavailable'], null);
    }

    public function evaluate(array $forecast, string $sellerPrice, ?array $constraints): array
    {
        $constraints = $this->normalize($constraints);
        $target = DecimalMoney::minor($sellerPrice);
        $before = $this->moneyRow($forecast['before'] ?? []);
        $after = $this->moneyRow($forecast['after'] ?? []);
        if ($target <= 0 || $after['price'] !== $target) {
            throw new ManagerDataException('Расчёт не подтверждает именно выбранную цену.', 422);
        }
        // The calculator rounds components separately. Preserve its small
        // rounding allowance, but use the lower supported profit at a limit.
        $profit = min($after['profit'], $after['price'] - $after['costs']);
        $amounts = ['before_price' => DecimalMoney::format($before['price']), 'seller_price' => DecimalMoney::format($target),
            'profit_used_for_limits' => $this->signedFormat($profit), 'change_rub' => DecimalMoney::format(abs($target - $before['price']))];
        if ($constraints === null) {
            return $this->result('not_configured', null, ['price_constraints_not_configured'], $amounts);
        }
        $violations = [];
        // Integer products are bounded by 10^16 under DecimalMoney's limits.
        // Rounded margin_percent is presentation data and cannot pass a limit.
        if ($profit * 10_000 < $target * DecimalMoney::minor($constraints['min_margin_percent'])) {
            $violations[] = 'min_margin_percent_not_met';
        }
        if ($profit < DecimalMoney::minor($constraints['min_profit_rub'])) {
            $violations[] = 'min_profit_rub_not_met';
        }
        if (abs($target - $before['price']) * 10_000 > $before['price'] * DecimalMoney::minor($constraints['max_change_percent'])) {
            $violations[] = 'max_change_percent_exceeded';
        }

        return $this->result($violations === [] ? 'within_limits' : 'outside_limits', $constraints, $violations, $amounts);
    }

    private function moneyRow(array $row): array
    {
        $price = DecimalMoney::minor($row['price'] ?? null);
        $costs = $this->signedMinor($row['total_costs'] ?? null);
        $profit = $this->signedMinor($row['net_profit'] ?? null);
        if ($price <= 0 || abs($price - $costs - $profit) > 2) {
            throw new ManagerDataException('Расходы и прибыль расчёта не подтверждены в копейках.', 422);
        }

        return compact('price', 'costs', 'profit');
    }

    private function signedMinor(mixed $amount): int
    {
        if (is_string($amount) || is_int($amount) || is_float($amount)) {
            $value = (string) $amount;
            if (str_starts_with($value, '-')) {
                return -DecimalMoney::minor(substr($value, 1));
            }
        }

        return DecimalMoney::minor($amount);
    }

    private function signedFormat(int $minor): string
    {
        return ($minor < 0 ? '-' : '').DecimalMoney::format(abs($minor));
    }

    private function result(string $status, ?array $constraints, array $violations, ?array $amounts): array
    {
        return ['version' => 'price_policy_v1', 'scope' => 'scenario_constraints', 'currency' => 'RUB', 'status' => $status,
            'constraints' => $constraints, 'violations' => $violations, 'amounts' => $amounts, 'execution_allowed' => false];
    }
}
