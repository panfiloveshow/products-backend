<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\Ozon\OzonCredentialHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Пробник ключей интеграций: помечает credential_health, чтобы фоновые синки
 * (scope syncable) не крутили мёртвые ключи, а UI показывал «обновите токен».
 *
 * WB: GET /ping (200 → healthy, 401 → invalid, прочее — не трогаем: сетевые
 * ошибки и лимиты не равны отзыву ключа). Ozon: OzonCredentialHealthService
 * (сам сохраняет health). Нерасшифровываемые/пустые креды → missing.
 *
 * Обновление кредов через UI сбрасывает health в 'unknown', поэтому пробник
 * ходит по ВСЕМ активным интеграциям, включая ранее помеченные — ключ,
 * заменённый напрямую в БД, тоже оживёт при следующем прогоне.
 */
class ProbeIntegrationCredentialsCommand extends Command
{
    protected $signature = 'integrations:probe-credentials {--integration= : Только одна интеграция}';

    protected $description = 'Проверить ключи WB/Ozon интеграций и обновить credential_health';

    public function handle(OzonCredentialHealthService $ozonHealth): int
    {
        $query = Integration::query()
            ->where('is_active', true)
            ->whereIn('marketplace', ['wildberries', 'ozon']);
        if ($id = $this->option('integration')) {
            $query->whereKey((int) $id);
        }

        foreach ($query->get() as $integration) {
            $health = $this->probe($integration, $ozonHealth);
            if ($health === null) {
                continue; // Ozon-сервис сохранил сам, либо статус решили не менять.
            }
            if ($health !== $integration->credential_health) {
                Log::info('Integration credential health changed', [
                    'integration_id' => $integration->id,
                    'from' => $integration->credential_health,
                    'to' => $health,
                ]);
            }
            $integration->forceFill([
                'credential_health' => $health,
                'credential_last_checked_at' => now(),
            ])->save();
            $this->line("int {$integration->id} ({$integration->name}): {$health}");
        }

        return self::SUCCESS;
    }

    private function probe(Integration $integration, OzonCredentialHealthService $ozonHealth): ?string
    {
        try {
            // Тот же путь, что у синков: локальные креды + Sellico-фолбэк.
            $credentials = $integration->resolveCredentials();
        } catch (\Throwable) {
            return 'missing'; // креды не расшифровываются (сменился APP_KEY / битая запись)
        }

        if ($integration->marketplace === 'ozon') {
            $result = $ozonHealth->check($integration); // сохраняет health сам
            $this->line("int {$integration->id} ({$integration->name}): " . ($result['health'] ?? '?'));

            return null;
        }

        $token = trim((string) ($credentials['api_key'] ?? ''));
        if ($token === '') {
            return 'missing';
        }

        try {
            $status = Http::withHeaders(['Authorization' => $token])
                ->timeout(15)
                ->get('https://common-api.wildberries.ru/ping')
                ->status();
        } catch (\Throwable) {
            return null; // сеть/таймаут — не повод хоронить ключ
        }

        return match (true) {
            $status === 200 => 'healthy',
            $status === 401 => 'invalid',
            default => null, // 429/5xx — статус не меняем
        };
    }
}
