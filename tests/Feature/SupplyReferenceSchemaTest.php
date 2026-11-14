<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Services\Manager\SupplyReferenceSchema;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SupplyReferenceSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function references(): array
    {
        return [
            'recommendation product' => ['supply_recommendations', 'product_id', 'products'],
            'item product' => ['supply_items', 'product_id', 'products'],
            'recommendation plan' => ['supply_recommendations', 'supply_plan_id', 'supply_plans'],
            'supply plan' => ['supplies', 'supply_plan_id', 'supply_plans'],
        ];
    }

    #[DataProvider('references')]
    public function test_uuid_reference_storage_matches_its_parent(string $table, string $column, string $parent): void
    {
        $this->assertSame(Schema::getColumnType($parent, 'id', true), Schema::getColumnType($table, $column, true));
    }

    public function test_uuid_values_and_existing_delete_policies_survive_the_complete_migration_chain(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => 73901]);
        $product = Product::factory()->ozon()->create(['integration_id' => $integration->id]);
        $plan = (string) Str::uuid();
        DB::table('supply_plans')->insert(['id' => $plan, 'name' => 'Test-only UUID plan',
            'period_start' => '2026-11-10', 'period_end' => '2026-11-30', 'marketplace' => 'ozon']);
        $rec = DB::table('supply_recommendations')->insertGetId(['integration_id' => $integration->id,
            'sku' => $product->sku, 'product_id' => $product->id, 'supply_plan_id' => $plan]);
        $supply = DB::table('supplies')->insertGetId(['integration_id' => $integration->id,
            'crm_number' => 'TEST-UUID-REFERENCE', 'supply_plan_id' => $plan]);
        $item = DB::table('supply_items')->insertGetId(['supply_id' => $supply, 'sku' => $product->sku,
            'product_id' => $product->id, 'recommendation_id' => $rec]);

        $this->assertSame($product->id, DB::table('supply_recommendations')->where('id', $rec)->value('product_id'));
        $this->assertSame($product->id, DB::table('supply_items')->where('id', $item)->value('product_id'));
        $this->assertSame($plan, DB::table('supplies')->where('id', $supply)->value('supply_plan_id'));
        DB::table('supply_plans')->where('id', $plan)->delete();
        $this->assertNull(DB::table('supply_recommendations')->where('id', $rec)->value('supply_plan_id'));
        $this->assertNull(DB::table('supplies')->where('id', $supply)->value('supply_plan_id'));
        DB::table('products')->where('id', $product->id)->delete();
        $this->assertFalse(DB::table('supply_recommendations')->where('id', $rec)->exists());
        $this->assertNull(DB::table('supply_items')->where('id', $item)->value('product_id'));
        $this->assertNull(DB::table('supply_items')->where('id', $item)->value('recommendation_id'));
        $this->assertTrue(DB::table('supply_items')->where('id', $item)->exists());
    }

    public function test_read_only_command_checks_all_four_references_without_claiming_business_data_validation(): void
    {
        // Trigger the lazy test migrations before recording the command's queries.
        $this->assertTrue(Schema::hasTable('supply_items'));
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $output = new BufferedOutput;
        try {
            $exit = $this->app->make(Kernel::class)->call('manager:check-supply-references', ['--json' => true], $output);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }
        $report = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('schema_compatible', $report['status']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['data_checked']);
        $this->assertCount(4, $report['checks']);
        $this->assertSame([], $report['issues']);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^\s*(select\b|pragma\b|show\b)/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/\b(?:from|join)\s+["`]?supply_(?:items|recommendations)["`]?\b/i', $query['query']);
        }
    }

    public function test_existing_numeric_reference_is_reported_without_converting_or_removing_rows(): void
    {
        Schema::table('supply_items', fn (Blueprint $table) => $table->dropForeign(['product_id']));
        Schema::table('supply_items', fn (Blueprint $table) => $table->dropColumn('product_id'));
        Schema::table('supply_items', fn (Blueprint $table) => $table->unsignedBigInteger('product_id')->nullable());
        $integration = Integration::factory()->ozon()->create(['id' => 73902]);
        $supply = DB::table('supplies')->insertGetId(['integration_id' => $integration->id, 'crm_number' => 'TEST-LEGACY-UUID']);
        $id = DB::table('supply_items')->insertGetId(['supply_id' => $supply, 'sku' => 'legacy', 'product_id' => 123]);
        $before = DB::table('supply_items')->where('id', $id)->first();
        $type = Schema::getColumnType('supply_items', 'product_id', true);

        $report = app(SupplyReferenceSchema::class)->inspect();
        $this->assertSame('schema_incompatible', $report['status']);
        $issues = collect($report['checks'])->firstWhere('table', 'supply_items')['issues'];
        $this->assertContains('reference_type_mismatch', $issues);
        $this->assertContains('reference_foreign_key_missing_or_ambiguous', $issues);
        $this->assertEquals($before, DB::table('supply_items')->where('id', $id)->first());
        $this->assertSame($type, Schema::getColumnType('supply_items', 'product_id', true));
        $this->artisan('manager:check-supply-references')->expectsOutputToContain('schema_incompatible')->assertFailed();
    }

    public function test_wrong_delete_policy_is_incompatible_even_with_matching_uuid_storage(): void
    {
        Schema::table('supply_items', fn (Blueprint $table) => $table->dropForeign(['product_id']));
        Schema::table('supply_items', fn (Blueprint $table) => $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete());
        $report = app(SupplyReferenceSchema::class)->inspect();

        $this->assertSame('schema_incompatible', $report['status']);
        $this->assertContains('reference_delete_policy_mismatch', collect($report['checks'])->firstWhere('table', 'supply_items')['issues']);
    }

    public function test_unavailable_metadata_does_not_expose_connection_error_details(): void
    {
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('getSchemaBuilder')->once()->andThrow(new \RuntimeException('private-connection-password-marker'));
        $report = app(SupplyReferenceSchema::class)->inspect($connection);

        $this->assertSame('unavailable', $report['status']);
        $this->assertSame(['schema_metadata_unavailable'], $report['issues']);
        $this->assertSame([], $report['checks']);
        $this->assertStringNotContainsString('private-connection', json_encode($report));
    }
}
