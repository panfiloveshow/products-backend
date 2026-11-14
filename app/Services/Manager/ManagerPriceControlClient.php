<?php

declare(strict_types=1);

namespace App\Services\Manager;

use Illuminate\Support\Facades\Http;

/** Reads the executor version while the original financial proposal is made. */
final class ManagerPriceControlClient
{
    public function capture(ManagerIdentity $identity, #[\SensitiveParameter] string $token, int $integrationId, array $snapshot): array
    {
        $market = $snapshot['marketplace'] ?? null;
        $resource = $snapshot['native_change']['resource_id'] ?? null;
        $base = rtrim((string) config('services.manager.price_executor_url', ''), '/');
        if ($base === '' || $token === '' || preg_match('/\s/', $token) || ! in_array($market, ['wildberries', 'ozon'], true)
            || ! is_string($resource) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $resource)
            || strlen($resource) === 19 && strcmp($resource, '9223372036854775807') > 0) {
            throw new ManagerDataException('Не настроена проверка ограничения исполнителя цены.', 503);
        }
        $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(20)->withOptions(['allow_redirects' => false])
            ->withHeaders(['X-Sellico-Workspace' => (string) $identity->workspaceId])
            ->get($base.'/manager/prices/execution-controls/context', ['integration_id' => (string) $integrationId, 'resource_id' => $resource]);
        $raw = $response->json('data');
        if (! $response->successful()) {
            throw new ManagerDataException('Не удалось проверить текущее ограничение цены.', in_array($response->status(), [401, 403, 409, 422], true) ? $response->status() : 503);
        }
        $proof = is_array($raw) ? ($raw['account_proof'] ?? null) : null;
        $state = is_array($raw) ? ($raw['control'] ?? null) : null;
        $expectedMarket = $market === 'wildberries' ? 'wb' : 'ozon';
        if (! is_array($raw) || ($raw['workspace_id'] ?? null) !== (string) $identity->workspaceId
            || ($raw['integration_id'] ?? null) !== (string) $integrationId || ($raw['marketplace'] ?? null) !== $expectedMarket
            || ($raw['scope'] ?? null) !== 'configured_price_account' || ! is_array($proof) || ($proof['resource_id'] ?? null) !== $resource
            || ($proof['source'] ?? null) !== ($market === 'wildberries' ? 'WB Prices API' : 'Ozon Seller API') || ! $this->timestamp($proof['observed_at'] ?? null)
            || ! is_array($state) || ! in_array($state['mode'] ?? null, ['legacy', 'stopped', 'manual_only'], true)
            || ! is_int($state['version'] ?? null) || $state['version'] < 0 || $state['version'] > 9007199254740991
            || ($state['mode'] === 'legacy' ? $state['version'] !== 0 || ! array_key_exists('changed_at', $state) || $state['changed_at'] !== null : $state['version'] < 1 || ! $this->timestamp($state['changed_at'] ?? null))
            || ! is_string($raw['context_fingerprint'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $raw['context_fingerprint'])) {
            throw new ManagerDataException('Исполнитель не подтвердил магазин, товар или версию ограничения цены.', 503);
        }
        // Unresolved legacy admissions hold every new Native price command of the
        // account. Refuse before a financial proposal asks for an approval that the
        // executor cannot dispatch. An executor that does not report the count yet
        // keeps the previous behaviour: its own fence still blocks the dispatch.
        $held = $raw['legacy_held_count'] ?? null;
        if (array_key_exists('legacy_held_count', $raw) && (! is_int($held) || $held < 0)) {
            throw new ManagerDataException('Исполнитель не подтвердил удержания прежних ценовых разрешений.', 503);
        }
        if ($state['mode'] === 'stopped') {
            throw new ManagerDataException('Отправка цен через этот исполнитель остановлена. Для новых личных действий нужно отдельное открытие.', 409);
        }
        if (is_int($held) && $held > 0) {
            throw new ManagerDataException('По этому кабинету есть неразобранные прежние ценовые разрешения. Новые команды цены удерживаются, пока каждая запись не разобрана лично в журнале прежних разрешений.', 409, 'legacy_price_admissions_unresolved');
        }

        return ['version' => 'price_execution_context_v1', 'scope' => 'configured_price_account',
            'integration_id' => (string) $integrationId, 'marketplace' => $market, 'resource_id' => $resource,
            'control' => ['mode' => $state['mode'], 'version' => $state['version'], 'changed_at' => $state['changed_at']],
            'context_fingerprint' => $raw['context_fingerprint'], 'source' => $proof['source']];
    }

    private function timestamp(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value)) {
            return false;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d') === substr($value, 0, 10);
        } catch (\Throwable) {
            return false;
        }
    }
}
