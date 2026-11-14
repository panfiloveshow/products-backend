<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\ManagerFbsLabelJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/** One async create per private document; subsequent clicks only observe its task. */
final class ManagerOzonLabelService
{
    public function prepare(ManagerIdentity $identity, ManagerStore $store, string $id, string $externalId, int $expires): void
    {
        if (! Schema::hasTable('manager_fbs_label_jobs')) throw new ManagerDataException('Получение этикеток Ozon ожидает обновления схемы.');
        ManagerFbsLabelJob::create(['id' => $id, 'workspace_id' => $identity->workspaceId, 'user_id' => $identity->userId,
            'integration_id' => $store->integrationId, 'external_id' => $externalId, 'store_binding' => $store->binding,
            'status' => 'prepared', 'expires_at' => $expires]);
    }

    /** $refresh verifies current personal access, store binding and posting state. */
    public function download(ManagerIdentity $identity, array $ticket, ManagerStore $store, callable $refresh): array
    {
        if (! Schema::hasTable('manager_fbs_label_jobs')) throw new ManagerDataException('Журнал этикеток недоступен.');
        $row = ManagerFbsLabelJob::whereKey($ticket['id'])->where('workspace_id', $identity->workspaceId)->where('user_id', $identity->userId)
            ->where('integration_id', $store->integrationId)->where('external_id', $ticket['external_id'])->where('store_binding', $store->binding)->first();
        if (! $row || $row->expires_at->timestamp !== $ticket['expires_at'] || $row->expires_at->isPast()) throw new ManagerDataException('Подготовка этикетки истекла или изменилась.', 410);
        if ($row->status === 'creating' && $row->updated_at->lt(now()->subMinutes(2))) {
            $row->update(['status' => $row->native_started_at === null ? 'failed' : 'unknown']);
        }
        if (in_array($row->status, ['unknown', 'failed'], true)) return $this->state($row->status);
        if ($row->status === 'prepared') {
            $claimed = ManagerFbsLabelJob::whereKey($row->id)->where('status', 'prepared')->whereNull('native_started_at')->where('expires_at', '>', now())->update(['status' => 'creating', 'updated_at' => now()]);
            if ($claimed !== 1) return $this->state('pending');
            try {
                $store = $refresh(true);
                $started = ManagerFbsLabelJob::whereKey($row->id)->where('workspace_id', $identity->workspaceId)->where('user_id', $identity->userId)
                    ->where('store_binding', $store->binding)->where('status', 'creating')->whereNull('native_started_at')->where('expires_at', '>', now())
                    ->update(['native_started_at' => now(), 'updated_at' => now()]);
                if ($started !== 1) throw new ManagerDataException('Срок подготовки истёк до формирования этикетки.', 410);
            } catch (ManagerDataException $error) {
                ManagerFbsLabelJob::whereKey($row->id)->whereNull('native_started_at')->where('status', 'creating')->update(['status' => 'failed']);
                throw $error;
            }
            try {
                $response = $this->api($store)->post('https://api-seller.ozon.ru/v3/posting/fbs/package-label/create', ['posting_numbers' => [$row->external_id]]);
                if ($response->status() !== 200) {
                    $status = in_array($response->status(), [400, 401, 403, 404, 409, 422], true) ? 'failed' : 'unknown';
                    $row->update(['status' => $status]); return $this->state($status);
                }
                $tasks = $this->tasks($response->json('tasks'));
                $selected = collect($tasks)->firstWhere('task_type', 'big_label') ?? $tasks[0];
                $row->update(['status' => 'pending', 'task_id' => $selected['task_id'], 'task_type' => $selected['task_type'], 'tasks' => $tasks]);
            } catch (\Throwable) {
                $row->update(['status' => 'unknown']); return $this->state('unknown');
            }
            $row->refresh();
        }
        if ($row->status === 'creating' || $row->task_id === null) return $this->state('pending');
        // A lost observation is safe to repeat; the async creation above is not.
        $poll = ManagerFbsLabelJob::whereKey($row->id)->whereIn('status', ['pending', 'ready'])->where('expires_at', '>', now())
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subSeconds(2)))
            ->update(['last_checked_at' => now(), 'updated_at' => now()]);
        if ($poll !== 1) return $this->state('pending');
        try {
            $response = $this->api($store)->post('https://api-seller.ozon.ru/v2/posting/fbs/package-label/get', ['task_id' => $this->nativeId($row->task_id)]);
            $body = $response->json(); $code = is_array($body) ? ($body['status']['code'] ?? null) : null;
            if ($response->status() !== 200 || ! in_array($code, ['pending', 'in_progress', 'completed', 'error'], true)) return $this->state('pending');
            if ($code === 'error') { $row->update(['status' => 'failed']); return $this->state('failed'); }
            if (in_array($code, ['pending', 'in_progress'], true)) return $this->state('pending');
            if (($body['status']['postings_count'] ?? null) !== 1 || ($body['status']['printed_postings_count'] ?? null) !== 1
                || (array_key_exists('unprinted_postings', $body['status']) && $body['status']['unprinted_postings'] !== [])
                || (isset($body['error']) && ! is_array($body['error'])) || ($body['error']['code'] ?? '') !== '' || ! is_string($body['file_url'] ?? null)) {
                $row->update(['status' => 'failed']); return $this->state('failed');
            }
            $url = $body['file_url']; $this->fileUrl($url);
        } catch (\Throwable) { return $this->state('pending'); }
        $row->update(['status' => 'ready']);
        $refresh(false);
        if ($row->expires_at->isPast()) throw new ManagerDataException('Срок скачивания этикетки истёк.', 410);
        try {
            // No seller headers, cookies, redirect, or arbitrary host are forwarded.
            $file = Http::accept('application/pdf')->connectTimeout(3)->timeout(20)->withOptions(['allow_redirects' => false,
                'progress' => static function ($total, $downloaded) { if ($downloaded > ManagerFbsDocumentService::MAX_BYTES) throw new \RuntimeException; }])->get($url);
            $bytes = $file->body();
            if ($file->status() !== 200 || strtolower(trim(explode(';', (string) $file->header('Content-Type'))[0])) !== 'application/pdf'
                || strlen($bytes) > ManagerFbsDocumentService::MAX_BYTES || ! str_starts_with($bytes, '%PDF-')) throw new \RuntimeException;
            return ['bytes' => $bytes, 'mime' => 'application/pdf', 'filename' => 'fbs-label-'.$row->id.'.pdf'];
        } catch (\Throwable) { throw new ManagerDataException('Не удалось получить проверенный PDF этикетки. Повторная проверка использует то же задание Ozon.'); }
    }

    private function tasks(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) < 1 || count($raw) > 2) throw new \RuntimeException;
        $tasks = []; $ids = []; $types = [];
        foreach ($raw as $task) {
            if (! is_array($task) || ! in_array($task['task_type'] ?? null, ['big_label', 'small_label'], true)) throw new \RuntimeException;
            $id = (string) $this->nativeId($task['task_id'] ?? null); $type = $task['task_type'];
            if (isset($ids[$id]) || isset($types[$type])) throw new \RuntimeException;
            $ids[$id] = true; $types[$type] = true; $tasks[] = ['task_id' => $id, 'task_type' => $type];
        }
        return $tasks;
    }

    private function nativeId(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) $value)) throw new \RuntimeException;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new \RuntimeException;
        return $id;
    }

    private function fileUrl(string $url): void
    {
        $u = parse_url($url);
        if (! is_array($u) || ($u['scheme'] ?? null) !== 'https' || ! in_array(strtolower($u['host'] ?? ''), ['cdn1.ozone.ru', 'cdn2.ozone.ru'], true)
            || isset($u['user']) || isset($u['pass']) || isset($u['fragment']) || (isset($u['port']) && $u['port'] !== 443)
            || ! isset($u['path']) || ! str_starts_with($u['path'], '/') || strlen($url) > 8000 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) throw new \RuntimeException;
    }

    private function api(ManagerStore $store): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()->withHeaders(['Client-Id' => $store->clientId, 'Api-Key' => $store->apiKey])->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false]);
    }

    private function state(string $status): array
    {
        return ['document_status' => $status, 'can_retry' => $status === 'pending', 'retry_after_seconds' => $status === 'pending' ? 2 : 0,
            'http_status' => $status === 'pending' ? 202 : 409,
            'message' => match ($status) { 'pending' => 'Готовность этикетки пока не подтверждена. Проверка продолжит то же задание Ozon.', 'unknown' => 'Ответ на создание задания не получен. Повторное создание заблокировано; проверьте этикетку в кабинете Ozon.', default => 'Ozon не подтвердил формирование полной этикетки. Проверьте отправление в кабинете.' }];
    }
}
