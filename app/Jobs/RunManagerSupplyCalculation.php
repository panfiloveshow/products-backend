<?php

namespace App\Jobs;

use App\Services\AutoSupplyPlanService;
use App\Services\Manager\ManagerSupplyCalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class RunManagerSupplyCalculation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 300;

    public int $tries = 1;
    public int $timeout = self::TIMEOUT_SECONDS;
    public bool $failOnTimeout = true;

    public readonly string $claimId;

    public function __construct(public readonly string $operationId)
    {
        $this->claimId = (string) Str::uuid();
    }

    public function handle(ManagerSupplyCalculationService $operations, AutoSupplyPlanService $calculator): void
    {
        $planId = $operations->begin($this->operationId, $this->claimId);
        if ($planId === null) return;
        try {
            (new CalculateAutoSupplyPlanJob($planId))->handle($calculator);
            $operations->finish($this->operationId, false, false, $this->claimId);
        } catch (\Throwable $error) {
            $operations->finish($this->operationId, true, false, $this->claimId);
            throw $error;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(ManagerSupplyCalculationService::class)->finish($this->operationId, true, false, $this->claimId);
    }
}
