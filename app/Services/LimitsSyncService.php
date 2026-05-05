<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class LimitsSyncService
{
    public function __construct(
        private SellicoApiService $sellicoApi
    ) {}

    public function countWorkspaceProducts(int $workspaceId): int
    {
        if ($workspaceId <= 0) {
            return 0;
        }

        return Product::query()
            ->join('integrations', 'products.integration_id', '=', 'integrations.id')
            ->where('integrations.work_space_id', $workspaceId)
            ->count('products.id');
    }

    /**
     * @return array<string,mixed>
     */
    public function syncWorkspaceProductsLimit(int $workspaceId): array
    {
        $currentValue = $this->countWorkspaceProducts($workspaceId);

        $result = $this->sellicoApi->syncWorkspaceLimitExternal($workspaceId, [
            'type' => 'products',
            'current_value' => $currentValue,
        ]);

        if ($result['success'] ?? false) {
            Log::info('Workspace products limit synced', [
                'workspace_id' => $workspaceId,
                'current_value' => $currentValue,
            ]);
        } else {
            Log::warning('Workspace products limit sync failed', [
                'workspace_id' => $workspaceId,
                'current_value' => $currentValue,
                'status' => $result['status'] ?? null,
                'error' => $result['error'] ?? null,
            ]);
        }

        return array_merge($result, [
            'workspace_id' => $workspaceId,
            'type' => 'products',
            'current_value' => $currentValue,
        ]);
    }

    /**
     * @return list<int>
     */
    public function workspaceIdsWithProductIntegrations(): array
    {
        return Integration::query()
            ->whereNotNull('work_space_id')
            ->distinct()
            ->orderBy('work_space_id')
            ->pluck('work_space_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
