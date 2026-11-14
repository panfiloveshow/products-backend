<?php

declare(strict_types=1);

namespace App\Services\Manager;

use Illuminate\Support\Facades\Http;

/** Only whole, explicitly cancellable FBS tasks. Native texts are data, not instructions. */
final class ManagerFbsCancellationGateway
{
    public function __construct(private ManagerFbsGateway $orders) {}

    public function context(ManagerStore $store, string $externalId, ?array $window = null): array
    {
        if ($store->marketplace === 'wildberries') {
            $status = $this->wbStatus($store, $externalId);
            if (! is_bool($status['is_cancellable'])) throw new ManagerDataException('WB не подтвердил isCancellable. Отмена не разрешена.');
            $window = $this->window($window);
            $before = $this->orders->wbOrderDetails($store, $externalId, $window);
            $before['vendor_state'] = $status['vendor_state'];
            $before['state_basis'] = 'orders_status_endpoint';
            $before['wb_state'] = $status['wb_state'];
            $allowed = $status['is_cancellable'] && $status['vendor_state'] !== 'cancel';
            $reasons = [];
        } elseif ($store->marketplace === 'ozon') {
            if ($window !== null) throw new ManagerDataException('Период поиска нужен только WB.', 422);
            $source = $this->orders->ozonPosting($store, $externalId);
            $before = $source['posting'];
            if (! is_array($before['available_actions'])) throw new ManagerDataException('Ozon не подтвердил доступные действия отправления.');
            $allowed = in_array('cancel', $before['available_actions'], true) && $before['vendor_state'] !== 'cancelled';
            $reasons = $allowed ? $this->ozonReasons($store, $externalId) : [];
            $allowed = $allowed && $reasons !== [];
        } else {
            throw new ManagerDataException('Отмена поддерживает только FBS WB/Ozon.', 422);
        }
        $facts = ['before' => $before, 'lookup_window' => $window, 'is_cancellable' => $allowed, 'reasons' => $reasons];
        $facts['context_fingerprint'] = hash('sha256', $this->canonicalJson(['manager_fbs_cancel_context_v1', $store->workspaceId, $store->integrationId, $store->binding, $facts]));
        return $facts;
    }

    /** Match the model's array cast; object key order is not a change of consent. */
    public function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalValue($value), JSON_THROW_ON_ERROR);
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (is_object($value)) $value = get_object_vars($value);
        if (! is_array($value)) return $value;
        if (! array_is_list($value)) ksort($value, SORT_STRING);
        return array_map(fn ($item) => $this->canonicalValue($item), $value);
    }

    public function plan(ManagerStore $store, string $externalId, array $input): array
    {
        if (array_diff(array_keys($input), ['context_fingerprint', 'reason_id', 'comment', 'lookup_window'])
            || ! is_string($input['context_fingerprint'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $input['context_fingerprint'])
            || ! array_key_exists('reason_id', $input) || ! array_key_exists('lookup_window', $input)
            || ! is_string($input['comment'] ?? null) || trim($input['comment']) === '' || mb_strlen($input['comment']) > 500
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input['comment'])) throw new ManagerDataException('Нужны точный снимок, явная причина и комментарий сотрудника.', 422);
        $context = $this->context($store, $externalId, $input['lookup_window']);
        if (! hash_equals($context['context_fingerprint'], $input['context_fingerprint'])) throw new ManagerDataException('Состояние, состав или причины отмены изменились. Прочитайте контекст заново.', 422);
        if (! $context['is_cancellable']) throw new ManagerDataException('Площадка не разрешила отмену этого отправления.', 422);
        $reason = null;
        if ($store->marketplace === 'ozon') {
            if (! is_string($input['reason_id']) || ! preg_match('/^[1-9][0-9]{0,9}$/D', $input['reason_id'])) throw new ManagerDataException('Выберите ID причины из текущего ответа Ozon.', 422);
            foreach ($context['reasons'] as $candidate) if ($candidate['id'] === $input['reason_id']) $reason = $candidate;
            if ($reason === null) throw new ManagerDataException('Эта причина не разрешена Ozon для выбранного отправления.', 422);
        } elseif ($input['reason_id'] !== null) {
            throw new ManagerDataException('WB не принимает ID причины в этом методе.', 422);
        }
        $target = $store->marketplace === 'ozon' ? 'cancelled' : 'cancel';
        return ['operation_type' => 'fbs_cancel', 'protocol' => 'manager_fbs_cancel_v1', 'external_id' => $externalId, 'supply_id' => null,
            'before' => $context['before'], 'supply_before' => null,
            'cancellation' => ['context_fingerprint' => $context['context_fingerprint'], 'reason_id' => $input['reason_id'], 'comment' => $input['comment'], 'lookup_window' => $context['lookup_window']],
            'cancellation_details' => ['before_state' => $context['before']['vendor_state'], 'target_state' => $target, 'reason' => $reason,
                'comment' => $input['comment'], 'comment_sent_to_marketplace' => $store->marketplace === 'ozon', 'lookup_window' => $context['lookup_window'],
                'allowed_by_platform' => true, 'financial_effects_verified' => false, 'refund_status' => null],
            'diff' => [['field' => 'vendor_state', 'label' => 'Полная отмена отправления', 'before' => $context['before']['vendor_state'], 'after' => $target]],
            'required_confirmations' => [
                ['key' => 'cancel_target_checked', 'label' => 'Проверил магазин, отправление, все товары и выбранную причину отмены.'],
                ['key' => 'cancel_impact_checked', 'label' => 'Разрешаю полную отмену. Финансовые последствия и возврат денег не подтверждены этим API.'],
                ['key' => 'not_handed_over_checked', 'label' => 'Лично проверил, что отправление ещё не передано перевозчику или площадке.'],
            ]];
    }

    public function observed(ManagerStore $store, array $plan): array
    {
        if ($store->marketplace === 'wildberries') {
            $status = $this->wbStatus($store, $plan['external_id']);
            return ['vendor_state' => $status['vendor_state'], 'matches' => $status['vendor_state'] === 'cancel', 'reason_verified' => null];
        }
        $source = $this->orders->ozonPosting($store, $plan['external_id'], true);
        $posting = $source['posting'];
        $matches = $posting['vendor_state'] === 'cancelled';
        $reason = $source['cancellation_reason_id'] ?? null;
        return ['vendor_state' => $posting['vendor_state'], 'matches' => $matches,
            'reason_verified' => $reason === null ? null : $reason === $plan['cancellation']['reason_id']];
    }

    private function wbStatus(ManagerStore $store, string $externalId): array
    {
        $id = filter_var($externalId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || (string) $id !== $externalId) throw new ManagerDataException('Нужен точный целочисленный ID WB.', 422);
        try {
            $response = Http::acceptJson()->withHeaders(['Authorization' => $store->apiKey])->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false])
                ->post('https://marketplace-api.wildberries.ru/api/v3/orders/status', ['orders' => [$id]]);
            $rows = $response->json('orders');
            if (! $response->successful() || ! is_array($rows) || count($rows) !== 1 || (string) ($rows[0]['id'] ?? '') !== $externalId
                || ! is_string($rows[0]['supplierStatus'] ?? null) || $rows[0]['supplierStatus'] === '' || strlen($rows[0]['supplierStatus']) > 40
                || ! is_string($rows[0]['wbStatus'] ?? null) || $rows[0]['wbStatus'] === '' || strlen($rows[0]['wbStatus']) > 80) throw new \RuntimeException;
            return ['vendor_state' => $rows[0]['supplierStatus'], 'wb_state' => $rows[0]['wbStatus'], 'is_cancellable' => is_bool($rows[0]['isCancellable'] ?? null) ? $rows[0]['isCancellable'] : null];
        } catch (\Throwable) { throw new ManagerDataException('WB не подтвердил статус выбранного задания.'); }
    }

    private function ozonReasons(ManagerStore $store, string $externalId): array
    {
        try {
            $client = Http::acceptJson()->withHeaders(['Client-Id' => $store->clientId, 'Api-Key' => $store->apiKey])->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false]);
            $specific = $client->post('https://api-seller.ozon.ru/v1/posting/fbs/cancel-reason', ['related_posting_numbers' => [$externalId]]);
            // The live v1 contract uses a capital Result and groups reasons by posting.
            $postings = $specific->json('Result');
            if (! $specific->successful() || ! is_array($postings) || ! array_is_list($postings) || count($postings) !== 1
                || ($postings[0]['posting_number'] ?? null) !== $externalId) throw new \RuntimeException;
            $rows = $postings[0]['reasons'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 500) throw new \RuntimeException;
            $ids = [];
            foreach ($rows as $row) {
                $reason = $this->reason($row);
                if (isset($ids[$reason['id']])) throw new \RuntimeException;
                $ids[$reason['id']] = $reason;
            }
            $global = $client->withBody('', 'application/json')->post('https://api-seller.ozon.ru/v2/posting/fbs/cancel-reason/list');
            $rows = $global->json('result');
            if (! $global->successful() || ! is_array($rows) || ! array_is_list($rows) || count($rows) > 500) throw new \RuntimeException;
            $result = []; $seen = [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_bool($row['is_available_for_cancellation'] ?? null)) throw new \RuntimeException;
                $id = $this->reason($row)['id'];
                if (isset($seen[$id])) throw new \RuntimeException;
                $seen[$id] = true;
                if (isset($ids[$id]) && $row['is_available_for_cancellation']) $result[] = $ids[$id];
            }
            usort($result, fn ($a, $b) => strcmp($a['id'], $b['id']));
            return $result;
        } catch (\Throwable) { throw new ManagerDataException('Ozon не подтвердил доступные причины отмены этого отправления.'); }
    }

    private function reason(mixed $row): array
    {
        if (! is_array($row) || ! is_string($row['title'] ?? null) || trim($row['title']) === '' || mb_strlen($row['title']) > 1000
            || ! is_string($row['type_id'] ?? null) || ! preg_match('/^[a-zA-Z0-9_]{1,80}$/D', $row['type_id'])) throw new \RuntimeException;
        return ['id' => $this->reasonId($row['id'] ?? null), 'title' => $row['title'], 'type_id' => $row['type_id']];
    }

    private function reasonId(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value)) throw new \RuntimeException;
        return (string) $value;
    }

    private function window(?array $window): array
    {
        $window ??= ['date_from' => now()->utc()->subDays(29)->toDateString(), 'date_to' => now()->utc()->toDateString()];
        if (count($window) !== 2 || array_diff(['date_from', 'date_to'], array_keys($window))) throw new ManagerDataException('Нужны две даты поиска WB.', 422);
        foreach ($window as $date) if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || date('Y-m-d', strtotime($date)) !== $date) throw new ManagerDataException('Некорректная дата поиска WB.', 422);
        $from = \Carbon\CarbonImmutable::parse($window['date_from'], 'UTC'); $to = \Carbon\CarbonImmutable::parse($window['date_to'], 'UTC');
        if ($from->gt($to) || $from->diffInDays($to) > 29 || $to->toDateString() > now()->utc()->toDateString() || $from->lt(now()->utc()->subMonthsNoOverflow(3)->startOfDay())) throw new ManagerDataException('WB допускает до 30 календарных дней в пределах трёх месяцев.', 422);
        return ['date_from' => $window['date_from'], 'date_to' => $window['date_to']];
    }
}
