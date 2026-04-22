<?php

namespace App\Domains\Locality\Explainability;

use App\Domains\Locality\Calculation\OverpaymentCalculator;
use App\Domains\Locality\Ingestion\PostingEnrichmentReader;
use App\Domains\Locality\Legacy\LegacyLocalityFacade;
use App\Domains\Locality\Presentation\DTO\SkuExplanationDto;
use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Domains\Ozon\UnitEconomics\MarkupReasonCode;
use App\Models\LocalityMetricDaily;
use App\Models\LocalityRecommendation;
use App\Models\OzonOrderUnitEconomics;
use App\Models\OzonSupplyFixation;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Собирает SkuExplanationDto: summary, per-cluster breakdown, attribution, counterfactual, timeline.
 */
class LocalityExplanationService
{
    public function __construct(
        private readonly PostingEnrichmentReader $reader,
        private readonly OzonInventoryHistoryReader $inventoryHistory,
        private readonly LegacyLocalityFacade $legacy,
        private readonly CounterfactualSimulator $counterfactual = new CounterfactualSimulator(),
        private readonly OverpaymentCalculator $overpayCalc = new OverpaymentCalculator(),
        private readonly OzonPricingMatrix $pricing = new OzonPricingMatrix(),
    ) {
    }

    public function explainForSku(
        int $integrationId,
        string $sku,
        Carbon $from,
        Carbon $to,
        bool $includeTimeline = true,
        ?int $counterfactualQty = null,
        ?string $counterfactualClusterId = null,
    ): SkuExplanationDto {
        // Версия тарифной матрицы включена в ключ: если деплоим новые наценки
        // (например, Омск 8→12 с 18.04.2026), кэш становится недоступен
        // автоматически и следующий запрос пересчитается. Иначе до истечения TTL
        // (30 мин) попап показывал бы старые цифры даже после `cache:clear`.
        $markupVersion = config('ozon_logistics_matrix.markups_effective_from')
            ?? config('ozon_logistics_matrix.generated_at');
        $markupVersionMissing = $markupVersion === null;

        // Observability: если оба ключа в config пустые — конфиг markup-матрицы
        // сгенерирован неправильно. Стабильный fallback на 'unversioned' маскирует
        // это: кэш становится stable для ВСЕХ релизов новых тарифов, инвалидация
        // ломается. Логируем WARN (раз в 5 минут, чтобы не засорять логи).
        if ($markupVersionMissing) {
            $logKey = 'locality:markup-version-missing-warned';
            if (Cache::add($logKey, 1, 300)) {
                Log::channel('locality')->warning(
                    'markups_effective_from AND generated_at отсутствуют в config/ozon_logistics_matrix.php — '
                    . 'explainForSku кэш не будет инвалидироваться при обновлении матрицы. '
                    . 'Проверь скрипт регенерации config.'
                );
            }
        }

        $cacheKey = sprintf(
            'locality:explain:v%s:%d:%s:%s:%s:%s',
            (string) ($markupVersion ?? 'unversioned'),
            $integrationId,
            $sku,
            $from->toDateString(),
            $to->toDateString(),
            $includeTimeline ? 'tl' : 'no'
        );
        $ttl = (int) config('locality.cache.explanation_ttl_minutes', 30);

        $payload = Cache::remember($cacheKey, $ttl * 60, fn () => $this->build(
            $integrationId,
            $sku,
            $from,
            $to,
            $includeTimeline
        ));

        if ($counterfactualQty !== null && $counterfactualClusterId !== null) {
            $payload['counterfactual'] = $this->counterfactual->simulate(
                $integrationId,
                $sku,
                $from,
                $to,
                $counterfactualClusterId,
                $counterfactualQty,
                $this->reader,
            );
        }

        return new SkuExplanationDto(
            sku: $sku,
            integrationId: $integrationId,
            periodDays: (int) $from->diffInDays($to) + 1,
            periodFrom: $from->toDateString(),
            periodTo: $to->toDateString(),
            calculatedAt: now()->toIso8601String(),
            dataConfidence: $payload['confidence'],
            warnings: $payload['warnings'],
            summary: $payload['summary'],
            perClusterBreakdown: $payload['per_cluster_breakdown'],
            stockProfile: $payload['stock_profile'],
            demandProfile: $payload['demand_profile'],
            shippingRoutes: $payload['shipping_routes'],
            attribution: $payload['attribution'],
            counterfactual: $payload['counterfactual'] ?? null,
            timeline: $payload['timeline'],
            activeFixation: $payload['active_fixation'],
            relatedRecommendations: $payload['related_recommendations'],
            productProfile: $payload['product_profile'],
        );
    }

    /** @return array<string,mixed> */
    private function build(int $integrationId, string $sku, Carbon $from, Carbon $to, bool $includeTimeline): array
    {
        $items = $this->reader->queryForPeriod($integrationId, $from, $to, $sku)->get();
        $warnings = [];

        if ($items->isEmpty()) {
            $warnings[] = 'no_sales_in_period';
        }

        $confidence = $this->overallConfidence($items);

        return [
            'confidence' => $confidence,
            'warnings' => $warnings,
            'summary' => $this->buildSummary($items),
            'per_cluster_breakdown' => $this->buildPerClusterBreakdown($integrationId, $sku, $items),
            'stock_profile' => $this->buildStockProfile($integrationId, $sku),
            'demand_profile' => $this->buildDemandProfile($integrationId, $sku),
            'shipping_routes' => $this->buildShippingRoutes($integrationId, $sku, $from, $to),
            'attribution' => $this->buildAttribution($integrationId, $sku, $items),
            'timeline' => $includeTimeline ? $this->buildTimeline($items, $from, $to) : [],
            'active_fixation' => $this->buildActiveFixation($integrationId, $sku),
            'related_recommendations' => $this->buildRelatedRecommendations($integrationId, $sku),
            'product_profile' => $this->buildProductProfile($integrationId, $sku),
        ];
    }

    private function buildProductProfile(int $integrationId, string $sku): ?array
    {
        $product = Product::query()
            ->where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->first(['sku', 'depth', 'width', 'height', 'weight', 'volume_weight', 'ozon_data']);

        if (! $product) {
            return null;
        }

        $ozonData = is_array($product->ozon_data) ? $product->ozon_data : [];
        $cache = UnitEconomicsCache::query()
            ->where('marketplace', 'ozon')
            ->where('sku', $sku)
            ->orderByRaw('CASE WHEN integration_id = ? THEN 0 ELSE 1 END', [$integrationId])
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
        $cacheData = is_array($cache?->marketplace_data) ? $cache->marketplace_data : [];
        $lengthMm = $this->resolveFloat(
            $product->depth,
            $ozonData['length_mm'] ?? null,
            $ozonData['dimensions']['depth'] ?? null,
            $cache?->depth,
            $cacheData['length_mm'] ?? null
        );
        $widthMm = $this->resolveFloat(
            $product->width,
            $ozonData['width_mm'] ?? null,
            $ozonData['dimensions']['width'] ?? null,
            $cache?->width,
            $cacheData['width_mm'] ?? null
        );
        $heightMm = $this->resolveFloat(
            $product->height,
            $ozonData['height_mm'] ?? null,
            $ozonData['dimensions']['height'] ?? null,
            $cache?->height,
            $cacheData['height_mm'] ?? null
        );
        $weightG = $this->resolveFloat(
            $product->weight,
            $ozonData['weight_g'] ?? null,
            $ozonData['dimensions']['weight'] ?? null,
            $cache?->weight,
            $cacheData['weight_g'] ?? null
        );
        $volumeWeight = $this->resolveFloat(
            $product->volume_weight,
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

        return [
            'length_mm' => $lengthMm !== null ? round($lengthMm, 2) : null,
            'width_mm' => $widthMm !== null ? round($widthMm, 2) : null,
            'height_mm' => $heightMm !== null ? round($heightMm, 2) : null,
            'weight_g' => $weightG !== null ? round($weightG, 2) : null,
            'volume_liters' => $volumeLiters !== null ? round($volumeLiters, 4) : null,
            'volume_weight' => $volumeWeight !== null ? round($volumeWeight, 4) : null,
            'chargeable_volume_liters' => $chargeableVolumeLiters,
        ];
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

    /** @param Collection<int,OzonOrderUnitEconomics> $items */
    private function buildSummary(Collection $items): array
    {
        $considered = $items->whereNotIn('markup_reason_code', MarkupReasonCode::excludedValues());
        $total = $considered->count();
        $local = $considered->filter(fn ($i) => $i->shipping_cluster_name !== null
            && $i->destination_cluster_name !== null
            && $i->shipping_cluster_name === $i->destination_cluster_name)->count();
        $breakdown = $this->overpayCalc->compute($items);
        $potential = (float) $breakdown['potential'];
        $actual = (float) $breakdown['actual'];
        $nonLocalOrders = (int) $breakdown['non_local_orders'];
        $gmv = round((float) $items->sum('sale_price'), 2);

        // Разбивка по статусам для UI-подсветки
        $cancelledOrders = $items->where('markup_reason_code', 'cancelled_order')->count();
        $notRedeemedOrders = $items->where('markup_reason_code', 'not_redeemed')->count();

        return [
            'orders_total' => $items->count(),
            'orders_considered' => $total,
            'orders_cancelled' => $cancelledOrders,
            'orders_not_redeemed' => $notRedeemedOrders,
            'orders_with_markup' => $items->where('markup_applied', true)->count(),
            'non_local_orders' => $nonLocalOrders,
            'local_sales_percent' => $total > 0 ? round(($local / $total) * 100, 2) : 0.0,
            'non_local_sales_percent' => $total > 0 ? round((($total - $local) / $total) * 100, 2) : 0.0,
            'total_overpayment_rub' => $potential,
            'actual_overpayment_rub' => $actual,
            'avg_overpayment_per_order_rub' => $nonLocalOrders > 0 ? round($potential / $nonLocalOrders, 2) : 0.0,
            'gmv_rub' => $gmv,
            'lost_margin_rub' => $potential,
            'high_confidence_orders' => $items->where('calculation_confidence', 'high')->count(),
        ];
    }

    /** @param Collection<int,OzonOrderUnitEconomics> $items */
    private function buildPerClusterBreakdown(int $integrationId, string $sku, Collection $items): array
    {
        $groups = $items->groupBy('destination_cluster_name');
        $result = [];

        foreach ($groups as $clusterName => $group) {
            if ($clusterName === null || $clusterName === '') {
                continue;
            }

            $shippedFrom = $group
                ->groupBy('shipping_cluster_name')
                ->map(fn (Collection $g) => $g->count())
                ->sortDesc();

            $daysScan = $group->pluck('order_date')
                ->filter()
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->unique();
            $daysWithStock = 0;
            $daysWithoutStock = 0;
            foreach ($daysScan as $day) {
                $snapshot = $this->inventoryHistory->stockByClusterOnDate($integrationId, $sku, Carbon::parse($day));
                if (($snapshot['by_cluster'][(string) $clusterName] ?? 0) > 0) {
                    $daysWithStock++;
                } else {
                    $daysWithoutStock++;
                }
            }

            $breakdown = $this->overpayCalc->compute($group);
            $ozonMarkupPct = (float) $this->pricing->resolveDestinationMarkupPercent((string) $clusterName);
            $totalOrders = $group->count();
            $nonLocalOrders = (int) $breakdown['non_local_orders'];

            // Статусные счётчики для UI-подсветки
            $cancelledInCluster = $group->where('markup_reason_code', 'cancelled_order')->count();
            $notRedeemedInCluster = $group->where('markup_reason_code', 'not_redeemed')->count();
            $activeOrders = $totalOrders - $cancelledInCluster - $notRedeemedInCluster;

            // Средневзвешенная наценка = (доля non-local заказов) × (ставка Ozon для кластера).
            // Для «Саратов→Саратов» локальных заказов наценка = 0, поэтому effective < reference.
            $effectiveMarkupPct = $totalOrders > 0
                ? round(($nonLocalOrders * $ozonMarkupPct) / $totalOrders, 2)
                : 0.0;

            $result[] = [
                'destination_cluster_id' => $group->pluck('destination_cluster_id')->filter()->first(),
                'destination_cluster_name' => (string) $clusterName,
                'orders' => $totalOrders,
                'active_orders' => $activeOrders,
                'cancelled_orders' => $cancelledInCluster,
                'not_redeemed_orders' => $notRedeemedInCluster,
                'non_local_orders' => $nonLocalOrders,
                'avg_base_logistics_rub' => round((float) $group->avg('base_logistics_tariff'), 2),
                'avg_applied_markup_percent' => $effectiveMarkupPct,
                'destination_markup_percent' => $ozonMarkupPct,
                'total_overpayment_rub' => (float) $breakdown['potential'],
                'actual_overpayment_rub' => (float) $breakdown['actual'],
                'reason_codes' => $group->pluck('markup_reason_code')->filter()->unique()->values()->all(),
                'shipped_from' => $shippedFrom->map(fn ($count, $name) => [
                    'cluster_name' => (string) $name,
                    'count' => (int) $count,
                ])->values()->all(),
                'had_local_stock_at_sale' => [
                    'days_with_stock' => $daysWithStock,
                    'days_without_stock' => $daysWithoutStock,
                    'data_source' => $daysScan->count() > 0 ? 'mixed' : 'unknown',
                ],
            ];
        }

        usort($result, fn ($a, $b) => $b['total_overpayment_rub'] <=> $a['total_overpayment_rub']);
        return $result;
    }

    private function buildStockProfile(int $integrationId, string $sku): array
    {
        $byCluster = $this->inventoryHistory->currentStockByCluster($integrationId, $sku);
        $total = array_sum($byCluster);
        if ($total <= 0) {
            return [];
        }

        $rows = [];
        foreach ($byCluster as $name => $qty) {
            $rows[] = [
                'cluster_name' => $name,
                'quantity' => $qty,
                'share_percent' => round(($qty / $total) * 100, 2),
            ];
        }
        usort($rows, fn ($a, $b) => $b['quantity'] <=> $a['quantity']);
        return $rows;
    }

    private function buildDemandProfile(int $integrationId, string $sku): array
    {
        $data = $this->legacy->localityForSku($integrationId, $sku);
        return $data['clusters_summary'] ?? [];
    }

    private function buildShippingRoutes(int $integrationId, string $sku, Carbon $from, Carbon $to): array
    {
        $periodDays = (int) $from->diffInDays($to) + 1;
        $all = $this->legacy->shippingRoutesForIntegration($integrationId, $periodDays);
        return $all[$sku] ?? [];
    }

    /** @param Collection<int,OzonOrderUnitEconomics> $items */
    private function buildAttribution(int $integrationId, string $sku, Collection $items): array
    {
        $buckets = [
            'no_local_stock' => ['orders' => 0, 'amount_rub' => 0.0],
            'ozon_routing' => ['orders' => 0, 'amount_rub' => 0.0],
            'cluster_blocked' => ['orders' => 0, 'amount_rub' => 0.0],
            'exception_status' => ['orders' => 0, 'amount_rub' => 0.0],
        ];

        $waived = ['cancelled' => 0, 'not_redeemed' => 0, 'fbo_lt_50' => 0];
        $zeroMarkupByCluster = [];
        $totalOverpayment = 0.0;

        foreach ($items as $item) {
            $reason = (string) ($item->markup_reason_code ?? '');
            if ($reason === 'cancelled_order') {
                $waived['cancelled']++;
                continue;
            }
            if ($reason === 'not_redeemed') {
                $waived['not_redeemed']++;
                continue;
            }

            // Все не-local заказы кроме отменённых — бакетируем по причине non-local.
            $ship = $item->shipping_cluster_name;
            $dest = $item->destination_cluster_name;
            if ($ship === null || $dest === null || $ship === $dest) {
                continue;
            }

            // Считаем потенциальную наценку по таблице Ozon
            $markupPct = $this->pricing->resolveDestinationMarkupPercent((string) $dest);
            $potential = (float) ($item->sale_price ?? 0) * ($markupPct / 100);
            if ($potential <= 0) {
                // Кластер с 0% — счётчик в informational.zero_markup_destinations
                $zeroMarkupByCluster[(string) $dest] = ($zeroMarkupByCluster[(string) $dest] ?? 0) + 1;
                continue;
            }

            $totalOverpayment += $potential;

            if ($item->markup_exception_code !== null) {
                $buckets['exception_status']['orders']++;
                $buckets['exception_status']['amount_rub'] += $potential;
                continue;
            }

            if ($reason === 'fbo_lt_50_orders_7d') {
                $waived['fbo_lt_50']++;
            }

            $bucket = $this->attributionBucketForItem($integrationId, $sku, $item);
            $buckets[$bucket]['orders']++;
            $buckets[$bucket]['amount_rub'] += $potential;
        }

        $bucketsPayload = [];
        foreach ($buckets as $code => $v) {
            if ($v['orders'] === 0) {
                continue;
            }
            $bucketsPayload[] = [
                'code' => $code,
                'orders' => $v['orders'],
                'amount_rub' => round($v['amount_rub'], 2),
                'share_percent' => $totalOverpayment > 0 ? round(($v['amount_rub'] / $totalOverpayment) * 100, 2) : 0.0,
            ];
        }

        $zeroMarkupPayload = [];
        foreach ($zeroMarkupByCluster as $name => $orders) {
            $zeroMarkupPayload[] = ['cluster_name' => $name, 'orders' => $orders];
        }

        return [
            'total_overpayment_rub' => round($totalOverpayment, 2),
            'buckets' => $bucketsPayload,
            'informational' => [
                'waived_orders' => $waived,
                'zero_markup_destinations' => $zeroMarkupPayload,
            ],
        ];
    }

    private function attributionBucketForItem(int $integrationId, string $sku, OzonOrderUnitEconomics $item): string
    {
        $destCluster = (string) ($item->destination_cluster_name ?? '');
        $shipCluster = (string) ($item->shipping_cluster_name ?? '');
        if ($destCluster === '') {
            return 'no_local_stock';
        }

        // Если order_date null (редкий кейс — PostingService не получил ни
        // in_process_at, ни delivered_at), раньше fallback'ились на now().
        // Это делало atribution неверным: для заказа, который физически был
        // год назад, смотрели сегодняшние остатки. Теперь помечаем 'unknown_attribution',
        // чтобы фронт мог отобразить это как «недостаточно данных для атрибуции»,
        // а не врать про «no_local_stock».
        if ($item->order_date === null) {
            return 'unknown_attribution';
        }

        $orderDate = Carbon::parse($item->order_date);
        $snapshot = $this->inventoryHistory->stockByClusterOnDate($integrationId, $sku, $orderDate);
        $stockInDest = $snapshot['by_cluster'][$destCluster] ?? 0;

        if ($stockInDest <= 0) {
            return 'no_local_stock';
        }
        if ($shipCluster !== '' && $shipCluster !== $destCluster) {
            return 'ozon_routing';
        }
        return 'no_local_stock';
    }

    /** @param Collection<int,OzonOrderUnitEconomics> $items */
    private function buildTimeline(Collection $items, Carbon $from, Carbon $to): array
    {
        $byDay = [];
        foreach ($items as $item) {
            if ($item->order_date === null) {
                continue;
            }
            if (in_array($item->markup_reason_code, MarkupReasonCode::excludedValues(), true)) {
                continue;
            }
            $day = Carbon::parse($item->order_date)->toDateString();
            $byDay[$day] ??= ['orders' => 0, 'local' => 0, 'overpayment' => 0.0];
            $byDay[$day]['orders']++;
            if ($item->shipping_cluster_name !== null
                && $item->destination_cluster_name !== null
                && $item->shipping_cluster_name === $item->destination_cluster_name) {
                $byDay[$day]['local']++;
            }
            if ($item->markup_applied) {
                $byDay[$day]['overpayment'] += (float) $item->non_local_markup_amount;
            }
        }

        $result = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $day = $cursor->toDateString();
            $d = $byDay[$day] ?? ['orders' => 0, 'local' => 0, 'overpayment' => 0.0];
            $result[] = [
                'date' => $day,
                'orders' => $d['orders'],
                'local' => $d['local'],
                'overpayment_rub' => round($d['overpayment'], 2),
                'locality_rate' => $d['orders'] > 0 ? round(($d['local'] / $d['orders']) * 100, 2) : null,
            ];
        }

        return $result;
    }

    private function buildActiveFixation(int $integrationId, string $sku): ?array
    {
        $fix = OzonSupplyFixation::query()
            ->where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->where(function ($q) {
                $q->whereNull('fixed_until')->orWhere('fixed_until', '>=', now()->toDateString());
            })
            ->orderByDesc('id')
            ->first();

        if ($fix === null) {
            return null;
        }

        return [
            'shipping_cluster_id' => $fix->shipping_cluster_id,
            'shipping_cluster_name' => $fix->shipping_cluster_name,
            'valid_until' => $fix->fixed_until?->toDateString(),
            'tariff_version' => $fix->tariff_version ?? null,
        ];
    }

    private function buildRelatedRecommendations(int $integrationId, string $sku): array
    {
        return LocalityRecommendation::query()
            ->where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->where('state', LocalityRecommendation::STATE_NEW)
            ->orderByDesc('expected_savings_rub')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'target_cluster' => (string) $r->target_cluster_name,
                'expected_savings_rub' => (float) $r->expected_savings_rub,
                'confidence' => (string) $r->confidence,
            ])
            ->all();
    }

    /**
     * Уверенность = покрытие кластеров в постингах за период.
     * Если у нас есть shipping_cluster и destination_cluster из API для почти всех заказов —
     * сравнение «local/non-local» надёжно, и переплата по таблице Ozon считается точно.
     *
     * ≥95% заказов с обоими кластерами → high
     * ≥70% → medium
     * иначе → low
     *
     * @param Collection<int,OzonOrderUnitEconomics> $items
     */
    private function overallConfidence(Collection $items): string
    {
        if ($items->isEmpty()) {
            return 'low';
        }

        $considered = $items->whereNotIn('markup_reason_code', MarkupReasonCode::excludedValues());
        $total = $considered->count();
        if ($total === 0) {
            return 'low';
        }

        $withBothClusters = $considered->filter(fn ($i) => $i->shipping_cluster_name !== null
            && $i->destination_cluster_name !== null)->count();
        $coverage = $withBothClusters / $total;

        return match (true) {
            $coverage >= 0.95 => 'high',
            $coverage >= 0.70 => 'medium',
            default => 'low',
        };
    }
}
