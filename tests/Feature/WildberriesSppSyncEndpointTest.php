<?php

namespace Tests\Feature;

use App\Jobs\SyncWildberriesSppJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WildberriesSppSyncEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sellico.skip_permission_check', true);
        Queue::fake();

        Schema::create('integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('work_space_id')->nullable();
            $table->string('name')->nullable();
            $table->string('marketplace');
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_sync_enabled')->default(true);
            $table->unsignedInteger('sync_interval_hours')->default(6);
            $table->timestamps();
        });
        Schema::create('wildberries_spp_sync_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->unique();
            $table->string('status')->default('idle');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('preserved_count')->default(0);
            $table->string('source')->nullable();
            $table->text('source_counts')->nullable();
            $table->text('message')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku');
            $table->string('marketplace');
            $table->timestamps();
        });
        Schema::create('wildberries_spp_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku');
            $table->string('source');
            $table->timestamps();
        });

        DB::table('integrations')->insert([
            'id' => 76,
            'work_space_id' => 101,
            'name' => 'WB 76',
            'marketplace' => 'wildberries',
            'credentials' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wildberries_spp_snapshots');
        Schema::dropIfExists('products');
        Schema::dropIfExists('wildberries_spp_sync_states');
        Schema::dropIfExists('integrations');
        parent::tearDown();
    }

    public function test_start_is_idempotent_and_status_uses_stable_contract(): void
    {
        $headers = ['X-Sellico-Workspace' => '101'];

        $this->withHeaders($headers)
            ->postJson('/api/unit-economics/integrations/76/spp-sync')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.preserved', 0);

        $this->withHeaders($headers)
            ->postJson('/api/unit-economics/integrations/76/spp-sync')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(SyncWildberriesSppJob::class, 1);

        $this->withHeaders($headers)
            ->getJson('/api/unit-economics/integrations/76/spp-sync')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'status', 'updated', 'total', 'preserved', 'source', 'source_counts', 'coverage', 'fresh_coverage',
                'known', 'known_coverage', 'known_source_counts', 'message', 'last_success_at', 'retry_at',
                'next_allowed_at', 'error',
            ]]);
    }

    public function test_status_is_idle_before_first_sync(): void
    {
        $this->withHeader('X-Sellico-Workspace', '101')
            ->getJson('/api/unit-economics/integrations/76/spp-sync')
            ->assertOk()
            ->assertJsonPath('data.status', 'idle')
            ->assertJsonPath('data.coverage', 0)
            ->assertJsonPath('data.error', null);
    }

    public function test_status_reports_accumulated_snapshot_coverage_separately_from_fresh_attempt(): void
    {
        DB::table('products')->insert([
            ['id' => 'p1', 'integration_id' => 76, 'sku' => 'A', 'marketplace' => 'wildberries', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'p2', 'integration_id' => 76, 'sku' => 'B', 'marketplace' => 'wildberries', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('wildberries_spp_snapshots')->insert([
            'integration_id' => 76,
            'sku' => 'A',
            'source' => 'orders',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('wildberries_spp_sync_states')->insert([
            'integration_id' => 76,
            'status' => 'retrying',
            'updated_count' => 0,
            'total_count' => 2,
            'preserved_count' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('X-Sellico-Workspace', '101')
            ->getJson('/api/unit-economics/integrations/76/spp-sync')
            ->assertOk()
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.fresh_coverage', 0)
            ->assertJsonPath('data.known', 1)
            ->assertJsonPath('data.known_coverage', 50)
            ->assertJsonPath('data.coverage', 50)
            ->assertJsonPath('data.known_source_counts.orders', 1);
    }

    public function test_recent_success_prevents_immediate_second_run(): void
    {
        DB::table('wildberries_spp_sync_states')->insert([
            'integration_id' => 76,
            'status' => 'partial',
            'updated_count' => 1,
            'total_count' => 2,
            'preserved_count' => 1,
            'last_success_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('X-Sellico-Workspace', '101')
            ->postJson('/api/unit-economics/integrations/76/spp-sync')
            ->assertOk()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.next_allowed_at', fn ($value) => is_string($value));

        Queue::assertNothingPushed();
    }

    public function test_workspace_cannot_start_foreign_integration(): void
    {
        $this->withHeader('X-Sellico-Workspace', '202')
            ->postJson('/api/unit-economics/integrations/76/spp-sync')
            ->assertForbidden();

        Queue::assertNothingPushed();
    }
}
