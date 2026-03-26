<?php

namespace App\Console\Commands;

use Illuminate\Console\Scheduling\ScheduleRunCommand as BaseScheduleRunCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Accepts legacy --columns if cron/scripts mistakenly pass it to schedule:run.
 */
#[AsCommand(name: 'schedule:run')]
class ScheduleRunCommand extends BaseScheduleRunCommand
{
    protected $signature = 'schedule:run
        {--whisper : Do not output message indicating that no jobs were ready to run}
        {--columns= : Ignored; kept for compatibility with older artisan invocations}
    ';
}
