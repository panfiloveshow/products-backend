<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\UnitEconomics\UnitEconomicsPriceIntegrityService;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UnitEconomicsPriceIntegrityCheck extends Command
{
    protected $signature = 'ue:price-integrity
        {--integration= : Ограничить одной активной интеграцией}
        {--marketplace=ozon,wildberries : Маркетплейсы через запятую}
        {--tolerance=0.01 : Допуск расхождения цены в рублях}
        {--max-age-minutes=2880 : Максимальный возраст источника цены; 0 отключает проверку}
        {--repair : Автоматически пересчитать интеграцию при исправимом расхождении}
        {--fail-on-drift : Вернуть exit-код 1, если после ремонта остались проблемы}
        {--log : Записать результат в Laravel log}';

    protected $description = 'Сверяет действующие цены с unit_economics_cache и автоматически исправляет кэш';

    public function handle(
        UnitEconomicsPriceIntegrityService $integrity,
        UnitEconomicsCacheService $cacheService
    ): int {
        $lock = Cache::lock('ue:price-integrity', 1500);
        if (! $lock->get()) {
            $this->warn('Проверка цен уже выполняется другим процессом.');

            return self::SUCCESS;
        }

        try {
            return $this->runChecks($integrity, $cacheService);
        } finally {
            $lock->release();
        }
    }

    private function runChecks(
        UnitEconomicsPriceIntegrityService $integrity,
        UnitEconomicsCacheService $cacheService
    ): int {
        $marketplaces = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('marketplace'))
        )));

        $integrations = Integration::query()
            ->active()
            ->when($this->option('integration'), fn ($query, $id) => $query->where('id', (int) $id))
            ->when($marketplaces !== [], fn ($query) => $query->whereIn('marketplace', $marketplaces))
            ->orderBy('id')
            ->get();

        if ($integrations->isEmpty()) {
            $this->warn('Активные интеграции для проверки не найдены.');

            return self::SUCCESS;
        }

        $reports = [];
        $repairs = [];
        foreach ($integrations as $integration) {
            $report = $integrity->inspectIntegration(
                $integration,
                (float) $this->option('tolerance'),
                (int) $this->option('max-age-minutes')
            );

            if ($this->option('repair') && $report['repairable']) {
                $stats = $cacheService->recalculateIntegration((int) $integration->id);
                $repairs[(int) $integration->id] = $stats;
                $report = $integrity->inspectIntegration(
                    $integration,
                    (float) $this->option('tolerance'),
                    (int) $this->option('max-age-minutes')
                );
            }

            $reports[] = $report;
        }

        $this->table(
            ['integration', 'marketplace', 'products', 'cache', 'issues', 'status'],
            array_map(fn (array $report) => [
                $report['integration_id'],
                $report['marketplace'],
                $report['products'],
                $report['cache_rows'].'/'.$report['expected_cache_rows'],
                $report['issue_counts'] === []
                    ? '0'
                    : collect($report['issue_counts'])->map(fn ($count, $type) => "{$type}:{$count}")->implode(', '),
                $report['healthy'] ? 'OK' : 'DRIFT',
            ], $reports)
        );

        $issues = collect($reports)->flatMap(fn (array $report) => array_map(
            fn (array $issue) => ['integration_id' => $report['integration_id'], ...$issue],
            $report['issues']
        ))->values()->all();

        if ($issues !== []) {
            $this->warn('Остались проблемы: '.count($issues).'. Первые 20:');
            $this->table(
                ['integration', 'type', 'sku', 'scheme', 'expected', 'actual', 'source', 'observed_at'],
                array_map(fn (array $issue) => [
                    $issue['integration_id'],
                    $issue['type'],
                    $issue['sku'],
                    $issue['scheme'],
                    $issue['expected'] ?? '—',
                    $issue['actual'] ?? '—',
                    $issue['source'],
                    $issue['observed_at'] ?? '—',
                ], array_slice($issues, 0, 20))
            );
        } else {
            $this->info('Действующие цены и кэш юнит-экономики согласованы.');
        }

        if ($this->option('log')) {
            $context = [
                'reports' => array_map(fn (array $report) => [
                    ...$report,
                    'issues' => array_slice($report['issues'], 0, 20),
                ], $reports),
                'repairs' => $repairs,
            ];

            if ($issues === []) {
                Log::info('ue:price-integrity completed', $context);
            } else {
                Log::error('ue:price-integrity found unresolved issues', $context);
            }
        }

        return $issues !== [] && $this->option('fail-on-drift')
            ? self::FAILURE
            : self::SUCCESS;
    }
}
