<?php

namespace App\Domains\Locality;

use App\Domains\Locality\Console\LocalityBackfillCommand;
use App\Domains\Locality\Console\LocalityHealthCheckCommand;
use App\Domains\Locality\Console\LocalityReconcileCommand;
use App\Domains\Locality\Console\LocalityRecomputeCommand;
use App\Domains\Locality\Console\LocalitySyncClustersCommand;
use App\Domains\Locality\Console\LocalitySyncFinanceCommand;
use App\Domains\Locality\Legacy\LegacyLocalityFacade;
use Illuminate\Support\ServiceProvider;

class LocalityServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    public array $singletons = [
        LegacyLocalityFacade::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/locality.php', 'locality');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                LocalitySyncClustersCommand::class,
                LocalitySyncFinanceCommand::class,
                LocalityBackfillCommand::class,
                LocalityRecomputeCommand::class,
                LocalityReconcileCommand::class,
                LocalityHealthCheckCommand::class,
            ]);
        }
    }
}
