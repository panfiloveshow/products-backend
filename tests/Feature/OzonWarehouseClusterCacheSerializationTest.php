<?php

namespace Tests\Feature;

use App\Models\OzonWarehouseCluster;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OzonWarehouseClusterCacheSerializationTest extends TestCase
{
    private ?string $previousCacheDefault = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousCacheDefault = config('cache.default');

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('ozon_warehouse_clusters')) {
            Schema::create('ozon_warehouse_clusters', function (Blueprint $table): void {
                $table->id();
                $table->string('warehouse_name');
                $table->string('warehouse_name_normalized')->index();
                $table->unsignedBigInteger('cluster_id')->index();
                $table->string('cluster_name');
                $table->string('region')->nullable();
                $table->decimal('latitude', 10, 6)->nullable();
                $table->decimal('longitude', 10, 6)->nullable();
                $table->boolean('is_hub')->nullable();
                $table->boolean('is_negabarit')->default(false);
                $table->boolean('is_jewelry')->default(false);
                $table->timestamps();
            });
        }

        DB::table('cache')->delete();
        DB::table('ozon_warehouse_clusters')->delete();
        config([
            'cache.default' => 'database',
            'cache.serializable_classes' => false,
        ]);
        Cache::forgetDriver('database');
    }

    private function decodeRawCacheValue(string $value): string
    {
        if (! str_contains($value, ':') && ! str_contains($value, ';')) {
            $decoded = base64_decode($value, true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $value;
    }

    protected function tearDown(): void
    {
        if ($this->previousCacheDefault !== null) {
            config(['cache.default' => $this->previousCacheDefault]);
        }

        parent::tearDown();
    }

    public function test_find_by_warehouse_name_roundtrips_without_serializing_php_objects(): void
    {
        $row = OzonWarehouseCluster::create([
            'warehouse_name' => 'Хоругвино РФЦ',
            'warehouse_name_normalized' => OzonWarehouseCluster::normalizeWarehouseName('Хоругвино РФЦ'),
            'cluster_id' => 154,
            'cluster_name' => 'Москва, МО и Дальние регионы',
            'region' => 'Центр',
            'is_hub' => true,
            'is_negabarit' => false,
            'is_jewelry' => false,
        ]);

        $cold = OzonWarehouseCluster::findByWarehouseName('Хоругвино РФЦ');
        $warm = OzonWarehouseCluster::findByWarehouseName('Хоругвино РФЦ');

        $this->assertInstanceOf(OzonWarehouseCluster::class, $cold);
        $this->assertInstanceOf(OzonWarehouseCluster::class, $warm);
        $this->assertSame($row->id, $warm->id);
        $this->assertSame(154, $warm->cluster_id);
        $this->assertSame('Москва, МО и Дальние регионы', $warm->cluster_name);
        $this->assertTrue($warm->is_hub);

        $rawValues = DB::table('cache')->pluck('value');
        $this->assertNotEmpty($rawValues);
        foreach ($rawValues as $value) {
            $this->assertDoesNotMatchRegularExpression(
                '/O:\d+:"/',
                $this->decodeRawCacheValue((string) $value),
                'Cache must not serialize PHP objects (breaks under Laravel 13 serializable_classes=false).'
            );
        }
    }

    public function test_find_by_warehouse_name_caches_missing_warehouse_as_null(): void
    {
        $this->assertNull(OzonWarehouseCluster::findByWarehouseName('NON_EXISTENT_WH_123'));
        $this->assertNull(OzonWarehouseCluster::findByWarehouseName('NON_EXISTENT_WH_123'));

        $rawValues = DB::table('cache')->pluck('value');
        $this->assertNotEmpty($rawValues);
        foreach ($rawValues as $value) {
            $this->assertDoesNotMatchRegularExpression('/O:\d+:"/', $this->decodeRawCacheValue((string) $value));
        }
    }

    public function test_get_warehouses_by_cluster_roundtrips_collection_without_serializing_objects(): void
    {
        OzonWarehouseCluster::create([
            'warehouse_name' => 'Казань РФЦ',
            'warehouse_name_normalized' => OzonWarehouseCluster::normalizeWarehouseName('Казань РФЦ'),
            'cluster_id' => 77,
            'cluster_name' => 'Поволжье',
            'is_hub' => false,
        ]);
        OzonWarehouseCluster::create([
            'warehouse_name' => 'Самара РФЦ',
            'warehouse_name_normalized' => OzonWarehouseCluster::normalizeWarehouseName('Самара РФЦ'),
            'cluster_id' => 77,
            'cluster_name' => 'Поволжье',
            'is_hub' => true,
        ]);

        $cold = OzonWarehouseCluster::getWarehousesByCluster(77);
        $warm = OzonWarehouseCluster::getWarehousesByCluster(77);

        $this->assertInstanceOf(Collection::class, $cold);
        $this->assertInstanceOf(Collection::class, $warm);
        $this->assertCount(2, $warm);
        $this->assertContainsOnlyInstancesOf(OzonWarehouseCluster::class, $warm);
        $this->assertEqualsCanonicalizing(
            ['Казань РФЦ', 'Самара РФЦ'],
            $warm->pluck('warehouse_name')->all()
        );

        $rawValues = DB::table('cache')->pluck('value');
        $this->assertNotEmpty($rawValues);
        foreach ($rawValues as $value) {
            $this->assertDoesNotMatchRegularExpression(
                '/O:\d+:"/',
                $this->decodeRawCacheValue((string) $value),
                'Cache must not serialize Eloquent Collection/Model objects.'
            );
        }
    }
}
