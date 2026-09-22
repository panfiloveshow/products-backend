<?php

namespace Tests\Feature\Locality;

use App\Domains\Locality\Recommendation\LocalityDraftApplier;
use App\Models\Integration;
use App\Models\LocalityRecommendation;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «Применить рекомендацию локальности» после отключения /v1/draft/create:
 * черновик — /v1/draft/direct/create, готовность — /v2/draft/create/info.
 */
class LocalityDraftApplierTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_apply_creates_direct_draft_in_cluster_and_links_it(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 919301, 'work_space_id' => 93]);
        Product::factory()->create([
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'sku' => 'SKU-1',
            'ozon_data' => ['sku' => 700001],
        ]);
        $rec = LocalityRecommendation::withoutGlobalScopes()->create([
            'integration_id' => $integration->id,
            'sku' => 'SKU-1',
            'target_cluster_id' => '101',
            'target_cluster_name' => 'Москва',
            'recommended_qty_units' => 12,
            'state' => LocalityRecommendation::STATE_NEW,
            'cohort_id' => (string) Str::uuid(),
            'computed_at' => now(),
            'period_from' => now()->subDays(28)->toDateString(),
            'period_to' => now()->toDateString(),
            'basis_snapshot_date' => now()->toDateString(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v1/cluster/list' => Http::response([
                'clusters' => [['id' => 101, 'macrolocal_cluster_id' => 4039, 'name' => 'Москва', 'logistic_clusters' => []]],
            ]),
            'api-seller.ozon.ru/v1/draft/direct/create' => Http::response(['draft_id' => 55, 'errors' => []]),
            'api-seller.ozon.ru/v2/draft/create/info' => Http::response(['status' => 'SUCCESS', 'clusters' => [], 'errors' => []]),
        ]);

        $result = app(LocalityDraftApplier::class)->apply($rec);

        $this->assertTrue($result['success']);
        $this->assertSame('55', $result['draft_id']);
        $rec->refresh();
        $this->assertSame(LocalityRecommendation::STATE_APPLIED, $rec->state);
        $this->assertSame('55', $rec->linked_draft_id);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-seller.ozon.ru/v1/draft/direct/create'
            && $request['cluster_info'] === [
                'items' => [['sku' => 700001, 'quantity' => 12]],
                'macrolocal_cluster_id' => 4039,
            ]);
    }
}
