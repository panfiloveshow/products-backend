<?php

namespace App\Domains\Locality\Ingestion;

use App\Domains\Ozon\Api\OzonClient;
use App\Models\Integration;
use App\Models\OzonFinanceTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Загружает финансовые данные Ozon в таблицу ozon_finance_transactions.
 * Источник "actual" для Reconciliation-слоя и фактических ставок
 * юнит-экономики (OzonActualRatesService).
 *
 * С 2026-08-25 работает на новых эндпоинтах (legacy /v3/finance/transaction/list
 * отключается Ozon-ом 2026-09-08, см. dev.ozon.ru/news/783):
 *  - POST /v1/finance/realization/by-day — продажи/возвраты по SKU за день
 *    (выручка seller_price × qty, комиссия standard_fee);
 *  - POST /v1/finance/accrual/by-day — начисления услуг по type_id
 *    (пагинация по last_id; Ozon требует строго последовательные запросы,
 *    параллельные режет 429-ым).
 *
 * Формат строк в таблице:
 *  - продажа: operation_type = 'OperationAgentDeliveredToCustomer' (legacy-имя
 *    сохранено намеренно — OzonActualRatesService видит непрерывную историю
 *    units/revenue без ветвления по источнику), accruals_for_sale = выручка,
 *    raw.quantity = штук в строке;
 *  - услуга: operation_type = имя типа из /v1/finance/accrual/types
 *    ('Acquiring', 'LastMileCourier', 'Logistic', ...), amount со знаком Ozon.
 *
 * operation_id новых строк всегда содержит ':' — по этому признаку строки дня
 * из legacy-источника удаляются при первой успешной записи дня новым форматом
 * (иначе скользящее окно джоба задвоило бы последние дни).
 */
class FinanceTransactionSyncer
{
    private const ENDPOINT_REALIZATION_BY_DAY = '/v1/finance/realization/by-day';
    private const ENDPOINT_ACCRUAL_BY_DAY = '/v1/finance/accrual/by-day';

    public const SALE_OPERATION_TYPE = 'OperationAgentDeliveredToCustomer';
    public const RETURN_OPERATION_TYPE = 'ClientReturnAgentOperation';

    /** Пауза между запросами к Ozon: новый API строго последовательный. */
    private const REQUEST_PAUSE_US = 700_000;

    /** Защита от зацикливания пагинации accrual/by-day. */
    private const MAX_ACCRUAL_PAGES = 200;

    /**
     * /v1/finance/accrual/types на 2026-08-25. Неизвестный id получает имя
     * "AccrualType_{id}" — словарь пополняется у Ozon без предупреждения.
     */
    private const ACCRUAL_TYPE_NAMES = [
        1 => 'Acquiring', 2 => 'BackwardShipment', 3 => 'BrandCommission',
        4 => 'BrandPromotion', 5 => 'BrandShelf', 6 => 'Cancellation',
        7 => 'Charity', 8 => 'ClaimCommission', 9 => 'ClientReturn',
        10 => 'Compensation', 11 => 'CorrectionCommission', 12 => 'CrossDock',
        13 => 'CrossDockPickUpCourierDelivery', 14 => 'DefectRate', 15 => 'Disposal',
        16 => 'Drop-Off', 17 => 'Drop-Off Agent', 18 => 'EarlyPayment',
        19 => 'ExternalPromotion', 20 => 'FlexiblePayments', 21 => 'Fulfillment',
        22 => 'Installment', 23 => 'InternetSiteAdvertising', 24 => 'ItemCloning',
        25 => 'ItemCompensation', 26 => 'KazakhstanBuyerInstallment', 27 => 'LabelOriginal',
        28 => 'LastMile', 29 => 'LastMileCourier', 30 => 'LastMilePickUpPoint',
        31 => 'LeadGeneration', 32 => 'Logistic', 33 => 'Marketing',
        34 => 'Marking', 35 => 'Moderation', 36 => 'OrdersBooking',
        37 => 'OzonData', 38 => 'PackageCost', 39 => 'PackingFee',
        40 => 'PartialReturn', 41 => 'PayPerClick', 42 => 'Pick-Up',
        43 => 'PickUpCourierArrangement', 44 => 'PickUpCourierDelivery', 45 => 'PickUpPointReturnAcceptance',
        46 => 'Placements', 47 => 'PointsForReviews', 48 => 'PremiumCashbackIndividualPoints',
        49 => 'PremiumCashbackPromotion', 50 => 'PremiumMailingCommission', 51 => 'PremiumMembership',
        52 => 'PremiumSubscription', 53 => 'PreparingToReturn', 54 => 'Promotion',
        55 => 'PushCampaign', 56 => 'QuantProcessingDrop', 57 => 'RealizationReportCorrection',
        58 => 'Replenishment', 59 => 'ReturnFlowLogistic', 60 => 'ReturnStorageInTheWarehouse',
        61 => 'ReviewsPin', 62 => 'RfbsClientDeliveryCharge', 63 => 'RfbsDomesticAgentFee',
        64 => 'RfbsDomesticDelivery', 65 => 'RfbsEasyReturn', 66 => 'RfbsGlobalAgentFee',
        67 => 'RfbsGlobalDelivery', 68 => 'RfbsServiceFee', 69 => 'SaleCommission',
        70 => 'SaleReview', 71 => 'SellerReturns', 72 => 'SetOff',
        73 => 'Shipment', 74 => 'StarsMembership', 75 => 'Stencil',
        76 => 'StockInsurance', 77 => 'SupplyInbound', 78 => 'TemporaryPlacement',
        79 => 'TemporaryPlacementsAgent', 80 => 'VideoCover', 81 => 'VolumeObligationReward',
        82 => 'VolumeWeightCharacteristicsProcessing', 83 => 'BrandDeposit', 84 => 'ItemPacking',
        85 => 'ItemSealing', 86 => 'PackmanCisPacking', 87 => 'SocialMediaAdvertising',
        88 => 'ClickAndCollect', 89 => 'DefectFineModeration', 90 => 'DefectFineProhibitedGoods',
        91 => 'DefectFineCounterfeitGoods', 92 => 'DefectFineComplaint', 93 => 'DefectFineErrors',
        94 => 'DefectFineShipmentDelayRate', 95 => 'CustomerReviews', 96 => 'AcceleratedReviewCollection',
        97 => 'PackageUnitProcessing', 98 => 'DeliveryToHandoverPlaceByOzon', 99 => 'InternationalLogisticDelta',
        100 => 'OzonGlobalLogisticsDelivery', 101 => 'OversizedExtraHandling', 102 => 'B2CTemporaryPlacement',
        103 => 'B2CDisposal', 104 => 'B2CInsuranceCompensation', 105 => 'B2CInsuranceShipping',
        106 => 'B2C Drop-Off', 107 => 'B2C Drop-Off Agent', 108 => 'B2CContainerPacking',
        109 => 'B2CContainerPackage', 110 => 'B2CCourierClientReinvoice', 111 => 'B2CDeliveryToHandoverPlaceByOzon',
        112 => 'B2CPickUpPointClientReinvoice', 113 => 'B2CPickUpPointReturnAcceptance', 114 => 'B2CLogistics',
        115 => 'B2CBackwardLogistics', 116 => 'FirstCustomerReview', 117 => 'IncreaseAssortmentLimit',
        118 => 'LabelBrandVerified', 119 => 'CustomerChatPoints', 120 => 'CourierPickUpByOzon',
        121 => 'CourierPickUpReinvoice',
    ];

    public function syncForIntegration(Integration $integration, Carbon $from, Carbon $to): SyncResult
    {
        $client = OzonClient::fromIntegration($integration);
        $integrationId = (int) $integration->id;

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        Log::channel('locality')->info('FinanceTransactionSyncer started', [
            'integration_id' => $integrationId,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'source' => 'accrual+realization by-day',
        ]);

        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $cursor->addDay();

            try {
                $rows = [
                    ...$this->fetchRealizationRows($client, $integrationId, $date),
                    ...$this->fetchAccrualRows($client, $integrationId, $date),
                ];
            } catch (\Throwable $e) {
                // by-day доступен только с подпиской Ozon Premium Plus: у магазинов
                // без неё каждый день отвечает 403, и после миграции 25.08 их
                // финданные замирали. Падаем на legacy /v3/finance/transaction/list
                // (жив до 08.09.2026) и помечаем интеграцию для UI-предупреждения.
                if (str_contains($e->getMessage(), 'HTTP 403')) {
                    $this->markPremiumPlusRequired($integration, true);

                    return $this->syncLegacyRange($client, $integration, $from, $to);
                }
                Log::channel('locality')->error('FinanceTransactionSyncer day failed', [
                    'integration_id' => $integrationId,
                    'date' => $date,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($rows === []) {
                // Пустой день не трогаем: возможен временный пустой ответ API,
                // а удаление уже записанного дня по нему — потеря данных.
                continue;
            }

            DB::transaction(function () use ($rows, $integrationId, $date, &$inserted, &$updated, &$skipped) {
                // Стираем строки этого дня из legacy-источника (их operation_id —
                // числовой id без ':'), иначе новые строки суммируются со старыми.
                OzonFinanceTransaction::query()
                    ->where('integration_id', $integrationId)
                    ->whereDate('operation_date', $date)
                    ->where('operation_id', 'NOT LIKE', '%:%')
                    ->delete();

                foreach ($rows as $row) {
                    $attrs = [
                        'integration_id' => $integrationId,
                        'operation_id' => $row['operation_id'],
                    ];
                    $values = $row;
                    unset($values['operation_id']);
                    $values['fetched_at'] = now();

                    $existing = OzonFinanceTransaction::query()->where($attrs)->first();
                    if ($existing === null) {
                        OzonFinanceTransaction::query()->create(array_merge($attrs, $values));
                        $inserted++;
                    } else {
                        $existing->fill($values);
                        if ($existing->isDirty()) {
                            $existing->save();
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    }
                }
            });
        }

        Log::channel('locality')->info('FinanceTransactionSyncer completed', [
            'integration_id' => $integrationId,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        $this->markPremiumPlusRequired($integration, false);

        return new SyncResult($inserted, $updated, $skipped);
    }

    private function markPremiumPlusRequired(Integration $integration, bool $required): void
    {
        $settings = is_array($integration->settings) ? $integration->settings : [];
        if (($settings['ozon_premium_plus_required'] ?? false) === $required) {
            return;
        }
        $settings['ozon_premium_plus_required'] = $required;
        $integration->forceFill(['settings' => $settings])->save();
        Log::channel('locality')->info('Ozon Premium Plus flag changed', [
            'integration_id' => $integration->id,
            'required' => $required,
        ]);
    }

    /**
     * Legacy-фолбэк: POST /v3/finance/transaction/list (Ozon отключает 08.09.2026).
     * Для магазинов без Premium Plus это единственный источник финопераций.
     */
    private function syncLegacyRange(OzonClient $client, Integration $integration, Carbon $from, Carbon $to): SyncResult
    {
        $integrationId = (int) $integration->id;
        $page = 1;
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        Log::channel('locality')->info('FinanceTransactionSyncer legacy fallback', [
            'integration_id' => $integrationId,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
        ]);

        while (true) {
            $response = $client->post('/v3/finance/transaction/list', [
                'filter' => [
                    'date' => [
                        'from' => $from->toIso8601String(),
                        'to' => $to->toIso8601String(),
                    ],
                    'transaction_type' => 'all',
                ],
                'page' => $page,
                'page_size' => 1000,
            ]);
            if (! is_array($response) || isset($response['_error'])) {
                Log::channel('locality')->error('FinanceTransactionSyncer legacy API error', [
                    'integration_id' => $integrationId,
                    'page' => $page,
                ]);
                break;
            }

            $operations = $response['result']['operations'] ?? [];
            if (empty($operations)) {
                break;
            }

            DB::transaction(function () use ($operations, $integrationId, &$inserted, &$updated, &$skipped) {
                foreach ($operations as $op) {
                    $operationId = $op['operation_id'] ?? null;
                    if ($operationId === null) {
                        $skipped++;

                        continue;
                    }

                    $posting = $op['posting'] ?? [];
                    $firstItem = ($op['items'] ?? [])[0] ?? [];
                    $attrs = [
                        'integration_id' => $integrationId,
                        'operation_id' => (string) $operationId,
                    ];
                    $values = [
                        'operation_type' => $op['operation_type'] ?? null,
                        'operation_type_name' => $op['operation_type_name'] ?? null,
                        'operation_date' => isset($op['operation_date'])
                            ? Carbon::parse($op['operation_date'])->toDateTimeString()
                            : null,
                        'posting_number' => $posting['posting_number'] ?? null,
                        'sku' => isset($firstItem['sku']) ? (string) $firstItem['sku'] : null,
                        'offer_id' => $firstItem['offer_id'] ?? null,
                        'amount' => (float) ($op['amount'] ?? 0),
                        'accruals_for_sale' => isset($op['accruals_for_sale']) ? (float) $op['accruals_for_sale'] : null,
                        'sale_commission' => isset($op['sale_commission']) ? (float) $op['sale_commission'] : null,
                        'warehouse_id' => $posting['warehouse_id'] ?? null,
                        'warehouse_name' => $posting['warehouse_name'] ?? null,
                        'raw' => $op,
                        'fetched_at' => now(),
                    ];

                    $existing = OzonFinanceTransaction::query()->where($attrs)->first();
                    if ($existing === null) {
                        OzonFinanceTransaction::query()->create(array_merge($attrs, $values));
                        $inserted++;
                    } elseif ($existing->fill($values)->isDirty()) {
                        $existing->save();
                        $updated++;
                    } else {
                        $skipped++;
                    }
                }
            });

            $totalPages = (int) ($response['result']['page_count'] ?? 0);
            if (($totalPages > 0 && $page >= $totalPages) || count($operations) < 1000) {
                break;
            }
            $page++;
        }

        Log::channel('locality')->info('FinanceTransactionSyncer legacy completed', [
            'integration_id' => $integrationId,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return new SyncResult($inserted, $updated, $skipped);
    }

    /**
     * Продажи/возвраты дня из /v1/finance/realization/by-day.
     * Одна строка = один SKU; количество единиц лежит в raw.quantity
     * (потребители суммируют его вместо count(*)).
     *
     * @return list<array<string,mixed>>
     */
    private function fetchRealizationRows(OzonClient $client, int $integrationId, string $date): array
    {
        $day = Carbon::parse($date);
        usleep(self::REQUEST_PAUSE_US);
        $response = $client->post(self::ENDPOINT_REALIZATION_BY_DAY, [
            'day' => $day->day,
            'month' => $day->month,
            'year' => $day->year,
        ]);

        if (! is_array($response) || isset($response['_error'])) {
            // 404/пусто для свежего дня — норма (отчёт ещё не сформирован).
            $status = is_array($response) ? ($response['_http_status'] ?? null) : null;
            if ($status !== null && $status !== 404) {
                throw new \RuntimeException("realization/by-day HTTP {$status}");
            }

            return [];
        }

        $rows = $response['rows'] ?? data_get($response, 'result.rows', []);
        if (! is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $item = is_array($row['item'] ?? null) ? $row['item'] : [];
            $sku = trim((string) ($item['sku'] ?? ''));
            $sellerPrice = (float) ($row['seller_price_per_instance'] ?? 0);

            foreach ([
                'delivery_commission' => [self::SALE_OPERATION_TYPE, 1],
                'return_commission' => [self::RETURN_OPERATION_TYPE, -1],
            ] as $key => [$operationType, $sign]) {
                $commission = $row[$key] ?? null;
                if (! is_array($commission)) {
                    continue;
                }

                $quantity = max(1, (int) ($commission['quantity'] ?? 1));
                $gross = round(abs($sellerPrice) * $quantity, 2);
                if ($gross <= 0) {
                    continue;
                }

                $result[] = [
                    'operation_id' => "rlz:{$date}:{$operationType}:" . ($sku !== '' ? $sku : $index),
                    'operation_type' => $operationType,
                    'operation_type_name' => $sign > 0 ? 'Продажа (realization/by-day)' : 'Возврат (realization/by-day)',
                    'operation_date' => $date . ' 00:00:00',
                    'posting_number' => null,
                    'sku' => $sku !== '' ? $sku : null,
                    'offer_id' => $item['offer_id'] ?? null,
                    'amount' => $sign * $gross,
                    'accruals_for_sale' => $sign * $gross,
                    'sale_commission' => -1 * $sign * abs((float) ($commission['standard_fee'] ?? 0)),
                    'warehouse_id' => null,
                    'warehouse_name' => null,
                    'raw' => [
                        'source' => 'realization_by_day',
                        'quantity' => $quantity,
                        'seller_price_per_instance' => $sellerPrice,
                        'commission' => $commission,
                        'item' => $item,
                    ],
                ];
            }
        }

        return $result;
    }

    /**
     * Начисления услуг дня из /v1/finance/accrual/by-day (пагинация по last_id).
     * Одна строка = accrual_id × type_id × sku, суммы агрегированы.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchAccrualRows(OzonClient $client, int $integrationId, string $date): array
    {
        $aggregated = [];
        $lastId = null;

        for ($page = 0; $page < self::MAX_ACCRUAL_PAGES; $page++) {
            usleep(self::REQUEST_PAUSE_US);
            $payload = ['date' => $date];
            if ($lastId !== null && $lastId !== '') {
                $payload['last_id'] = $lastId;
            }

            $response = $client->post(self::ENDPOINT_ACCRUAL_BY_DAY, $payload);
            if (! is_array($response) || isset($response['_error'])) {
                $status = is_array($response) ? ($response['_http_status'] ?? null) : null;
                throw new \RuntimeException('accrual/by-day HTTP ' . ($status ?? 'null response'));
            }

            $accruals = $response['accruals'] ?? [];
            if (! is_array($accruals) || $accruals === []) {
                break;
            }

            foreach ($accruals as $accrual) {
                if (! is_array($accrual)) {
                    continue;
                }
                $this->aggregateAccrualFees($aggregated, $accrual, $date);
            }

            $prevLastId = $lastId;
            $lastId = (string) ($response['last_id'] ?? '');
            // Ozon может вернуть неизменный непустой курсор на последней странице.
            if ($lastId === '' || $lastId === $prevLastId) {
                break;
            }
        }

        return array_values($aggregated);
    }

    /**
     * Собирает (sku, type_id, amount) из всех контейнеров accrual-строки:
     * item_fees.fees[].fees[], posting.products[].delivery.services[],
     * posting.products[].commission, non_item_fee, container_fees.
     * Форма контейнеров у Ozon плавает, поэтому обход рекурсивный: узел с
     * type_id + accrued.amount — это начисление, sku наследуется от ближайшего
     * родителя, у которого он есть.
     *
     * @param array<string,array<string,mixed>> $aggregated
     */
    private function aggregateAccrualFees(array &$aggregated, array $accrual, string $date): void
    {
        $accrualId = (string) ($accrual['accrual_id'] ?? '');
        if ($accrualId === '') {
            return;
        }

        $unitNumber = $accrual['unit_number'] ?? null;
        $fees = [];
        $this->collectFees($accrual, null, $fees);

        // Начисление без разложения по type_id всё равно фиксируем — суммой строки.
        if ($fees === [] && isset($accrual['total_amount']['amount'])) {
            $fees[] = ['sku' => null, 'type_id' => 0, 'amount' => (float) $accrual['total_amount']['amount']];
        }

        foreach ($fees as $fee) {
            $amount = $fee['amount'];
            if ($amount == 0.0) {
                continue;
            }

            $typeId = $fee['type_id'];
            $sku = $fee['sku'];
            $key = "{$accrualId}:{$typeId}:" . ($sku ?? '-');

            if (isset($aggregated[$key])) {
                $aggregated[$key]['amount'] = round($aggregated[$key]['amount'] + $amount, 2);

                continue;
            }

            $aggregated[$key] = [
                'operation_id' => $key,
                'operation_type' => self::ACCRUAL_TYPE_NAMES[$typeId] ?? "AccrualType_{$typeId}",
                'operation_type_name' => null,
                'operation_date' => $date . ' 00:00:00',
                'posting_number' => $unitNumber,
                'sku' => $sku,
                'offer_id' => null,
                'amount' => round($amount, 2),
                'accruals_for_sale' => null,
                'sale_commission' => null,
                'warehouse_id' => null,
                'warehouse_name' => null,
                'raw' => [
                    'source' => 'accrual_by_day',
                    'accrual_id' => $accrualId,
                    'type_id' => $typeId,
                    'unit_number' => $unitNumber,
                    'accrued_category' => $accrual['accrued_category'] ?? null,
                    'delivery_schema' => data_get($accrual, 'posting.delivery_schema'),
                ],
            ];
        }
    }

    /**
     * @param list<array{sku: ?string, type_id: int, amount: float}> $fees
     */
    private function collectFees(array $node, ?string $sku, array &$fees): void
    {
        if (isset($node['sku']) && ! is_array($node['sku'])) {
            $inherited = trim((string) $node['sku']);
            $sku = $inherited !== '' ? $inherited : $sku;
        }

        if (isset($node['type_id']) && isset($node['accrued']['amount'])) {
            $fees[] = [
                'sku' => $sku,
                'type_id' => (int) $node['type_id'],
                'amount' => (float) $node['accrued']['amount'],
            ];

            return;
        }

        foreach ($node as $key => $child) {
            // total_amount строки — дубль суммы вложенных начислений, не спускаемся.
            if ($key === 'total_amount' || ! is_array($child)) {
                continue;
            }
            $this->collectFees($child, $sku, $fees);
        }
    }
}
