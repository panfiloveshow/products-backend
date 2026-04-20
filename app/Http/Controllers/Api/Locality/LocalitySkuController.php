<?php

namespace App\Http\Controllers\Api\Locality;

use App\Domains\Locality\Presentation\DTO\SkuLocalityDto;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\LocalityMetricDaily;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalitySkuController extends Controller
{
    private const ALLOWED_SORT = ['overpayment', 'lost_margin', 'local_share_asc', 'orders', 'revenue'];

    /** GET /api/v1/locality/skus */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integration_id' => 'required|exists:integrations,id',
            'period' => 'nullable|integer|in:7,28',
            'as_of' => 'nullable|date_format:Y-m-d',
            'sort' => 'nullable|string|in:' . implode(',', self::ALLOWED_SORT),
            // Поднимаем лимит до 1000 — UE-таблица и Locality-SKU-таб грузят bulk, не хотим терять SKU.
            'limit' => 'nullable|integer|min:1|max:1000',
            'offset' => 'nullable|integer|min:0',
        ]);

        $integration = Integration::findOrFail($validated['integration_id']);
        $period = (int) ($validated['period'] ?? config('locality.period.default_days', 28));

        // as_of: явно переданный → берём. Без него — ПОСЛЕДНИЙ доступный снапшот (timezone-safe).
        // Backfill могли делать вчера/позавчера — не стоит возвращать пусто только потому, что ещё не пересчитали сегодня.
        if (isset($validated['as_of'])) {
            $asOf = Carbon::parse($validated['as_of']);
        } else {
            $latest = LocalityMetricDaily::query()
                ->where('integration_id', $integration->id)
                ->where('period_days', $period)
                ->max('snapshot_date');
            $asOf = $latest ? Carbon::parse($latest) : now()->startOfDay();
        }

        $sort = $validated['sort'] ?? 'overpayment';
        $limit = (int) ($validated['limit'] ?? 20);
        $offset = (int) ($validated['offset'] ?? 0);

        $query = LocalityMetricDaily::query()
            ->where('integration_id', $integration->id)
            ->where('snapshot_date', $asOf->toDateString())
            ->where('period_days', $period);

        match ($sort) {
            'overpayment' => $query->orderByDesc('overpayment_amount'),
            'lost_margin' => $query->orderByDesc('lost_margin_amount'),
            'local_share_asc' => $query->orderBy('local_share_percent'),
            'orders' => $query->orderByDesc('orders_count'),
            'revenue' => $query->orderByDesc('revenue_total'),
        };

        $total = (clone $query)->count();
        $rows = $query->limit($limit)->offset($offset)->get();

        $productNames = Product::query()
            ->where('integration_id', $integration->id)
            ->whereIn('sku', $rows->pluck('sku')->all())
            ->get([
                'sku',
                'name',
                'depth',
                'width',
                'height',
                'weight',
                'volume_weight',
                'ozon_data',
            ])
            ->keyBy('sku');

        $data = $rows->map(function ($row) use ($productNames, $integration) {
            $meta = is_array($row->meta) ? $row->meta : [];
            /** @var Product|null $product */
            $product = $productNames->get($row->sku);
            $cache = UnitEconomicsCache::query()
                ->where('marketplace', 'ozon')
                ->where('sku', $row->sku)
                ->orderByRaw('CASE WHEN integration_id = ? THEN 0 ELSE 1 END', [$integration->id])
                ->orderByDesc('calculated_at')
                ->orderByDesc('id')
                ->first([
                    'integration_id',
                    'volume_liters',
                    'volume_weight',
                    'depth',
                    'width',
                    'height',
                    'weight',
                    'marketplace_data',
                ]);

            $ozonData = is_array($product?->ozon_data) ? $product->ozon_data : [];
            $cacheData = is_array($cache?->marketplace_data) ? $cache->marketplace_data : [];
            $lengthMm = $this->resolveFloat(
                $product?->depth,
                $ozonData['length_mm'] ?? null,
                $ozonData['dimensions']['depth'] ?? null,
                $cache?->depth,
                $cacheData['length_mm'] ?? null
            );
            $widthMm = $this->resolveFloat(
                $product?->width,
                $ozonData['width_mm'] ?? null,
                $ozonData['dimensions']['width'] ?? null,
                $cache?->width,
                $cacheData['width_mm'] ?? null
            );
            $heightMm = $this->resolveFloat(
                $product?->height,
                $ozonData['height_mm'] ?? null,
                $ozonData['dimensions']['height'] ?? null,
                $cache?->height,
                $cacheData['height_mm'] ?? null
            );
            $weightG = $this->resolveFloat(
                $product?->weight,
                $ozonData['weight_g'] ?? null,
                $ozonData['dimensions']['weight'] ?? null,
                $cache?->weight,
                $cacheData['weight_g'] ?? null
            );
            $volumeWeight = $this->resolveFloat(
                $product?->volume_weight,
                $ozonData['volume_weight'] ?? null,
                $cache?->volume_weight,
                $cacheData['volume_weight'] ?? null
            );
            $volumeLiters = $this->resolveVolumeLiters($lengthMm, $widthMm, $heightMm, $cache?->volume_liters, $cacheData['volume_liters'] ?? null);
            $chargeableVolumeLiters = $this->resolveChargeableVolumeLiters(
                $volumeLiters,
                $volumeWeight,
                $cacheData['chargeable_volume_liters'] ?? null
            );
            $dto = new SkuLocalityDto(
                sku: (string) $row->sku,
                productName: $product?->name,
                ordersCount: (int) $row->orders_count,
                localSharePercent: $row->local_share_percent !== null ? (float) $row->local_share_percent : null,
                overpaymentAmount: (float) $row->overpayment_amount,
                lostMarginAmount: (float) $row->lost_margin_amount,
                avgBaseLogisticsRub: (float) ($row->avg_base_tariff ?? 0),
                avgMarkupPercent: (float) ($row->avg_markup_percent ?? 0),
                confidence: (string) $row->calculation_confidence,
                dominantDestinationCluster: $meta['dominant_destination_cluster'] ?? null,
                dominantShippingCluster: $meta['dominant_shipping_cluster'] ?? null,
                volumeLiters: $volumeLiters,
                volumeWeight: $volumeWeight,
                chargeableVolumeLiters: $chargeableVolumeLiters,
                lengthMm: $lengthMm,
                widthMm: $widthMm,
                heightMm: $heightMm,
                weightG: $weightG,
            );
            return $dto->toArray();
        })->all();

        return response()->json([
            'message' => 'Success',
            'data' => $data,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'sort' => $sort,
                'period_days' => $period,
                'as_of' => $asOf->toDateString(),
            ],
        ]);
    }

    private function resolveFloat(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function resolveVolumeLiters(?float $lengthMm, ?float $widthMm, ?float $heightMm, mixed ...$fallbacks): ?float
    {
        if ($lengthMm !== null && $widthMm !== null && $heightMm !== null) {
            return round(($lengthMm * $widthMm * $heightMm) / 1000000, 4);
        }

        return $this->resolveFloat(...$fallbacks);
    }

    private function resolveChargeableVolumeLiters(?float $volumeLiters, ?float $volumeWeight, mixed ...$fallbacks): ?float
    {
        $explicit = $this->resolveFloat(...$fallbacks);
        if ($explicit !== null) {
            return round($explicit, 4);
        }

        if ($volumeLiters === null && $volumeWeight === null) {
            return null;
        }

        $volumeByWeight = $volumeWeight !== null ? $volumeWeight * 5 : null;

        return round(max(
            $volumeLiters ?? 0.0,
            $volumeByWeight ?? 0.0,
        ), 4);
    }
}
