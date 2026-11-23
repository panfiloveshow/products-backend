<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\UnitEconomicsCacheController;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomicsCache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnitEconomicsListingConcurrencyTest extends TestCase
{
    public function test_simultaneous_list_reads_survive_a_database_cache_miss(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertStringStartsWith('codex_ue_', DB::getDatabaseName());
        $default = config('cache.default');
        $previousPrefix = config('cache.prefix');
        $prefix = 'ue-read-race-'.bin2hex(random_bytes(8)).'-';
        config(['cache.default' => 'database', 'cache.prefix' => $prefix]);
        Cache::purge('database');
        Http::preventStrayRequests();
        $integration = Integration::factory()->ozon()->create(['id' => random_int(900000, 990000)]);
        $product = Product::factory()->ozon()->create(['integration_id' => $integration->id, 'sku' => 'READ-RACE']);
        UnitEconomicsCache::create(['integration_id' => $integration->id, 'product_id' => $product->id,
            'sku' => $product->sku, 'product_name' => 'Read race', 'marketplace' => 'ozon', 'fulfillment_type' => 'FBO', 'price' => 1000]);
        $directory = sys_get_temp_dir().'/'.$prefix;
        mkdir($directory, 0700);
        $params = ['integration_id' => $integration->id, 'fulfillment_type' => 'FBO', 'limit' => 50];
        $cacheKey = 'ue_scheme_counts_'.$integration->id.'_ozon';

        try {
            $primed = app(UnitEconomicsCacheController::class)->index(Request::create('/api/unit-economics/ozon', 'GET', $params), 'ozon');
            $this->assertSame(200, $primed->getStatusCode());
            Cache::forget($cacheKey);
            DB::disconnect();
            $pids = [];
            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Could not fork listing reader');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        Cache::purge('database');
                        $paused = false;
                        DB::listen(function (QueryExecuted $query) use ($directory, $worker, $cacheKey, &$paused): void {
                            if ($paused || ! str_starts_with(strtolower($query->sql), 'select')
                                || ! str_contains($query->sql, '"cache"')
                                || ! str_contains(json_encode($query->bindings), $cacheKey)) {
                                return;
                            }
                            $paused = true;
                            touch($directory.'/ready-'.$worker);
                            $deadline = microtime(true) + 5;
                            while (! is_file($directory.'/ready-'.(1 - $worker))) {
                                if (microtime(true) > $deadline) {
                                    throw new \RuntimeException('Other reader did not reach the cache miss');
                                }
                                usleep(1000);
                            }
                        });
                        $response = app(UnitEconomicsCacheController::class)->index(Request::create('/api/unit-economics/ozon', 'GET', $params), 'ozon');
                        file_put_contents($directory.'/result-'.$worker, json_encode([
                            'status' => $response->getStatusCode(), 'items' => count($response->getData(true)['data']['items']),
                        ]));
                        DB::disconnect();
                        exit(0);
                    } catch (\Throwable $error) {
                        file_put_contents($directory.'/result-'.$worker, json_encode(['error' => $error::class, 'code' => $error->getCode()]));
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            $statuses = [];
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $statuses[] = pcntl_wexitstatus($status);
            }
            DB::purge();
            foreach ($statuses as $worker => $status) {
                $result = json_decode(file_get_contents($directory.'/result-'.$worker), true);
                $this->assertSame(0, $status, json_encode($result));
                $this->assertSame(['status' => 200, 'items' => 1], $result);
            }
            $this->assertSame(1, UnitEconomicsCache::where('integration_id', $integration->id)->count());
        } finally {
            DB::purge();
            DB::table('cache')->where('key', 'like', $prefix.'%')->delete();
            UnitEconomicsCache::where('integration_id', $integration->id)->delete();
            Product::where('integration_id', $integration->id)->delete();
            Integration::whereKey($integration->id)->delete();
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            config(['cache.default' => $default, 'cache.prefix' => $previousPrefix]);
            Cache::purge('database');
        }
    }
}
