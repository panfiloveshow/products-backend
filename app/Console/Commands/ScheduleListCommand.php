<?php

namespace App\Console\Commands;

use Illuminate\Console\Scheduling\ScheduleListCommand as BaseScheduleListCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Accepts legacy --columns from monitoring/cron scripts; Laravel 12+ removed it from schedule:list.
 */
#[AsCommand(name: 'schedule:list')]
class ScheduleListCommand extends BaseScheduleListCommand
{
    public function __construct()
    {
        parent::__construct();

        if (! $this->getDefinition()->hasOption('columns')) {
            $this->addOption(
                'columns',
                null,
                InputOption::VALUE_OPTIONAL,
                'Ignored; kept for compatibility with older artisan invocations'
            );
        }
    }
}
