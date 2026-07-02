<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\UzumExtensionCommand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ingest расширения Uzum + командный мост (см. UzumExtensionController).
 * Паттерн окружения — как в IntegrationAccessTest: sqlite :memory:, локальная
 * интеграция с совпадающим workspace, skip_permission_check.
 */
class UzumExtensionIngestTest extends TestCase
{
    private const INTEGRATION_ID = 77;

    private const WORKSPACE_ID = 101;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sellico.skip_permission_check', true);

        Schema::dropIfExists('uzum_extension_commands');
        Schema::dropIfExists('uzum_extension_snapshots');
        Schema::dropIfExists('products');
        Schema::dropIfExists('integrations');

        Schema::create('integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('work_space_id')->nullable();
            $table->string('name')->nullable();
            $table->string('marketplace')->nullable();
            $table->text('credentials')->nullable();
            $table->text('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('marketplace')->nullable();
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->string('marketplace_id')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->string('category')->nullable();
            $table->string('vendor_code')->nullable();
            $table->json('uzum_data')->nullable();
            $table->timestamps();
        });

        Schema::create('uzum_extension_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('shop_id', 100)->nullable();
            $table->string('payload_type', 30);
            $table->string('payload_hash', 64)->nullable();
            $table->json('raw_payload');
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->string('status', 20)->default('ok');
            $table->text('error_message')->nullable();
            $table->string('extension_version', 30)->nullable();
            $table->string('extractor_version', 30)->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('uzum_extension_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('integration_id');
            $table->string('command_key', 80)->nullable();
            $table->string('method', 8)->default('GET');
            $table->text('path');
            $table->json('body')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->integer('http_status')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        DB::table('integrations')->insert([
            'id' => self::INTEGRATION_ID,
            'work_space_id' => self::WORKSPACE_ID,
            'name' => 'Uzum Shop',
            'marketplace' => 'uzum',
            'credentials' => '{}',
            'settings' => json_encode(['uzum_shop_id' => '93581']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('uzum_extension_commands');
        Schema::dropIfExists('uzum_extension_snapshots');
        Schema::dropIfExists('products');
        Schema::dropIfExists('integrations');

        parent::tearDown();
    }

    private function ingest(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer test-token')
            ->withHeader('X-Sellico-Workspace', (string) self::WORKSPACE_ID)
            ->postJson('/api/integrations/'.self::INTEGRATION_ID.'/uzum/extension/ingest', $payload);
    }

    public function test_ingest_products_creates_product_and_snapshot(): void
    {
        $response = $this->ingest([
            'payload_type' => 'products',
            'shop_id' => '93581',
            'items' => [
                ['productId' => 111, 'title' => 'Чайник', 'price' => 250000, 'stock' => 5],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('rejected', 0);

        $this->assertDatabaseHas('products', [
            'integration_id' => self::INTEGRATION_ID,
            'sku' => 'uzum-product-111',
            'name' => 'Чайник',
        ]);
        $this->assertDatabaseHas('uzum_extension_snapshots', [
            'integration_id' => self::INTEGRATION_ID,
            'payload_type' => 'products',
            'accepted_count' => 1,
            'status' => 'ok',
        ]);
    }

    public function test_ingest_dimensions_validates_ranges_and_fills_error_message(): void
    {
        Product::query()->create([
            'integration_id' => self::INTEGRATION_ID,
            'marketplace' => 'uzum',
            'sku' => 'uzum-product-111',
            'name' => 'Чайник',
            'uzum_data' => ['product_id' => 111, 'sku_id' => 555],
        ]);

        $response = $this->ingest([
            'payload_type' => 'dimensions',
            'items' => [
                // валидные ширина/вес, мусорная длина (отрицательная) — не должна записаться
                ['sku_id' => 555, 'length' => -10, 'width' => 20, 'weight' => 1.5],
                // без sku_id/product_id — отклоняется целиком
                ['length' => 10],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('rejected', 1);

        $dimensions = Product::query()->where('sku', 'uzum-product-111')->first()->uzum_data['dimensions'];
        $this->assertEquals(['width' => 20, 'weight' => 1.5], $dimensions);

        // Снапшот дописан фактом: partial + причины отказов в error_message.
        $snapshot = DB::table('uzum_extension_snapshots')->first();
        $this->assertSame('partial', $snapshot->status);
        $this->assertNotEmpty($snapshot->error_message);
    }

    public function test_ingest_products_with_sku_id_updates_only_matching_row(): void
    {
        foreach ([['sku_id' => 1, 'stock' => 0], ['sku_id' => 2, 'stock' => 0]] as $i => $row) {
            Product::query()->create([
                'integration_id' => self::INTEGRATION_ID,
                'marketplace' => 'uzum',
                'sku' => 'sku-'.$row['sku_id'],
                'name' => 'Футболка',
                'stock' => $row['stock'],
                'uzum_data' => ['product_id' => 500, 'sku_id' => $row['sku_id']],
            ]);
        }

        $response = $this->ingest([
            'payload_type' => 'products',
            'items' => [
                ['productId' => 500, 'skuId' => 2, 'title' => 'Футболка', 'stock' => 20],
            ],
        ]);

        $response->assertOk()->assertJsonPath('accepted', 1);

        // Обновилась только строка SKU 2; SKU 1 не тронут.
        $this->assertSame(20, Product::query()->where('sku', 'sku-2')->first()->stock);
        $this->assertSame(0, Product::query()->where('sku', 'sku-1')->first()->stock);
    }

    public function test_ingest_orders_raw_snapshot_is_stored(): void
    {
        $response = $this->ingest([
            'payload_type' => 'orders',
            'items' => [
                ['raw' => ['orderList' => [['id' => 1]]], 'source_url' => 'https://api-seller.uzum.uz/api/seller/shop/93581/order/list'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('accepted', 1);
        $this->assertDatabaseHas('uzum_extension_snapshots', [
            'integration_id' => self::INTEGRATION_ID,
            'payload_type' => 'orders',
            'status' => 'ok',
        ]);
    }

    public function test_extension_version_endpoint_is_public(): void
    {
        config()->set('services.uzum.extension_min_version', '0.2.0');

        $this->getJson('/api/extension/version')
            ->assertOk()
            ->assertJsonPath('min_version', '0.2.0');
    }

    public function test_command_lifecycle_enqueue_fetch_result(): void
    {
        $headers = [
            'Authorization' => 'Bearer test-token',
            'X-Sellico-Workspace' => (string) self::WORKSPACE_ID,
        ];
        $base = '/api/integrations/'.self::INTEGRATION_ID.'/uzum/extension/commands';

        $enqueue = $this->withHeaders($headers)->postJson($base, ['command_key' => 'products.stats']);
        $enqueue->assertOk()->assertJsonPath('status', 'pending');
        $commandId = $enqueue->json('id');

        $list = $this->withHeaders($headers)->getJson($base);
        $list->assertOk();
        $this->assertSame($commandId, $list->json('data.0.id'));
        $this->assertSame('/api/seller/shop/93581/product/products-statistic', $list->json('data.0.path'));

        $result = $this->withHeaders($headers)->postJson("{$base}/{$commandId}/result", [
            'ok' => true,
            'status' => 200,
            'data' => ['total' => 42],
        ]);
        $result->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('uzum_extension_commands', [
            'id' => $commandId,
            'status' => 'done',
            'http_status' => 200,
        ]);
    }

    public function test_enqueue_rejects_when_pending_queue_full(): void
    {
        for ($i = 0; $i < 50; $i++) {
            UzumExtensionCommand::create([
                'integration_id' => self::INTEGRATION_ID,
                'command_key' => 'products.stats',
                'method' => 'GET',
                'path' => '/api/seller/shop/93581/product/products-statistic',
                'status' => 'pending',
                'expires_at' => now()->addMinutes(10),
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->withHeader('X-Sellico-Workspace', (string) self::WORKSPACE_ID)
            ->postJson('/api/integrations/'.self::INTEGRATION_ID.'/uzum/extension/commands', [
                'command_key' => 'products.stats',
            ]);

        $response->assertStatus(429)->assertJsonPath('success', false);
    }
}
