<?php

namespace App\Console\Commands;

use App\Services\Manager\SupplyReferenceSchema;
use Illuminate\Console\Command;

final class CheckSupplyReferenceSchema extends Command
{
    protected $signature = 'manager:check-supply-references {--json : Output metadata diagnostics as JSON}';

    protected $description = 'Read supply UUID reference metadata without changing schema or data';

    public function handle(SupplyReferenceSchema $schema): int
    {
        $report = $schema->inspect();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Supply references: '.$report['status']);
            $this->line('Read-only metadata check; business rows were not checked.');
            foreach ($report['issues'] as $issue) {
                $this->line($issue);
            }
            foreach ($report['checks'] as $check) {
                $this->line($check['table'].'.'.$check['column'].' -> '.$check['parent'].': '.($check['issues'] === [] ? 'ok' : implode(', ', $check['issues'])));
            }
        }

        return $report['status'] === 'schema_compatible' ? self::SUCCESS : self::FAILURE;
    }
}
