<?php

declare(strict_types=1);

namespace App\Services\UnitEconomics;

use App\Models\Product;
use Carbon\CarbonImmutable;

/** Fee observations belong to a scheme and never inherit a product update time. */
final class OzonCommissionObservation
{
    private const VERSION = 'ozon_commission_v1';
    private const FIELDS = ['fbo' => 'sales_percent_fbo', 'fbs' => 'sales_percent_fbs',
        'rfbs' => 'sales_percent_rfbs', 'express' => 'sales_percent_express'];

    public function merge(Product $product, array $data, array $priceRow): array
    {
        $id = $this->productId($product, $data);
        $rowId = $priceRow['product_id'] ?? null;
        $at = $this->time($priceRow['commissions_observed_at'] ?? $priceRow['price_observed_at'] ?? null);
        if ($id === null || (! is_int($rowId) && ! is_string($rowId)) || (string) $rowId !== $id
            || ($priceRow['offer_id'] ?? null) !== (string) $product->sku || $at === null
            || ! is_array($priceRow['commissions'] ?? null)) {
            return $data;
        }
        foreach (self::FIELDS as $scheme => $field) {
            $percent = $this->percent($priceRow['commissions'][$field] ?? null);
            if ($percent === null) {
                continue;
            }
            if (! is_array($data['commission_observations'] ?? null)) {
                $data['commission_observations'] = [];
            }
            $old = $this->entry($product, $data, $scheme);
            if ($old !== null) {
                $oldAt = $this->time($old['observed_at']);
                if ($at->lessThan($oldAt)) {
                    continue;
                }
                if ($at->equalTo($oldAt)) {
                    if ($percent !== $old['percent']) {
                        $data['commission_observations'][$scheme] = [...$old, 'status' => 'conflict', 'conflicting_percent' => $percent];
                    }
                    continue;
                }
            }
            $observedAt = $at->format($at->micro === 0 ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP');
            $data['commission_observations'][$scheme] = ['version' => self::VERSION, 'percent' => $percent, 'observed_at' => $observedAt,
                'product_id' => $id, 'offer_id' => (string) $product->sku,
                'integration_id' => (string) $product->integration_id, 'source' => 'ozon_prices_v5', 'status' => 'observed'];
            if (! is_array($data['commissions'] ?? null)) {
                $data['commissions'] = [];
            }
            $data['commissions'][$scheme] = array_replace(is_array($data['commissions'][$scheme] ?? null) ? $data['commissions'][$scheme] : [], ['percent' => (float) $percent]);
            if (is_array($data['commissions_by_scheme'] ?? null)) {
                $data['commissions_by_scheme'][$scheme] = array_replace(is_array($data['commissions_by_scheme'][$scheme] ?? null) ? $data['commissions_by_scheme'][$scheme] : [], ['percent' => (float) $percent]);
            }
        }

        return $data;
    }

    public function forScheme(Product $product, string $scheme, ?array $data = null): ?array
    {
        $scheme = match (strtoupper($scheme)) {
            'FBO' => 'fbo', 'FBS' => 'fbs', 'RFBS', 'REALFBS', 'DBS' => 'rfbs', 'EXPRESS' => 'express', default => null,
        };
        if ($scheme === null) {
            return null;
        }
        $data ??= is_array($product->ozon_data) ? $product->ozon_data : [];
        $entry = $this->entry($product, $data, $scheme);
        $commissions = is_array($data['commissions_by_scheme'] ?? null) ? $data['commissions_by_scheme']
            : (is_array($data['commissions'] ?? null) ? $data['commissions'] : []);
        if ($entry === null || $entry['status'] !== 'observed'
            || $this->percent($commissions[$scheme]['percent'] ?? null) !== $entry['percent']) {
            return null;
        }

        return $entry;
    }

    private function entry(Product $product, array $data, string $scheme): ?array
    {
        $entry = is_array($data['commission_observations'] ?? null) ? ($data['commission_observations'][$scheme] ?? null) : null;
        if (! is_array($entry) || ($entry['version'] ?? null) !== self::VERSION || ($entry['source'] ?? null) !== 'ozon_prices_v5'
            || ! in_array($entry['status'] ?? null, ['observed', 'conflict'], true)
            || $this->productId($product, $data) === null || ($entry['product_id'] ?? null) !== $this->productId($product, $data)
            || ($entry['offer_id'] ?? null) !== (string) $product->sku
            || ($entry['integration_id'] ?? null) !== (string) $product->integration_id
            || ! is_string($entry['percent'] ?? null) || $this->percent($entry['percent']) !== $entry['percent']
            || $this->time($entry['observed_at'] ?? null) === null) {
            return null;
        }

        return $entry;
    }

    private function productId(Product $product, array $data): ?string
    {
        $id = $data['product_id'] ?? null;

        return $product->marketplace === 'ozon' && (is_int($id) || is_string($id))
            && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id) && (string) $product->sku !== '' ? (string) $id : null;
    }

    private function percent(mixed $value): ?string
    {
        // Percentages are not rubles: keep fractional basis points from the
        // API instead of rounding them through the two-decimal money codec.
        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }
            $fixed = sprintf('%.8F', $value == 0.0 ? 0.0 : $value);
            if ((float) $fixed !== $value) {
                return null;
            }
            $value = $fixed;
        }
        if ((! is_int($value) && ! is_string($value)) || strlen((string) $value) > 32
            || ! preg_match('/^(0|[1-9][0-9]{0,2})(?:\.([0-9]{1,20}))?$/D', (string) $value, $parts)) {
            return null;
        }
        $fraction = rtrim($parts[2] ?? '', '0');
        if (strlen($fraction) > 8 || (int) $parts[1] > 100 || ((int) $parts[1] === 100 && $fraction !== '')) {
            return null;
        }

        return $parts[1].'.'.str_pad($fraction, 2, '0');
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        if (! str_ends_with($value, 'Z')) {
            $hours = (int) substr($value, -5, 2);
            $minutes = (int) substr($value, -2);
            if ($hours > 14 || $minutes > 59 || ($hours === 14 && $minutes !== 0)) {
                return null;
            }
        }
        try {
            $at = CarbonImmutable::parse($value);
            if ($at->format('Y-m-d\TH:i:s') !== substr($value, 0, 19) || $at->year < 1970 || $at->greaterThan(now()->utc()->addMinutes(5))) {
                return null;
            }

            return $at->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
