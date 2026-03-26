<?php

namespace App\Console\Commands;

use Illuminate\Console\Scheduling\ScheduleListCommand as BaseScheduleListCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Accepts legacy --columns from monitoring/cron scripts; Laravel 12 removed it from schedule:list.
 */
#[AsCommand(name: 'schedule:list')]
class ScheduleListCommand extends BaseScheduleListCommand
{
    protected $signature = 'schedule:list
        {--timezone= : The timezone that times should be displayed in}
        {--next : Sort the listed tasks by their next due date}
        {--json : Output the scheduled tasks as JSON}
        {--columns= : Ignored; kept for compatibility with older artisan invocations}
    ';
}
