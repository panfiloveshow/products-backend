<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use App\Models\ManagerPromotionProposal;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsSettings;
use App\Services\UnitEconomics\CalculationFingerprint;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ManagerPromotionService
{
    public const CONFIRMATIONS = [
        'financial_assumptions_checked',
        'promotion_terms_and_limits_checked',
        'exact_sku_and_promo_price_checked',
    ];

    public function __construct(
        private ManagerStoreResolver $stores,
        private ManagerPriceService $prices,
        private UnitEconomicsCacheService $calculator,
        private ManagerPricePolicy $policy = new ManagerPricePolicy,
    ) {}

    public function list(
        ManagerIdentity $identity,
        int $integrationId,
        string $marketplace,
        ?string $status = null,
        int $page = 1,
        int $limit = 50,
    ): array {
        $this->readPermission($identity);
        [$integration, $store] = $this->resolveStore($identity, $integrationId, $marketplace);
        $rawPromos = $marketplace === 'wildberries'
            ? $this->fetchWbPromotions($store, $page, $limit)
            : $this->fetchOzonPromotions($store);

        $items = [];
        foreach ($rawPromos as $item) {
            if ($status !== null && $status !== '' && ($item['status'] ?? null) !== $status) {
                continue;
            }
            $items[] = $item;
        }

        if ($marketplace === 'ozon') {
            $offset = max(0, ($page - 1) * $limit);
            $total = count($items);
            $items = array_slice($items, $offset, $limit);
        } else {
            $total = count($items);
        }

        return [
            'workspace_id' => (string) $identity->workspaceId,
            'integration_id' => (string) $integration->id,
            'integration_name' => $store->name,
            'marketplace' => $marketplace,
            'data_updated_at' => now()->utc()->toIso8601String(),
            'items' => array_values($items),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ],
        ];
    }

    public function candidateContext(
        ManagerIdentity $identity,
        int $integrationId,
        string $marketplace,
        string $promotionId,
        string $productId,
        string $scheme,
        string $action,
        string $targetPromoPrice,
        ?array $constraints,
        #[\SensitiveParameter] string $token = '',
    ): array {
        $this->readPermission($identity);
        $normalizedConstraints = $this->policy->normalize($constraints);
        $normalizedPrice = DecimalMoney::normalize($targetPromoPrice);
        [$integration, $store] = $this->resolveStore($identity, $integrationId, $marketplace);

        $product = Product::query()->whereKey($productId)->where('integration_id', $integrationId)->first();
        if (! $product || $product->marketplace !== $marketplace) {
            throw new ManagerDataException('Товар не найден в выбранном магазине.', 404);
        }

        $promoMeta = $this->fetchPromotionMeta($store, $marketplace, $promotionId, $product, $action);
        $simulated = $this->evaluatePromotionScenario($identity, $integrationId, $product, $scheme, $normalizedPrice, $normalizedConstraints);
        $scenario = $simulated['scenario'];

        $resourceId = $this->nativeResourceId($product, $marketplace);
        $controlMeta = $this->inspectPriceExecutionControl($identity, $integrationId, $marketplace, $resourceId, $token);

        $binding = [
            'credential_binding' => $store->binding,
            'product_binding' => CalculationFingerprint::productBinding($product),
            'promotion_id' => $promotionId,
            'action' => $action,
            'fulfillment_type' => $scheme,
            'target_promo_price' => $normalizedPrice,
            'scenario_before' => $scenario['before'] ?? null,
            'scenario_after' => $scenario['after'] ?? null,
            'policy_status' => $scenario['policy']['status'] ?? null,
        ];

        $contextFingerprint = hash('sha256', json_encode($this->stable([
            'manager_promotion_context_v1',
            (string) $identity->workspaceId,
            (string) $integration->id,
            $marketplace,
            $promotionId,
            $product->id,
            $scheme,
            $action,
            $normalizedPrice,
            $promoMeta['max_allowed_promo_price'],
            $promoMeta['auto_action_enabled'],
            $binding,
        ]), JSON_THROW_ON_ERROR));

        return [
            'workspace_id' => (string) $identity->workspaceId,
            'integration_id' => (string) $integration->id,
            'integration_name' => $store->name,
            'marketplace' => $marketplace,
            'promotion_id' => $promotionId,
            'promotion_title' => $promoMeta['title'],
            'action' => $action,
            'product_id' => (string) $product->id,
            'product_sku' => (string) $product->sku,
            'fulfillment_type' => $scheme,
            'target_promo_price' => $normalizedPrice,
            'max_allowed_promo_price' => $promoMeta['max_allowed_promo_price'],
            'auto_action_enabled' => $promoMeta['auto_action_enabled'],
            'lock_in_warning' => $promoMeta['lock_in_warning'],
            'legacy_held_count' => $controlMeta['legacy_held_count'],
            'context_fingerprint' => $contextFingerprint,
            'observed_at' => now()->utc()->toIso8601String(),
            'scenario' => $scenario,
            '_control_mode' => $controlMeta['mode'],
            '_binding' => $binding,
        ];
    }

    public function prepare(
        ManagerIdentity $identity,
        int $integrationId,
        string $marketplace,
        string $promotionId,
        string $productId,
        string $scheme,
        string $action,
        string $targetPromoPrice,
        string $contextFingerprint,
        array $constraints,
        #[\SensitiveParameter] string $token = '',
    ): array {
        $this->preparePermission($identity);
        $this->available();

        $normalizedConstraints = $this->policy->normalize($constraints);
        $ctx = $this->candidateContext(
            $identity,
            $integrationId,
            $marketplace,
            $promotionId,
            $productId,
            $scheme,
            $action,
            $targetPromoPrice,
            $normalizedConstraints,
            $token,
        );

        if (($ctx['_control_mode'] ?? null) === 'stopped') {
            throw new ManagerDataException('Отправка цен и акций через исполнитель остановлена.', 409);
        }
        if (is_int($ctx['legacy_held_count']) && $ctx['legacy_held_count'] > 0) {
            throw new ManagerDataException(
                'По этому кабинету есть неразобранные прежние ценовые разрешения. Подготовка акции заблокирована до их личного разбора.',
                409,
                'legacy_price_admissions_unresolved',
            );
        }
        if (($ctx['scenario']['status'] ?? null) !== 'forecast') {
            throw new ManagerDataException('Финансовый прогноз участия в акции заблокирован.', 409);
        }
        if (($ctx['scenario']['policy']['status'] ?? null) !== 'within_limits') {
            throw new ManagerDataException('Целевая цена акции нарушает заданные финансовые ограничения.', 422);
        }
        if (! hash_equals($ctx['context_fingerprint'], $contextFingerprint)) {
            throw new ManagerDataException('Контекст акции или расчёта изменился. Обновите расчёт перед подготовкой.', 409);
        }

        $holdKey = $this->holdKey($integrationId, $promotionId, $productId);
        if (DB::table('manager_promotion_holds')->where('resource_key', $holdKey)->exists()) {
            throw new ManagerDataException('По этому товару и акции есть незавершённая или удерживаемая операция.', 409);
        }

        $now = now()->utc();
        $row = new ManagerPromotionProposal([
            'id' => (string) Str::uuid(),
            'workspace_id' => $identity->workspaceId,
            'created_by' => $identity->userId,
            'integration_id' => $integrationId,
            'integration_name' => $ctx['integration_name'],
            'marketplace' => $marketplace,
            'promotion_id' => $promotionId,
            'promotion_title' => $ctx['promotion_title'],
            'product_id' => $productId,
            'product_sku' => $ctx['product_sku'],
            'fulfillment_type' => $scheme,
            'action' => $action,
            'target_promo_price' => $ctx['target_promo_price'],
            'context_fingerprint' => $ctx['context_fingerprint'],
            'constraints' => $normalizedConstraints,
            'scenario' => $ctx['scenario'],
            'binding' => $ctx['_binding'],
            'status' => 'awaiting_confirmation',
            'events' => [['event' => 'prepared', 'at' => $now->toIso8601String()]],
            'created_at' => $now,
            'expires_at' => $now->copy()->addMinutes(5),
        ]);
        $row->fingerprint = $this->fingerprint($row);
        $row->save();

        return $this->expose($row, $identity);
    }

    public function get(ManagerIdentity $identity, string $id): array
    {
        $row = $this->owned($identity, $id);
        DB::transaction(function () use ($row) {
            $locked = ManagerPromotionProposal::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $this->integrity($locked, $locked->fingerprint);
            if ($locked->status === 'awaiting_confirmation' && $locked->expires_at->isPast()) {
                $this->event($locked, 'expired', ['status' => 'expired']);
            }
        });

        return $this->expose($row->fresh(), $identity);
    }

    public function cancel(ManagerIdentity $identity, string $id, string $fingerprint): array
    {
        $row = $this->owned($identity, $id);
        DB::transaction(function () use ($row, $fingerprint) {
            $locked = ManagerPromotionProposal::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $this->integrity($locked, $fingerprint);
            if ($locked->status === 'awaiting_confirmation') {
                $this->event($locked, 'cancelled', ['status' => 'cancelled']);
            }
        });

        return $this->get($identity, $id);
    }

    public function approve(
        ManagerIdentity $identity,
        string $id,
        string $fingerprint,
        array $confirmations,
        #[\SensitiveParameter] string $token = '',
    ): array {
        $this->approvePermission($identity);
        $this->available();
        $this->validateConfirmations($confirmations);

        $row = $this->owned($identity, $id);
        $this->integrity($row, $fingerprint);
        if ($row->status !== 'awaiting_confirmation') {
            return $this->get($identity, $id);
        }

        $live = $this->candidateContext(
            $identity,
            (int) $row->integration_id,
            $row->marketplace,
            $row->promotion_id,
            $row->product_id,
            $row->fulfillment_type,
            $row->action,
            $row->target_promo_price,
            $row->constraints,
            $token,
        );

        if (($live['_control_mode'] ?? null) === 'stopped') {
            throw new ManagerDataException('Отправка цен и акций остановлена.', 409);
        }
        if (is_int($live['legacy_held_count']) && $live['legacy_held_count'] > 0) {
            throw new ManagerDataException(
                'По этому кабинету есть неразобранные прежние ценовые разрешения.',
                409,
                'legacy_price_admissions_unresolved',
            );
        }
        if (
            ($live['scenario']['status'] ?? null) !== 'forecast'
            || ($live['scenario']['policy']['status'] ?? null) !== 'within_limits'
            || $this->encoded($live['_binding']) !== $this->encoded($row->binding)
        ) {
            DB::transaction(function () use ($row, $fingerprint) {
                $locked = ManagerPromotionProposal::whereKey($row->id)->lockForUpdate()->firstOrFail();
                $this->integrity($locked, $fingerprint);
                if ($locked->status === 'awaiting_confirmation') {
                    $this->event($locked, 'source_changed', ['status' => 'cancelled', 'message_code' => 'source_changed']);
                }
            });
            throw new ManagerDataException('Исходные данные цены или акции изменились перед применением.', 409);
        }

        [, $store] = $this->resolveStore($identity, (int) $row->integration_id, $row->marketplace);
        $product = Product::query()->whereKey($row->product_id)->where('integration_id', $row->integration_id)->firstOrFail();
        $holdKey = $this->holdKey((int) $row->integration_id, $row->promotion_id, $row->product_id);

        $claimed = DB::transaction(function () use ($row, $fingerprint, $confirmations, $identity, $holdKey): bool {
            $locked = ManagerPromotionProposal::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $this->integrity($locked, $fingerprint);
            if ($locked->status !== 'awaiting_confirmation') {
                return false;
            }
            if ($locked->expires_at->isPast()) {
                $this->event($locked, 'expired', ['status' => 'expired']);

                return false;
            }
            if (DB::table('manager_promotion_holds')->where('resource_key', $holdKey)->exists()) {
                throw new ManagerDataException('По этому товару и акции уже выполняется или удерживается операция.', 409);
            }
            DB::table('manager_promotion_holds')->insert([
                'resource_key' => $holdKey,
                'proposal_id' => $locked->id,
                'created_at' => now()->utc(),
            ]);
            $this->event($locked, 'personally_approved', [
                'status' => 'executing',
                'approval' => [
                    'confirmations' => $confirmations,
                    'confirmed_by' => (string) $identity->userId,
                    'confirmed_at' => now()->utc()->toIso8601String(),
                ],
            ]);

            return true;
        });

        if (! $claimed) {
            return $this->get($identity, $id);
        }

        try {
            $receipt = $this->dispatchNativePromotion($store, $product, $row);
            DB::transaction(function () use ($row, $receipt, $holdKey) {
                $locked = ManagerPromotionProposal::whereKey($row->id)->lockForUpdate()->firstOrFail();
                DB::table('manager_promotion_holds')->where('resource_key', $holdKey)->where('proposal_id', $locked->id)->delete();
                $this->event($locked, 'native_receipt_'.$receipt['status'], [
                    'status' => $receipt['status'],
                    'executor_receipt' => $receipt,
                ]);
            });
        } catch (ManagerDataException $e) {
            $definite = in_array($e->httpStatus, [400, 401, 403, 404, 409, 422], true);
            DB::transaction(function () use ($row, $definite, $holdKey, $e) {
                $locked = ManagerPromotionProposal::whereKey($row->id)->lockForUpdate()->firstOrFail();
                if ($definite) {
                    DB::table('manager_promotion_holds')->where('resource_key', $holdKey)->where('proposal_id', $locked->id)->delete();
                }
                if ($locked->status === 'executing') {
                    $this->event($locked, $definite ? 'native_rejected' : 'native_unknown', [
                        'status' => $definite ? 'failed' : 'unknown',
                        'message_code' => $e->reason ?? ($definite ? 'native_rejected' : 'receipt_unavailable'),
                    ]);
                }
            });
        } catch (\Throwable) {
            DB::transaction(function () use ($row) {
                $locked = ManagerPromotionProposal::whereKey($row->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'executing') {
                    $this->event($locked, 'native_unknown', [
                        'status' => 'unknown',
                        'message_code' => 'receipt_unavailable',
                    ]);
                }
            });
        }

        return $this->get($identity, $id);
    }

    private function dispatchNativePromotion(ManagerStore $store, Product $product, ManagerPromotionProposal $row): array
    {
        $nativeId = $this->nativeResourceId($product, $row->marketplace);
        if ($row->marketplace === 'wildberries') {
            if ($row->action === 'exit') {
                throw new ManagerDataException('Выход из автоакции WB не подтверждён Calendar upload API без отдельного изменения цены.', 422);
            }
            $response = Http::withHeaders(['Authorization' => $store->apiKey])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(25)
                ->post('https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions/upload', [
                    'data' => [
                        'promotionID' => (int) $row->promotion_id,
                        'uploadNow' => true,
                        'nomenclatures' => [(int) $nativeId],
                    ],
                ]);

            if (! $response->successful()) {
                $status = in_array($response->status(), [400, 401, 403, 404, 409, 422], true) ? $response->status() : 503;
                throw new ManagerDataException('WB Calendar API не подтвердил загрузку товара в акцию.', $status);
            }

            $uploadId = $response->json('data.uploadID');

            return [
                'native_change_id' => (string) Str::uuid(),
                'status' => 'succeeded',
                'native_state' => $uploadId !== null ? 'wb_upload_'.$uploadId : 'wb_uploaded',
            ];
        }

        $endpoint = $row->action === 'enter'
            ? 'https://api-seller.ozon.ru/v1/actions/products/activate'
            : 'https://api-seller.ozon.ru/v1/actions/products/deactivate';

        $payload = $row->action === 'enter'
            ? [
                'action_id' => (int) $row->promotion_id,
                'products' => [[
                    'product_id' => (int) $nativeId,
                    'action_price' => (float) $row->target_promo_price,
                ]],
            ]
            : [
                'action_id' => (int) $row->promotion_id,
                'product_ids' => [(int) $nativeId],
            ];

        $response = Http::withHeaders([
            'Client-Id' => (string) $store->clientId,
            'Api-Key' => $store->apiKey,
        ])->acceptJson()->connectTimeout(5)->timeout(25)->post($endpoint, $payload);

        if (! $response->successful()) {
            $status = in_array($response->status(), [400, 401, 403, 404, 409, 422], true) ? $response->status() : 503;
            throw new ManagerDataException('Ozon Actions API не подтвердил изменение участия в акции.', $status);
        }

        $rejected = $response->json('result.rejected') ?? [];
        if (is_array($rejected) && $rejected !== []) {
            throw new ManagerDataException('Ozon отклонил товар при изменении участия в акции.', 422);
        }

        return [
            'native_change_id' => (string) Str::uuid(),
            'status' => 'succeeded',
            'native_state' => $row->action === 'enter' ? 'ozon_activated' : 'ozon_deactivated',
        ];
    }

    private function evaluatePromotionScenario(
        ManagerIdentity $identity,
        int $integrationId,
        Product $product,
        string $scheme,
        string $targetPromoPrice,
        ?array $constraints,
    ): array {
        $simulated = $this->prices->simulate($identity, $integrationId, (string) $product->id, $scheme, $targetPromoPrice, $constraints);
        if (($simulated['scenario']['status'] ?? null) === 'forecast') {
            return $simulated;
        }

        $blockers = $simulated['scenario']['blockers'] ?? [];
        if ($blockers === ['promotion_context_requires_separate_scenario']) {
            $targetMinor = DecimalMoney::minor($targetPromoPrice);
            $preview = $this->calculator->previewPrice($product, $scheme, $targetMinor / 100);
            unset($preview['before']['_calculation_identity'], $preview['after']['_calculation_identity'], $preview['inputs'], $preview['before']['financial_input_usage'], $preview['after']['financial_input_usage']);
            $assessment = $this->policy->evaluate($preview, $targetPromoPrice, $constraints);
            $simulated['scenario'] = [
                'status' => 'forecast',
                'seller_price' => DecimalMoney::format($targetMinor),
                ...$preview,
                'assumptions' => ['Расчёт выполнен для сценария участия/выхода из акции по текущим тарифам и настройкам.'],
                'blockers' => [],
                'policy' => $assessment,
                'execution_allowed' => false,
            ];
        }

        return $simulated;
    }

    private function inspectPriceExecutionControl(
        ManagerIdentity $identity,
        int $integrationId,
        string $marketplace,
        string $resourceId,
        #[\SensitiveParameter] string $token,
    ): array {
        $base = rtrim((string) config('services.manager.price_executor_url', ''), '/');
        if (! config('services.manager.price_execution_enabled', false) || $base === '' || $token === '') {
            return ['mode' => 'manual_only', 'legacy_held_count' => 0];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(20)
            ->withOptions(['allow_redirects' => false])
            ->withHeaders(['X-Sellico-Workspace' => (string) $identity->workspaceId])
            ->get($base.'/manager/prices/execution-controls/context', [
                'integration_id' => (string) $integrationId,
                'resource_id' => $resourceId,
            ]);

        if (! $response->successful()) {
            throw new ManagerDataException(
                'Не удалось проверить текущее ограничение исполнителя цен.',
                in_array($response->status(), [401, 403, 409, 422], true) ? $response->status() : 503,
            );
        }

        $raw = $response->json('data');
        if (! is_array($raw)) {
            throw new ManagerDataException('Некорректный ответ исполнителя цен.', 503);
        }

        $held = $raw['legacy_held_count'] ?? 0;
        if (! is_int($held) || $held < 0) {
            throw new ManagerDataException('Некорректный счётчик прежних ценовых разрешений.', 503);
        }

        $mode = is_array($raw['control'] ?? null) ? (string) ($raw['control']['mode'] ?? 'legacy') : 'legacy';

        return [
            'mode' => $mode,
            'legacy_held_count' => $held,
        ];
    }

    private function fetchWbPromotions(ManagerStore $store, int $page, int $limit): array
    {
        $start = now()->utc()->subDays(7)->format('Y-m-d\TH:i:s\Z');
        $end = now()->utc()->addDays(60)->format('Y-m-d\TH:i:s\Z');
        $offset = max(0, ($page - 1) * $limit);

        $response = Http::withHeaders(['Authorization' => $store->apiKey])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->get('https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions', [
                'startDateTime' => $start,
                'endDateTime' => $end,
                'allPromo' => 'true',
                'limit' => $limit,
                'offset' => $offset,
            ]);

        if (! $response->successful()) {
            throw new ManagerDataException('WB Calendar API не вернул список акций.', 503);
        }

        $rows = $response->json('data.promotions') ?? [];
        if (! is_array($rows)) {
            throw new ManagerDataException('Некорректный формат списка акций WB.', 503);
        }

        $items = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! isset($r['id'], $r['name'])) {
                continue;
            }
            $type = isset($r['type']) ? (string) $r['type'] : 'regular';
            $isAuto = $type === 'auto';
            $items[] = [
                'promotion_id' => (string) $r['id'],
                'title' => (string) $r['name'],
                'promotion_type' => $type,
                'status' => 'active',
                'starts_at' => isset($r['startDateTime']) ? (string) $r['startDateTime'] : null,
                'ends_at' => isset($r['endDateTime']) ? (string) $r['endDateTime'] : null,
                'auto_action_enabled' => $isAuto,
                'lock_in_warning' => $isAuto ? 'Автоакция WB: проверьте ограничения выхода и минимальной скидки.' : null,
                'participating_products_count' => isset($r['inPromoActionTotal']) && is_int($r['inPromoActionTotal'])
                    ? $r['inPromoActionTotal']
                    : null,
            ];
        }

        return $items;
    }

    private function fetchOzonPromotions(ManagerStore $store): array
    {
        $response = Http::withHeaders([
            'Client-Id' => (string) $store->clientId,
            'Api-Key' => $store->apiKey,
        ])->acceptJson()->connectTimeout(5)->timeout(20)->get('https://api-seller.ozon.ru/v1/actions');

        if (! $response->successful()) {
            throw new ManagerDataException('Ozon Actions API не вернул список акций.', 503);
        }

        $rows = $response->json('result') ?? [];
        if (! is_array($rows)) {
            throw new ManagerDataException('Некорректный формат списка акций Ozon.', 503);
        }

        $items = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! isset($r['id'], $r['title'])) {
                continue;
            }
            $freezeDate = isset($r['freeze_date']) && $r['freeze_date'] !== '' ? (string) $r['freeze_date'] : null;
            $items[] = [
                'promotion_id' => (string) $r['id'],
                'title' => (string) $r['title'],
                'promotion_type' => isset($r['action_type']) ? (string) $r['action_type'] : null,
                'status' => isset($r['action_status']) ? strtolower((string) $r['action_status']) : 'active',
                'starts_at' => isset($r['date_start']) ? (string) $r['date_start'] : null,
                'ends_at' => isset($r['date_end']) ? (string) $r['date_end'] : null,
                'auto_action_enabled' => (bool) ($r['is_voucher_action'] ?? false),
                'lock_in_warning' => $freezeDate !== null ? "Дата заморозки условий акции: {$freezeDate}" : null,
                'participating_products_count' => isset($r['participating_products_count']) && is_int($r['participating_products_count'])
                    ? $r['participating_products_count']
                    : null,
            ];
        }

        return $items;
    }

    private function fetchPromotionMeta(
        ManagerStore $store,
        string $marketplace,
        string $promotionId,
        Product $product,
        string $action,
    ): array {
        $promos = $marketplace === 'wildberries'
            ? $this->fetchWbPromotions($store, 1, 100)
            : $this->fetchOzonPromotions($store);

        $matched = null;
        foreach ($promos as $promo) {
            if ($promo['promotion_id'] === $promotionId) {
                $matched = $promo;
                break;
            }
        }
        if ($matched === null) {
            throw new ManagerDataException('Акция не найдена в кабинете выбранного магазина.', 404);
        }

        $maxAllowed = null;
        $nativeId = (int) $this->nativeResourceId($product, $marketplace);

        if ($marketplace === 'wildberries') {
            $resp = Http::withHeaders(['Authorization' => $store->apiKey])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->get('https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions/nomenclatures', [
                    'promotionID' => (int) $promotionId,
                    'inAction' => $action === 'exit' ? 'true' : 'false',
                    'limit' => 100,
                    'offset' => 0,
                ]);
            if ($resp->successful()) {
                foreach (($resp->json('data.nomenclatures') ?? []) as $nom) {
                    if ((int) ($nom['id'] ?? 0) === $nativeId && isset($nom['planPrice'])) {
                        $maxAllowed = DecimalMoney::normalize((string) $nom['planPrice']);
                        break;
                    }
                }
            }
        } elseif ($action === 'enter') {
            $resp = Http::withHeaders([
                'Client-Id' => (string) $store->clientId,
                'Api-Key' => $store->apiKey,
            ])->acceptJson()->connectTimeout(5)->timeout(20)->post('https://api-seller.ozon.ru/v1/actions/candidates', [
                'action_id' => (int) $promotionId,
                'limit' => 100,
                'offset' => 0,
            ]);
            if ($resp->successful()) {
                foreach (($resp->json('result.products') ?? []) as $cand) {
                    if ((int) ($cand['id'] ?? 0) === $nativeId && isset($cand['max_action_price'])) {
                        $maxAllowed = DecimalMoney::normalize((string) $cand['max_action_price']);
                        break;
                    }
                }
            }
        }

        return [
            'title' => $matched['title'],
            'auto_action_enabled' => (bool) $matched['auto_action_enabled'],
            'lock_in_warning' => $matched['lock_in_warning'],
            'max_allowed_promo_price' => $maxAllowed,
        ];
    }

    private function resolveStore(ManagerIdentity $identity, int $integrationId, string $marketplace): array
    {
        if (! in_array($marketplace, ['wildberries', 'ozon'], true)) {
            throw new ManagerDataException('Акции поддерживаются для Wildberries и Ozon.', 422);
        }
        $integration = Integration::query()->whereKey($integrationId)->where('work_space_id', $identity->workspaceId)->first();
        if (! $integration || $integration->marketplace !== $marketplace) {
            throw new ManagerDataException('Магазин не найден в выбранном workspace.', 404);
        }
        $store = $this->stores->resolve($identity, $integrationId, $marketplace);

        return [$integration, $store];
    }

    private function nativeResourceId(Product $product, string $marketplace): string
    {
        $raw = $marketplace === 'wildberries'
            ? ($product->wb_data['nmID'] ?? null)
            : ($product->ozon_data['product_id'] ?? null);
        $str = is_int($raw) || is_string($raw) ? (string) $raw : '';
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $str)) {
            throw new ManagerDataException('Не подтверждён нативный идентификатор товара площадки.', 422);
        }

        return $str;
    }

    private function readPermission(ManagerIdentity $identity): void
    {
        foreach (['products.view', 'marketing.view', 'unit_economics.view'] as $perm) {
            if (! $identity->permits($perm)) {
                throw new ManagerDataException('Недостаточно прав для чтения акций и финансового расчёта.', 403);
            }
        }
    }

    private function preparePermission(ManagerIdentity $identity): void
    {
        if (! $identity->personalConfirmed) {
            throw new ManagerDataException('Подготовка изменения акции требует личного аккаунта.', 403);
        }
        $this->readPermission($identity);
        if (! $identity->permits('unit_economics.calculate')) {
            throw new ManagerDataException('Недостаточно прав на расчёт юнит-экономики для акции.', 403);
        }
    }

    private function approvePermission(ManagerIdentity $identity): void
    {
        $this->preparePermission($identity);
        foreach (['marketing.manage', 'integrations.view'] as $perm) {
            if (! $identity->permits($perm)) {
                throw new ManagerDataException('Нет личного права на применение изменений акций.', 403);
            }
        }
    }

    private function available(): void
    {
        if (! config('services.manager.promotions_enabled', false) || ! Schema::hasTable('manager_promotion_proposals')) {
            throw new ManagerDataException('Согласование акций Сели остановлено или ожидает миграции.', 503);
        }
    }

    private function validateConfirmations(array $confirmations): void
    {
        if (count($confirmations) !== count(self::CONFIRMATIONS)) {
            throw new ManagerDataException('Нужны все три личные отметки подтверждения акции.', 422);
        }
        foreach (self::CONFIRMATIONS as $key) {
            if (($confirmations[$key] ?? null) !== true) {
                throw new ManagerDataException('Каждая из трёх отметок подтверждения акции должна быть включена.', 422);
            }
        }
    }

    private function owned(ManagerIdentity $identity, string $id): ManagerPromotionProposal
    {
        $this->readPermission($identity);
        $row = ManagerPromotionProposal::whereKey($id)
            ->where('workspace_id', $identity->workspaceId)
            ->where('created_by', $identity->userId)
            ->first();
        if (! $row) {
            throw new ManagerDataException('Личное предложение по акции не найдено.', 404);
        }

        return $row;
    }

    private function holdKey(int $integrationId, string $promotionId, string $productId): string
    {
        return hash('sha256', "promo:{$integrationId}:{$promotionId}:{$productId}");
    }

    private function fingerprint(ManagerPromotionProposal $row): string
    {
        return hash('sha256', json_encode($this->stable([
            'manager_promotion_proposal_v1',
            $row->id,
            (string) $row->workspace_id,
            (string) $row->created_by,
            (string) $row->integration_id,
            $row->marketplace,
            $row->promotion_id,
            $row->promotion_title,
            $row->product_id,
            $row->product_sku,
            $row->fulfillment_type,
            $row->action,
            $row->target_promo_price,
            $row->context_fingerprint,
            $row->constraints,
            $row->binding,
            $row->created_at->utc()->toIso8601String(),
            $row->expires_at->utc()->toIso8601String(),
        ]), JSON_THROW_ON_ERROR));
    }

    private function integrity(ManagerPromotionProposal $row, string $fingerprint): void
    {
        if (! hash_equals($row->fingerprint, $fingerprint) || ! hash_equals($row->fingerprint, $this->fingerprint($row))) {
            throw new ManagerDataException('Состав предложения по акции изменился.', 409);
        }
    }

    private function event(ManagerPromotionProposal $row, string $event, array $changes = []): void
    {
        $row->forceFill([
            ...$changes,
            'events' => [...($row->events ?? []), ['event' => $event, 'at' => now()->utc()->toIso8601String()]],
        ])->save();
    }

    private function expose(ManagerPromotionProposal $row, ManagerIdentity $identity): array
    {
        $approvalAvailable = $row->status === 'awaiting_confirmation'
            && ! $row->expires_at->isPast()
            && $identity->personalConfirmed
            && $identity->permits('marketing.manage')
            && $identity->permits('integrations.view')
            && $identity->permits('unit_economics.calculate')
            && config('services.manager.promotions_enabled', false);

        return [
            'schema_version' => 1,
            'operation_type' => 'promotion_change_proposal',
            'id' => $row->id,
            'fingerprint' => $row->fingerprint,
            'workspace_id' => (string) $row->workspace_id,
            'integration_id' => (string) $row->integration_id,
            'integration_name' => $row->integration_name,
            'marketplace' => $row->marketplace,
            'promotion_id' => $row->promotion_id,
            'promotion_title' => $row->promotion_title,
            'product_id' => $row->product_id,
            'product_sku' => $row->product_sku,
            'fulfillment_type' => $row->fulfillment_type,
            'action' => $row->action,
            'target_promo_price' => $row->target_promo_price,
            'status' => $row->status,
            'approval_available' => (bool) $approvalAvailable,
            'promotion_changed' => $row->status === 'succeeded',
            'scenario' => $row->scenario,
            'executor_receipt' => $row->executor_receipt,
            'created_at' => $row->created_at->utc()->toIso8601String(),
            'expires_at' => $row->expires_at->utc()->toIso8601String(),
        ];
    }

    private function stable(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            $value[$k] = $this->stable($v);
        }

        return $value;
    }

    private function encoded(mixed $value): string
    {
        return json_encode($this->stable($value), JSON_THROW_ON_ERROR);
    }
}
