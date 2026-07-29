<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Product;
use App\Models\Supply;
use App\Models\SupplyEvent;
use App\Models\SupplyItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Граница между расчётным автопланом и операционным модулем поставок Ozon.
 *
 * Сервис намеренно не вызывает Seller API. Он валидирует и фиксирует состав
 * утверждённого плана, затем идемпотентно создаёт локальные Supply/SupplyItem.
 */
class OzonPlanMaterializationService
{
    /**
     * @return array{
     *   allowed: bool,
     *   errors: list<array{code: string, message: string, context?: array<string, mixed>}>,
     *   warnings: list<array{code: string, message: string}>,
     *   lines_count: int,
     *   clusters_count: int,
     *   total_qty: int,
     *   fingerprint: string|null
     * }
     */
    public function approvalCheck(AutoSupplyPlan $plan): array
    {
        $check = $this->preflightCheck($plan);
        $validation = is_array($plan->validation_json) ? $plan->validation_json : [];

        if (! $plan->validated_at || ! $plan->validation_fingerprint) {
            $check['errors'][] = [
                'code' => 'validation_required',
                'message' => 'Перед утверждением выполните проверку плана.',
            ];
        } elseif (
            ! isset($validation['plan_fingerprint'])
            || ! hash_equals((string) $validation['plan_fingerprint'], (string) $check['fingerprint'])
        ) {
            $check['errors'][] = [
                'code' => 'validation_stale',
                'message' => 'Состав плана изменился после проверки. Выполните проверку повторно.',
            ];
        }

        $integration = $plan->integration()->withoutGlobalScopes()->first();
        if ($integration !== null && isset($validation['facts_hash'])) {
            $health = app(DataFreshnessRegistry::class)->inspect($integration);
            if (
                ! hash_equals((string) $validation['facts_hash'], (string) ($health['hash'] ?? ''))
                || ! ($health['can_approve'] ?? false)
            ) {
                $check['errors'][] = [
                    'code' => 'facts_changed_after_validation',
                    'message' => 'Исходные данные обновились или устарели после проверки. Проверьте план повторно.',
                ];
            }
        }

        $check['allowed'] = $check['errors'] === [];
        $check['fingerprint'] = $check['allowed']
            ? (string) $plan->validation_fingerprint
            : null;

        return $check;
    }

    /**
     * Проверка состава без требования уже сохранённой валидации.
     *
     * @return array{
     *   allowed: bool,
     *   errors: list<array{code: string, message: string, context?: array<string, mixed>}>,
     *   warnings: list<array{code: string, message: string}>,
     *   lines_count: int,
     *   clusters_count: int,
     *   total_qty: int,
     *   fingerprint: string|null
     * }
     */
    public function preflightCheck(AutoSupplyPlan $plan): array
    {
        $errors = [];
        $warnings = [];

        if ($plan->marketplace !== 'ozon') {
            $errors[] = [
                'code' => 'wrong_marketplace',
                'message' => 'Этот сценарий доступен только для планов Ozon.',
            ];
        }

        if ($plan->status !== AutoSupplyPlan::STATUS_READY) {
            $errors[] = [
                'code' => 'plan_not_ready',
                'message' => 'Сначала дождитесь успешного расчёта плана.',
            ];
        }

        $qualityAudit = is_array($plan->plan_quality_audit) ? $plan->plan_quality_audit : [];
        $acceptanceGates = is_array($qualityAudit['acceptance_gates'] ?? null)
            ? $qualityAudit['acceptance_gates']
            : [];
        $qualityAllowed = array_key_exists('can_create_ozon_draft', $acceptanceGates)
            ? (bool) $acceptanceGates['can_create_ozon_draft']
            : (($qualityAudit['status'] ?? null) !== 'bad');

        if (! $qualityAllowed) {
            $errors[] = [
                'code' => 'quality_gate_blocked',
                'message' => (string) (
                    $acceptanceGates['manual_review_reason_ru']
                    ?? $qualityAudit['summary_ru']
                    ?? 'План заблокирован проверкой качества.'
                ),
            ];
        } elseif (($qualityAudit['status'] ?? null) !== null && ($qualityAudit['status'] ?? null) !== 'good') {
            $warnings[] = [
                'code' => 'manual_review_recommended',
                'message' => (string) (
                    $acceptanceGates['manual_review_reason_ru']
                    ?? 'Проверьте отмеченные риски перед исполнением плана.'
                ),
            ];
        }

        $lines = $this->eligibleLines($plan);
        if ($lines->isEmpty()) {
            $errors[] = [
                'code' => 'no_cluster_lines',
                'message' => 'В плане нет положительных строк Ozon с привязкой к кластеру.',
            ];
        }

        $products = $this->productsForLines($plan, $lines);
        $unresolved = $lines
            ->filter(fn (AutoSupplyPlanLine $line) => $this->resolveOzonSku(
                $this->productForLine($products, $line)
            ) === null)
            ->map(fn (AutoSupplyPlanLine $line) => [
                'line_id' => $line->id,
                'sku' => $line->sku,
                'offer_id' => $line->offer_id,
            ])
            ->values()
            ->all();

        if ($unresolved !== []) {
            $errors[] = [
                'code' => 'ozon_sku_unresolved',
                'message' => 'Для части строк не найден числовой Ozon SKU в карточке товара.',
                'context' => ['lines' => array_slice($unresolved, 0, 20)],
            ];
        }

        if ($this->supplyMethod($plan) === Supply::METHOD_CROSSDOCK && $this->dropOffPointId($plan) === null) {
            $errors[] = [
                'code' => 'drop_off_point_required',
                'message' => 'Для кросс-докинга выберите точку отгрузки Ozon.',
            ];
        }

        return [
            'allowed' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'lines_count' => $lines->count(),
            'clusters_count' => $lines->pluck('cluster_id')->unique()->count(),
            'total_qty' => (int) $lines->sum('qty_rounded'),
            'fingerprint' => $errors === [] ? $this->fingerprintFrom($plan, $lines, $products) : null,
        ];
    }

    public function approve(AutoSupplyPlan $plan, ?int $userId = null): AutoSupplyPlan
    {
        return DB::transaction(function () use ($plan, $userId): AutoSupplyPlan {
            /** @var AutoSupplyPlan $lockedPlan */
            $lockedPlan = AutoSupplyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $check = $this->approvalCheck($lockedPlan);

            if (! $check['allowed']) {
                throw ValidationException::withMessages([
                    'plan' => collect($check['errors'])->pluck('message')->all(),
                ]);
            }

            $fingerprint = (string) $check['fingerprint'];
            $hasMaterializedSupplies = $lockedPlan->supplies()->exists();
            if (
                $lockedPlan->business_status === AutoSupplyPlan::BUSINESS_STATUS_APPROVED
                && hash_equals((string) $lockedPlan->approval_fingerprint, $fingerprint)
            ) {
                return $lockedPlan;
            }

            if ($hasMaterializedSupplies) {
                throw ValidationException::withMessages([
                    'plan' => ['Нельзя повторно утвердить изменённый план, по которому уже созданы поставки.'],
                ]);
            }

            $lockedPlan->update([
                'business_status' => AutoSupplyPlan::BUSINESS_STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $userId,
                'approval_fingerprint' => $fingerprint,
                'materialized_at' => null,
            ]);

            return $lockedPlan->fresh();
        });
    }

    /**
     * @return array{plan: AutoSupplyPlan, supplies: Collection<int, Supply>, created_count: int}
     */
    public function materialize(AutoSupplyPlan $plan, ?int $userId = null): array
    {
        return DB::transaction(function () use ($plan, $userId): array {
            /** @var AutoSupplyPlan $lockedPlan */
            $lockedPlan = AutoSupplyPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $check = $this->approvalCheck($lockedPlan);

            if (! $check['allowed']) {
                throw ValidationException::withMessages([
                    'plan' => collect($check['errors'])->pluck('message')->all(),
                ]);
            }

            if (
                $lockedPlan->business_status !== AutoSupplyPlan::BUSINESS_STATUS_APPROVED
                || ! $lockedPlan->approval_fingerprint
            ) {
                throw ValidationException::withMessages([
                    'plan' => ['Сначала утвердите текущую версию плана.'],
                ]);
            }

            if (! hash_equals((string) $lockedPlan->approval_fingerprint, (string) $check['fingerprint'])) {
                throw ValidationException::withMessages([
                    'plan' => ['План изменился после утверждения. Проверьте и утвердите его повторно.'],
                ]);
            }

            $lines = $this->eligibleLines($lockedPlan);
            $products = $this->productsForLines($lockedPlan, $lines);
            $supplies = collect();
            $createdCount = 0;

            foreach ($lines->groupBy(fn (AutoSupplyPlanLine $line) => (string) $line->cluster_id) as $clusterId => $clusterLines) {
                /** @var AutoSupplyPlanLine $first */
                $first = $clusterLines->first();
                $supply = Supply::query()->firstOrCreate(
                    [
                        'auto_supply_plan_id' => $lockedPlan->id,
                        'cluster_id' => $clusterId,
                    ],
                    [
                        'integration_id' => $lockedPlan->integration_id,
                        'supply_type' => Supply::TYPE_FBO,
                        'supply_method' => $this->supplyMethod($lockedPlan),
                        'delivery_scheme' => $this->supplyMethod($lockedPlan) === Supply::METHOD_CROSSDOCK
                            ? Supply::SCHEME_DROP_OFF
                            : null,
                        'cluster_name' => $first->cluster_name,
                        'drop_off_point_id' => $this->dropOffPointId($lockedPlan),
                        'status' => Supply::STATUS_DRAFT,
                        'created_by' => $userId,
                        'responsible_id' => $userId,
                        'comment' => 'Создано из утверждённого плана автопоставок Ozon.',
                        'meta' => [
                            'source' => 'ozon_auto_supply_plan',
                            'approval_fingerprint' => $lockedPlan->approval_fingerprint,
                        ],
                    ]
                );

                if ($supply->wasRecentlyCreated) {
                    $createdCount++;
                }

                foreach ($clusterLines as $line) {
                    $product = $this->productForLine($products, $line);
                    $ozonSku = $this->resolveOzonSku($product);

                    SupplyItem::query()->firstOrCreate(
                        [
                            'supply_id' => $supply->id,
                            'auto_supply_plan_line_id' => $line->id,
                        ],
                        [
                            'product_id' => $product?->id,
                            'sku' => (string) $line->sku,
                            'ozon_product_id' => (string) $ozonSku,
                            'barcode' => $line->barcode ?: $product?->barcode,
                            'product_name' => $line->product_name ?: $product?->name,
                            'planned_qty' => (int) $line->qty_rounded,
                            'pack_multiple' => 1,
                            'weight' => $product?->weight,
                            'length' => $product?->depth,
                            'width' => $product?->width,
                            'height' => $product?->height,
                            'status' => SupplyItem::STATUS_PENDING,
                            'meta' => [
                                'source' => 'ozon_auto_supply_plan',
                                'cluster_id' => (string) $line->cluster_id,
                                'offer_id' => $line->offer_id,
                            ],
                        ]
                    );
                }

                $supply->recalculateTotals();

                if ($supply->wasRecentlyCreated) {
                    $supply->logEvent(SupplyEvent::TYPE_CREATED, [
                        'title' => 'Поставка создана из автоплана Ozon',
                        'description' => sprintf(
                            'Кластер %s, позиций: %d, количество: %d',
                            $clusterId,
                            $clusterLines->count(),
                            (int) $clusterLines->sum('qty_rounded')
                        ),
                        'initiated_by' => $userId === null
                            ? SupplyEvent::INITIATED_BY_SYSTEM
                            : SupplyEvent::INITIATED_BY_USER,
                        'user_id' => $userId,
                    ]);
                }

                $supplies->push($supply->fresh()->load('items'));
            }

            if ($lockedPlan->materialized_at === null) {
                $lockedPlan->update(['materialized_at' => now()]);
            }

            return [
                'plan' => $lockedPlan->fresh(),
                'supplies' => $supplies,
                'created_count' => $createdCount,
            ];
        });
    }

    /**
     * @return Collection<int, AutoSupplyPlanLine>
     */
    private function eligibleLines(AutoSupplyPlan $plan): Collection
    {
        $selectedClusterIds = collect((array) ($plan->params['cluster_ids'] ?? []))
            ->map(fn ($clusterId) => (int) $clusterId)
            ->filter(fn (int $clusterId) => $clusterId > 0)
            ->unique()
            ->values()
            ->all();

        return $plan->lines()
            ->whereNotNull('cluster_id')
            ->where('qty_rounded', '>', 0)
            ->where('is_excluded', false)
            ->where(function ($query) {
                $query
                    ->where('is_cluster_split', true)
                    ->orWhere('destination_type', 'cluster');
            })
            ->when(
                $selectedClusterIds !== [],
                fn ($query) => $query->whereIn('cluster_id', $selectedClusterIds)
            )
            ->orderBy('cluster_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, AutoSupplyPlanLine>  $lines
     * @return Collection<string, Product>
     */
    private function productsForLines(AutoSupplyPlan $plan, Collection $lines): Collection
    {
        $skus = $lines->pluck('sku')->filter()->map(fn ($sku) => (string) $sku)->unique()->all();
        $offerIds = $lines->pluck('offer_id')->filter()->map(fn ($offerId) => (string) $offerId)->unique()->all();

        return Product::query()
            ->where('integration_id', $plan->integration_id)
            ->where(function ($query) use ($skus, $offerIds) {
                $query->whereIn('sku', $skus);
                if ($offerIds !== []) {
                    $query->orWhereIn('vendor_code', $offerIds);
                }
            })
            ->get()
            ->flatMap(function (Product $product): array {
                $keys = [(string) $product->sku];
                if ($product->vendor_code) {
                    $keys[] = (string) $product->vendor_code;
                }

                return collect($keys)
                    ->unique()
                    ->mapWithKeys(fn (string $key) => [$key => $product])
                    ->all();
            });
    }

    /**
     * @param  Collection<string, Product>  $products
     */
    private function productForLine(Collection $products, AutoSupplyPlanLine $line): ?Product
    {
        return $products->get((string) $line->sku)
            ?? ($line->offer_id ? $products->get((string) $line->offer_id) : null);
    }

    private function resolveOzonSku(?Product $product): ?int
    {
        if ($product === null) {
            return null;
        }

        $ozonData = is_array($product->ozon_data) ? $product->ozon_data : [];
        $value = $ozonData['sku']
            ?? $ozonData['product_id']
            ?? $product->marketplace_id
            ?? null;

        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function supplyMethod(AutoSupplyPlan $plan): string
    {
        $value = strtolower(trim((string) (
            $plan->params['draft_supply_method']
            ?? $plan->params['supply_method']
            ?? Supply::METHOD_DIRECT
        )));

        return in_array($value, ['crossdock', 'cross_dock', 'cross-dock'], true)
            ? Supply::METHOD_CROSSDOCK
            : Supply::METHOD_DIRECT;
    }

    private function dropOffPointId(AutoSupplyPlan $plan): ?string
    {
        $value = $plan->params['drop_off_point_warehouse_id']
            ?? $plan->params['crossdock_drop_off_point_warehouse_id']
            ?? null;

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  Collection<int, AutoSupplyPlanLine>  $lines
     * @param  Collection<string, Product>  $products
     */
    private function fingerprintFrom(AutoSupplyPlan $plan, Collection $lines, Collection $products): string
    {
        $normalizedLines = $lines
            ->map(function (AutoSupplyPlanLine $line) use ($products): array {
                return [
                    'id' => (int) $line->id,
                    'sku' => (string) $line->sku,
                    'offer_id' => (string) ($line->offer_id ?? ''),
                    'cluster_id' => (string) $line->cluster_id,
                    'qty' => (int) $line->qty_rounded,
                    'ozon_sku' => $this->resolveOzonSku($this->productForLine($products, $line)),
                ];
            })
            ->sortBy(fn (array $line) => sprintf('%s|%020d', $line['cluster_id'], $line['id']))
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'plan_id' => $plan->id,
            'integration_id' => (int) $plan->integration_id,
            'marketplace' => (string) $plan->marketplace,
            'supply_method' => $this->supplyMethod($plan),
            'drop_off_point_id' => $this->dropOffPointId($plan),
            'selected_cluster_ids' => collect((array) ($plan->params['cluster_ids'] ?? []))
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all(),
            'quality_audit' => $plan->plan_quality_audit,
            'lines' => $normalizedLines,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
