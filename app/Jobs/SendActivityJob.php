<?php

namespace App\Jobs;

use App\Services\SellicoApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fire-and-forget доставка activity в PlaceSales API
 * (POST /workspaces/{workspace}/activities).
 *
 * Живёт в очереди, чтобы сбой Sellico API не ломал основные бизнес-операции.
 */
class SendActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $workspaceId,
        public string $action,
        public string $title,
        public ?string $description = null,
        public array $meta = [],
        public ?string $token = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(SellicoApiService $sellico): void
    {
        $result = $sellico->sendActivity(
            $this->workspaceId,
            $this->action,
            $this->title,
            $this->description,
            $this->meta,
            $this->token
        );

        if (! ($result['success'] ?? false)) {
            $status = $result['status'] ?? null;

            // 401/403 — токен невалиден или не имеет прав, повтор не поможет.
            // 422 — невалидный payload, тоже бессмысленно ретраить.
            if (in_array($status, [401, 403, 422], true)) {
                Log::warning('SendActivityJob: non-retryable error, skipping retries', [
                    'workspace_id' => $this->workspaceId,
                    'action' => $this->action,
                    'status' => $status,
                    'error' => $result['error'] ?? null,
                ]);

                $this->delete();

                return;
            }

            throw new \RuntimeException(
                'Failed to send activity: '.($result['error'] ?? 'unknown')
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendActivityJob failed after retries', [
            'workspace_id' => $this->workspaceId,
            'action' => $this->action,
            'error' => $exception->getMessage(),
        ]);
    }
}
