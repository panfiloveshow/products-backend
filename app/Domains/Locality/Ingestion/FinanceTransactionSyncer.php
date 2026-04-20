<?php

namespace App\Domains\Locality\Ingestion;

use App\Domains\Ozon\Api\OzonClient;
use App\Models\Integration;
use App\Models\OzonFinanceTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Загружает финансовые транзакции Ozon в таблицу ozon_finance_transactions.
 * Используется как источник "actual" для Reconciliation-слоя.
 *
 * Endpoint: POST /v3/finance/transaction/list (пагинация по page, page_size до 1000)
 */
class FinanceTransactionSyncer
{
    private const ENDPOINT_LIST = '/v3/finance/transaction/list';
    private const ENDPOINT_TOTALS = '/v3/finance/transaction/totals';
    private const PAGE_SIZE = 1000;

    public function syncForIntegration(Integration $integration, Carbon $from, Carbon $to): SyncResult
    {
        $client = OzonClient::fromIntegration($integration);
        $integrationId = (int) $integration->id;

        $page = 1;
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        Log::channel('locality')->info('FinanceTransactionSyncer started', [
            'integration_id' => $integrationId,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
        ]);

        while (true) {
            $payload = [
                'filter' => [
                    'date' => [
                        'from' => $from->toIso8601String(),
                        'to' => $to->toIso8601String(),
                    ],
                    'transaction_type' => 'all',
                ],
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
            ];

            $response = $client->post(self::ENDPOINT_LIST, $payload);
            if (! is_array($response) || isset($response['_error'])) {
                Log::channel('locality')->error('FinanceTransactionSyncer API error', [
                    'integration_id' => $integrationId,
                    'page' => $page,
                    'response' => $response,
                ]);
                break;
            }

            $operations = $response['result']['operations'] ?? [];
            if (empty($operations)) {
                break;
            }

            DB::transaction(function () use ($operations, $integrationId, &$inserted, &$updated, &$skipped) {
                foreach ($operations as $op) {
                    $operationId = $this->extractOperationId($op);
                    if ($operationId === null) {
                        $skipped++;
                        continue;
                    }

                    $posting = $op['posting'] ?? [];
                    $items = $op['items'] ?? [];
                    $firstItem = $items[0] ?? [];

                    $attrs = [
                        'integration_id' => $integrationId,
                        'operation_id' => (string) $operationId,
                    ];
                    $values = [
                        'operation_type' => $op['operation_type'] ?? null,
                        'operation_type_name' => $op['operation_type_name'] ?? null,
                        'operation_date' => $this->parseDate($op['operation_date'] ?? null),
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

                    $existing = OzonFinanceTransaction::query()
                        ->where($attrs)
                        ->first();

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

            $totalPages = (int) ($response['result']['page_count'] ?? 0);
            if ($totalPages > 0 && $page >= $totalPages) {
                break;
            }

            if (count($operations) < self::PAGE_SIZE) {
                break;
            }

            $page++;
        }

        Log::channel('locality')->info('FinanceTransactionSyncer completed', [
            'integration_id' => $integrationId,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return new SyncResult($inserted, $updated, $skipped);
    }

    /**
     * Получить сверочные суммы по operation_type для периода.
     *
     * @return array<string,float>
     */
    public function fetchTotals(Integration $integration, Carbon $from, Carbon $to): array
    {
        $client = OzonClient::fromIntegration($integration);
        $payload = [
            'date' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'transaction_type' => 'all',
        ];

        $response = $client->post(self::ENDPOINT_TOTALS, $payload);
        if (! is_array($response) || isset($response['_error'])) {
            return [];
        }

        $result = $response['result'] ?? $response;
        $totals = [];
        foreach ($result as $key => $value) {
            if (is_numeric($value)) {
                $totals[(string) $key] = (float) $value;
            }
        }

        return $totals;
    }

    private function extractOperationId(array $op): ?string
    {
        $id = $op['operation_id'] ?? null;
        if ($id === null || $id === '') {
            return null;
        }

        return (string) $id;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
