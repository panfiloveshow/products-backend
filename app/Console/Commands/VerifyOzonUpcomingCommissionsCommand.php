<?php

namespace App\Console\Commands;

use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use App\Models\Integration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сверка ставки «с 28.08» (commission_rate_from_2026_08_28) в кэше ЮЭ с
 * официальной таблицей и точечная починка расхождений.
 *
 * Зачем: поле пишется несколькими путями (синк, пересчёт кэша), и старые
 * снапшоты уже дважды затаскивали в кэш устаревшие ставки (клиенты видели
 * 55 вместо 52). Команда — компенсатор: каждый час возвращает кэш к таблице
 * и логирует, какие строки и когда были перезаписаны — по логу видно виновника.
 */
class VerifyOzonUpcomingCommissionsCommand extends Command
{
    protected $signature = 'ozon:verify-upcoming-commissions {--integration=} {--dry-run}';

    protected $description = 'Сверить и починить ставку с 28.08 в кэше ЮЭ по официальной таблице Ozon';

    public function handle(OzonPricingMatrix $pricing): int
    {
        $query = Integration::syncable()->where('marketplace', 'ozon');
        if ($id = $this->option('integration')) {
            $query->whereKey((int) $id);
        }

        $totalFixed = 0;
        foreach ($query->pluck('id') as $integrationId) {
            $fixed = 0;
            DB::table('unit_economics_cache as c')
                ->join('products as p', function ($join) use ($integrationId) {
                    $join->on('p.sku', '=', 'c.sku')->where('p.integration_id', $integrationId);
                })
                ->where('c.integration_id', $integrationId)
                ->whereNotNull('c.marketplace_data')
                ->select('c.id', 'c.sku', 'c.fulfillment_type', 'c.price', 'c.marketplace_data', 'c.updated_at', 'p.category')
                ->orderBy('c.id')
                ->chunk(500, function ($rows) use ($pricing, $integrationId, &$fixed) {
                    foreach ($rows as $row) {
                        $expected = $pricing->resolveCommissionFromOfficialTable(
                            $row->fulfillment_type,
                            $row->category,
                            (float) $row->price
                        );
                        if ($expected === null) {
                            continue; // категория не найдена в таблице — чинить нечем
                        }
                        $md = json_decode($row->marketplace_data, true);
                        if (! is_array($md)) {
                            continue;
                        }
                        $current = $md['commission_rate_from_2026_08_28'] ?? null;
                        if ($current !== null && abs((float) $current - $expected) < 0.005) {
                            continue;
                        }
                        $fixed++;
                        Log::warning('Ozon upcoming commission drift in UE cache', [
                            'integration_id' => $integrationId,
                            'sku' => $row->sku,
                            'scheme' => $row->fulfillment_type,
                            'cached' => $current,
                            'expected' => $expected,
                            'row_updated_at' => (string) $row->updated_at,
                        ]);
                        if (! $this->option('dry-run')) {
                            $md['commission_rate_from_2026_08_28'] = round($expected, 2);
                            DB::table('unit_economics_cache')->where('id', $row->id)
                                ->update(['marketplace_data' => json_encode($md, JSON_UNESCAPED_UNICODE)]);
                        }
                    }
                });
            if ($fixed > 0) {
                $this->line("int {$integrationId}: исправлено {$fixed}");
                $totalFixed += $fixed;
            }
        }
        $this->line("Итого исправлено: {$totalFixed}");

        return self::SUCCESS;
    }
}
