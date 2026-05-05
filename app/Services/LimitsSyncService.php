<?php

namespace App\Services;

use App\Models\AutoSupplyPlan;
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

    public function countWorkspaceAutoplanning(int $workspaceId): int
    {
        if ($workspaceId <= 0) {
            return 0;
        }

        return AutoSupplyPlan::query()
            ->join('integrations', 'auto_supply_plans.integration_id', '=', 'integrations.id')
            ->where('integrations.work_space_id', $workspaceId)
            ->count('auto_supply_plans.id');
    }

    /**
     * @return array<string,mixed>
     */
    public function syncWorkspaceProductsLimit(int $workspaceId): array
    {
        $currentValue = $this->countWorkspaceProducts($workspaceId);

        return $this->syncWorkspaceLimit($workspaceId, 'products', $currentValue);
    }

    /**
     * @return array<string,mixed>
     */
    public function syncWorkspaceAutoplanningLimit(int $workspaceId): array
    {
        $currentValue = $this->countWorkspaceAutoplanning($workspaceId);

        return $this->syncWorkspaceLimit($workspaceId, 'autoplanning', $currentValue);
    }

    /**
     * @return array<string,mixed>
     */
    public function ensureLimitAvailable(int $workspaceId, string $type, int $increment = 0): array
    {
        $type = $this->normalizeType($type);
        $currentValue = $this->currentValue($workspaceId, $type);
        $nextValue = $currentValue + max(0, $increment);

        $syncResult = $this->syncWorkspaceLimit($workspaceId, $type, $currentValue);
        $limitResult = $this->getWorkspaceLimit($workspaceId, $type);

        if (! ($limitResult['success'] ?? false)) {
            return [
                'success' => false,
                'status' => $limitResult['status'] ?? 502,
                'message' => $limitResult['error'] ?? 'Не удалось проверить лимит тарифа',
                'type' => $type,
                'current_value' => $currentValue,
                'sync_success' => $syncResult['success'] ?? false,
            ];
        }

        $limit = $limitResult['limit']['limit'] ?? null;
        if ($limit === null || $limit === '') {
            return [
                'success' => true,
                'status' => 200,
                'type' => $type,
                'current_value' => $currentValue,
                'limit' => null,
                'sync_success' => $syncResult['success'] ?? false,
            ];
        }

        $limit = (int) $limit;
        if ($nextValue > $limit) {
            return [
                'success' => false,
                'status' => 403,
                'message' => $this->limitExceededMessage($type),
                'type' => $type,
                'current_value' => $currentValue,
                'requested_value' => $nextValue,
                'limit' => $limit,
                'sync_success' => $syncResult['success'] ?? false,
            ];
        }

        return [
            'success' => true,
            'status' => 200,
            'type' => $type,
            'current_value' => $currentValue,
            'requested_value' => $nextValue,
            'limit' => $limit,
            'sync_success' => $syncResult['success'] ?? false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function limitResponsePayload(array $check): array
    {
        return [
            'success' => false,
            'message' => $check['message'] ?? 'Лимит тарифа исчерпан',
            'error' => 'limit_exceeded',
            'type' => $check['type'] ?? null,
            'current_value' => $check['current_value'] ?? null,
            'requested_value' => $check['requested_value'] ?? null,
            'limit' => $check['limit'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getWorkspaceLimit(int $workspaceId, string $type): array
    {
        $type = $this->normalizeType($type);
        $result = $this->sellicoApi->getWorkspaceLimitsExternal($workspaceId, $type);

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $limits = $result['limits'] ?? [];
        $limit = $this->findLimit($limits, $type);

        return [
            'success' => true,
            'status' => $result['status'] ?? 200,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function syncWorkspaceLimit(int $workspaceId, string $type, int $currentValue): array
    {
        $type = $this->normalizeType($type);

        $result = $this->sellicoApi->syncWorkspaceLimitExternal($workspaceId, [
            'type' => $type,
            'current_value' => $currentValue,
        ]);

        if ($result['success'] ?? false) {
            Log::info('Workspace external limit synced', [
                'workspace_id' => $workspaceId,
                'type' => $type,
                'current_value' => $currentValue,
            ]);
        } else {
            Log::warning('Workspace external limit sync failed', [
                'workspace_id' => $workspaceId,
                'type' => $type,
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

    private function currentValue(int $workspaceId, string $type): int
    {
        return match ($this->normalizeType($type)) {
            'products' => $this->countWorkspaceProducts($workspaceId),
            'autoplanning' => $this->countWorkspaceAutoplanning($workspaceId),
            default => 0,
        };
    }

    private function normalizeType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'product', 'products' => 'products',
            'auto_planning', 'auto-planning', 'autoplanning' => 'autoplanning',
            default => strtolower(trim($type)),
        };
    }

    private function limitExceededMessage(string $type): string
    {
        return match ($this->normalizeType($type)) {
            'products' => 'Лимит товаров по тарифу исчерпан. Обновите тариф или удалите лишние товары, чтобы продолжить синхронизацию.',
            'autoplanning' => 'Лимит автопланирования по тарифу исчерпан. Обновите тариф или удалите лишние планы, чтобы создать новый.',
            default => 'Лимит тарифа исчерпан.',
        };
    }

    private function findLimit(mixed $limits, string $type): ?array
    {
        if (! is_array($limits)) {
            return null;
        }

        if (isset($limits['data']) && is_array($limits['data'])) {
            $limits = $limits['data'];
        }

        if (isset($limits['type'])) {
            return ($limits['type'] === $type) ? $limits : null;
        }

        foreach ($limits as $limit) {
            if (is_array($limit) && ($limit['type'] ?? null) === $type) {
                return $limit;
            }
        }

        return null;
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

    /**
     * @return list<int>
     */
    public function workspaceIdsWithLimitUsage(): array
    {
        $productWorkspaceIds = Integration::query()
            ->whereNotNull('work_space_id')
            ->whereHas('products')
            ->distinct()
            ->pluck('work_space_id')
            ->map(fn ($id) => (int) $id);

        $autoplanningWorkspaceIds = AutoSupplyPlan::query()
            ->join('integrations', 'auto_supply_plans.integration_id', '=', 'integrations.id')
            ->whereNotNull('integrations.work_space_id')
            ->distinct()
            ->pluck('integrations.work_space_id')
            ->map(fn ($id) => (int) $id);

        return $productWorkspaceIds
            ->merge($autoplanningWorkspaceIds)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
