<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductExportAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sellico.skip_permission_check', true);
        Storage::fake('local');

        Schema::dropIfExists('products');
        Schema::dropIfExists('integrations');

        Schema::create('integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('work_space_id')->nullable();
            $table->string('name')->nullable();
            $table->string('marketplace')->nullable();
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_sync_enabled')->default(true);
            $table->unsignedInteger('sync_interval_hours')->default(6);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->string('marketplace')->nullable();
            $table->string('marketplace_id')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('category')->nullable();
            $table->integer('stock')->nullable();
            $table->timestamps();
        });

        Integration::create([
            'id' => 10,
            'work_space_id' => 101,
            'name' => 'Ozon A',
            'marketplace' => 'ozon',
            'credentials' => [],
        ]);

        Product::create([
            'id' => 'product-1',
            'integration_id' => 10,
            'sku' => 'SKU-1',
            'name' => 'Product 1',
            'marketplace' => 'ozon',
            'price' => 1000,
            'stock' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('integrations');

        parent::tearDown();
    }

    public function test_product_export_download_requires_access_to_original_integration(): void
    {
        $exportResponse = $this->withHeader('X-Sellico-Workspace', '101')
            ->postJson('/api/products/export/ozon', [
                'integration_id' => 10,
            ]);

        $exportResponse->assertOk();
        $exportId = $exportResponse->json('data.export_id');

        Storage::disk('local')->assertExists("exports/products/{$exportId}.csv");
        Storage::disk('local')->assertExists("exports/products/{$exportId}.json");

        $this->withHeader('X-Sellico-Workspace', '202')
            ->getJson("/api/products/export/{$exportId}/download")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_product_export_download_allows_original_workspace(): void
    {
        $exportResponse = $this->withHeader('X-Sellico-Workspace', '101')
            ->postJson('/api/products/export/ozon', [
                'integration_id' => 10,
            ]);

        $exportResponse->assertOk();
        $exportId = $exportResponse->json('data.export_id');

        $this->withHeader('X-Sellico-Workspace', '101')
            ->get("/api/products/export/{$exportId}/download")
            ->assertOk();
    }
}
