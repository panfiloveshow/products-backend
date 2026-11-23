<?php

namespace App\Console\Commands;

use Illuminate\Console\Scheduling\ScheduleRunCommand as BaseScheduleRunCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Accepts legacy --columns if cron/scripts mistakenly pass it to schedule:run.
 */
#[AsCommand(name: 'schedule:run')]
class ScheduleRunCommand extends BaseScheduleRunCommand
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
