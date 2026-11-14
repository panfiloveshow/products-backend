<?php

namespace App\Jobs;

use App\Services\Manager\ManagerOzonPackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class RunManagerOzonPackingGroup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;
    public bool $failOnTimeout = true;
    public readonly string $claimId;

    public function __construct(public readonly string $operationId, #[\SensitiveParameter] private readonly string $dispatchToken)
    {
        $this->claimId = (string) Str::uuid();
    }
    public function handle(ManagerOzonPackingService $operations): void { $operations->execute($this->operationId, $this->dispatchToken, $this->claimId); }
    public function failed(?\Throwable $exception): void { app(ManagerOzonPackingService::class)->failed($this->operationId, $this->dispatchToken, $this->claimId); }
    public function __debugInfo(): array { return ['operation_id' => $this->operationId, 'claim_id' => $this->claimId]; }
}
