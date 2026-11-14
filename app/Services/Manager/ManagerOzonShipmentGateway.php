<?php

declare(strict_types=1);

namespace App\Services\Manager;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Only existing Ozon FBS carriages. No creation, approval, signing or physical handoff. */
final class ManagerOzonShipmentGateway
{
    private const DOCUMENTS = ['get_shipping_list' => 'shipping_list', 'get_waybill' => 'waybill', 'get_act_of_acceptance' => 'act_of_acceptance'];

    public function readList(ManagerStore $store, string $from, string $to): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if (! $start || ! $end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
            || $end < $start || $end->getTimestamp() - $start->getTimestamp() > 30 * 86400) {
            throw new ManagerDataException('Укажите период создания отгрузок от 1 до 31 дня.', 422);
        }
        $data = $this->json($store, '/v2/posting/fbs/act/list', ['filter' => ['date_from' => $from, 'date_to' => $to, 'integration_type' => 'ozon'], 'limit' => 50]);
        $rows = $data['result'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 50) $this->invalid();
        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['integration_type'] ?? null) !== 'ozon') $this->invalid();
            $id = $this->id($row['id'] ?? null);
            if (isset($items[$id])) $this->invalid();
            $items[$id] = ['carriage_id' => $id, 'delivery_method_id' => $this->id($row['delivery_method_id'] ?? null),
                'vendor_state' => $this->state($row['status'] ?? null), 'departure_date' => $this->date($row['departure_date'] ?? null),
                'is_partial' => is_bool($row['is_partial'] ?? null) ? $row['is_partial'] : null,
                'has_postings_for_next_carriage' => is_bool($row['has_postings_for_next_carriage'] ?? null) ? $row['has_postings_for_next_carriage'] : null];
        }
        // This native method has a limit but no documented cursor/total/has_next.
        return ['items' => array_values($items), 'count_returned' => count($items), 'limit' => 50, 'limit_reached' => count($items) === 50,
            'completeness' => 'not_proven', 'date_from' => $from, 'date_to' => $to, 'date_basis' => 'carriage_created_at',
            'physical_status' => null, 'observed_at' => now()->utc()->toRfc3339String()];
    }

    public function details(ManagerStore $store, string $carriageId): array
    {
        $id = $this->id($carriageId);
        $data = $this->json($store, '/v1/carriage/get', ['carriage_id' => (int) $id]);
        if ($this->id($data['carriage_id'] ?? null) !== $id || ($data['integration_type'] ?? null) !== 'ozon'
            || ! is_array($data['available_actions'] ?? null) || ! array_is_list($data['available_actions'])
            || count($data['available_actions']) > 50 || ! is_bool($data['is_partial'] ?? null)
            || ! is_bool($data['has_postings_for_next_carriage'] ?? null)) $this->invalid();
        foreach ($data['available_actions'] as $action) if (! is_string($action) || strlen($action) > 100) $this->invalid();
        $documents = [];
        foreach (self::DOCUMENTS as $action => $document) if (in_array($action, $data['available_actions'], true)) $documents[] = $document;
        $state = $this->state($data['status'] ?? null);
        if ($state === 'cancelled' || ! $documents) throw new ManagerDataException('Ozon не подтвердил доступные документы этой отгрузки. Проверьте её в кабинете.', 422);
        if (in_array('waybill', $documents, true) && ($data['is_waybill_enabled'] ?? null) !== true) $this->invalid();
        if (! is_string($data['act_type'] ?? null) || strlen($data['act_type']) > 100) $this->invalid();
        $partial = $data['partial_num'] ?? null;
        if (! is_int($partial) || $partial < 0) $this->invalid();

        return ['carriage_id' => $id, 'delivery_method_id' => $this->id($data['delivery_method_id'] ?? null),
            'departure_date' => $this->date($data['departure_date'] ?? null), 'vendor_state' => $state,
            'available_documents' => $documents, 'is_partial' => $data['is_partial'], 'partial_num' => $partial,
            'has_postings_for_next_carriage' => $data['has_postings_for_next_carriage'], 'act_type' => $data['act_type']];
    }

    public function readiness(ManagerStore $store, array $expected): string
    {
        $data = $this->json($store, '/v2/posting/fbs/act/check-status', ['id' => (int) $this->id($expected['carriage_id'])]);
        $r = $data['result'] ?? null;
        if (! is_array($r) || ! is_string($r['status'] ?? null)) $this->invalid();
        $status = match ($r['status']) {
            'ready' => 'ready', 'in_process', "The next postings aren't ready" => 'pending', 'error', 'cancelled' => 'failed',
            default => throw new ManagerDataException('Ozon не подтвердил готовность документов.'),
        };
        // Pending/error responses need not contain a finished document's metadata. Never release a PDF for them.
        if ($status !== 'ready') return $status;
        // A changed partial carriage must not be presented under the previous preparation.
        foreach (['is_partial', 'partial_num', 'has_postings_for_next_carriage', 'act_type'] as $field) {
            if (($r[$field] ?? null) !== $expected[$field]) throw new ManagerDataException('Состав документов отгрузки изменился. Подготовьте карточку заново.', 410);
        }
        return 'ready';
    }

    public function pdf(ManagerStore $store, string $carriageId): array
    {
        $response = $this->post($store, '/v2/posting/fbs/act/get-pdf', ['id' => (int) $this->id($carriageId)], ManagerFbsDocumentService::MAX_BYTES, 'application/pdf');
        $bytes = $response->body();
        if ($response->status() !== 200 || strtolower(trim(explode(';', $response->header('Content-Type'))[0])) !== 'application/pdf'
            || ! str_starts_with($bytes, '%PDF-') || strlen($bytes) > ManagerFbsDocumentService::MAX_BYTES) {
            throw new ManagerDataException('Ozon не вернул проверенный PDF. Документ не получен.');
        }
        return ['bytes' => $bytes, 'mime' => 'application/pdf'];
    }

    private function json(ManagerStore $store, string $path, array $body): array
    {
        $response = $this->post($store, $path, $body, 524288, 'application/json');
        try { $data = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (\Throwable) { $this->invalid(); }
        if (! $response->successful() || ! is_array($data) || array_is_list($data)) $this->invalid();
        return $data;
    }

    private function post(ManagerStore $store, string $path, array $body, int $max, string $accept): Response
    {
        if ($store->marketplace !== 'ozon' || ! $store->clientId) throw new ManagerDataException('Документы отгрузки подключены для Ozon FBS.', 422);
        try {
            $response = Http::withHeaders(['Api-Key' => $store->apiKey, 'Client-Id' => $store->clientId, 'Accept' => $accept])
                ->connectTimeout(3)->timeout(20)->withOptions(['allow_redirects' => false, 'progress' => static function ($total, $downloaded) use ($max) {
                    if ($total > $max || $downloaded > $max) throw new \RuntimeException('Document response too large');
                }])->post('https://api-seller.ozon.ru'.$path, $body);
            if (strlen($response->body()) > $max) throw new \RuntimeException;
            return $response;
        } catch (\Throwable) {
            throw new ManagerDataException('Документы отгрузки временно недоступны. Данные не получены.');
        }
    }

    private function id(mixed $value): string
    {
        $s = is_int($value) || is_string($value) ? (string) $value : '';
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $s) || (strlen($s) === 19 && strcmp($s, (string) PHP_INT_MAX) > 0)) $this->invalid();
        return $s;
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 40 || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T[^\s]*)?$/D', $value) || strtotime($value) === false) $this->invalid();
        $day = substr($value, 0, 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (! $parsed || $parsed->format('Y-m-d') !== $day) $this->invalid();
        return $value;
    }

    private function state(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[a-z_]{1,50}$/D', $value)) $this->invalid();
        return $value;
    }

    private function invalid(): never
    {
        throw new ManagerDataException('Ozon не подтвердил состав или формат документов отгрузки.');
    }
}
