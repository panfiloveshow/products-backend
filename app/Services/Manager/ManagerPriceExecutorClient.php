<?php

declare(strict_types=1);

namespace App\Services\Manager;

use Illuminate\Support\Facades\Http;

/** One send; all later calls read the durable operation. No automatic retries. */
final class ManagerPriceExecutorClient
{
    public function send(ManagerIdentity $identity, string $token, array $command): array { return $this->request($identity, $token, $command['operation_id'], $command); }
    public function read(ManagerIdentity $identity, string $token, string $id): array { return $this->request($identity, $token, $id, null); }

    private function request(ManagerIdentity $identity, #[\SensitiveParameter] string $token, string $id, ?array $command): array
    {
        $base = rtrim((string) config('services.manager.price_executor_url', ''), '/');
        if ($base === '' || $token === '' || preg_match('/\s/', $token)) throw new ManagerDataException('Исполнитель цены не настроен.', 503);
        $http = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout($command === null ? 15 : 60)->withOptions(['allow_redirects' => false])
            ->withHeaders(['X-Sellico-Workspace' => (string) $identity->workspaceId]);
        $response = $command === null ? $http->get($base.'/manager/prices/operations/'.$id) : $http->post($base.'/manager/prices/operations', $command);
        $receipt = $response->json('data');
        if (! $response->successful() || ! is_array($receipt) || ($receipt['version'] ?? null) !== 'manager_price_receipt_v1' || ($receipt['operation_id'] ?? null) !== $id
            || ! \Illuminate\Support\Str::isUuid($receipt['native_change_id'] ?? '') || ! preg_match('/^[a-f0-9]{64}$/D', $receipt['proposal_fingerprint'] ?? '')
            || ! in_array($receipt['marketplace'] ?? null, ['wb', 'ozon'], true)
            || ! isset(['pending' => 'executing', 'submitting' => 'executing', 'uploaded' => 'verifying', 'verifying' => 'verifying', 'applied' => 'succeeded', 'failed' => 'failed', 'submit_unknown' => 'unknown'][$receipt['native_state'] ?? ''])
            || ['pending' => 'executing', 'submitting' => 'executing', 'uploaded' => 'verifying', 'verifying' => 'verifying', 'applied' => 'succeeded', 'failed' => 'failed', 'submit_unknown' => 'unknown'][$receipt['native_state']] !== ($receipt['status'] ?? null)) {
            throw new ManagerDataException('Не получена проверенная квитанция цены; отправку повторять нельзя.', 503);
        }
        return array_intersect_key($receipt, array_flip(['version', 'operation_id', 'native_change_id', 'marketplace', 'proposal_fingerprint', 'status', 'native_state']));
    }
}
