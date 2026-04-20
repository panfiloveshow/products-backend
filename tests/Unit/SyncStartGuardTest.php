<?php

namespace Tests\Unit;

use App\Models\SyncLog;
use App\Support\SyncStartGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncStartGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sync_logs');
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('marketplace');
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('sync_type')->default('products');
            $table->string('status')->default('pending');
            $table->unsignedInteger('items_synced')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->text('credentials')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sync_logs');
        parent::tearDown();
    }

    public function test_returns_null_when_no_active_sync(): void
    {
        $result = SyncStartGuard::findActiveDuplicate('products', 'ozon', 17);

        $this->assertNull($result);
    }

    public function test_returns_active_sync_if_recent(): void
    {
        $syncLog = SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_RUNNING,
            'started_at' => now()->subMinutes(30),
        ]);

        $result = SyncStartGuard::findActiveDuplicate('products', 'ozon', 17);

        $this->assertNotNull($result);
        $this->assertSame($syncLog->id, $result->id);
    }

    public function test_auto_fails_stale_running_sync_and_returns_null(): void
    {
        $syncLog = SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_RUNNING,
            'started_at' => now()->subHours(3),
        ]);

        $result = SyncStartGuard::findActiveDuplicate('products', 'ozon', 17);

        $this->assertNull($result);

        $syncLog->refresh();
        $this->assertSame(SyncLog::STATUS_FAILED, $syncLog->status);
        $this->assertNotNull($syncLog->completed_at);
        $this->assertStringContainsString('зависла', $syncLog->error_message);
    }

    public function test_auto_fails_stale_pending_sync(): void
    {
        $syncLog = SyncLog::create([
            'marketplace' => 'wildberries',
            'integration_id' => 25,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_PENDING,
        ]);

        // Laravel перезаписывает created_at при create(), обновляем вручную
        $syncLog->timestamps = false;
        $syncLog->created_at = now()->subHours(5);
        $syncLog->updated_at = now()->subHours(5);
        $syncLog->save();

        $result = SyncStartGuard::findActiveDuplicate('products', 'wildberries', 25);

        $this->assertNull($result);
    }

    public function test_does_not_affect_different_integration(): void
    {
        SyncLog::create([
            'marketplace' => 'ozon',
            'integration_id' => 17,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_RUNNING,
            'started_at' => now()->subMinutes(10),
        ]);

        $result = SyncStartGuard::findActiveDuplicate('products', 'ozon', 55);

        $this->assertNull($result);
    }

    public function test_yandex_family_matches_both_variants(): void
    {
        $syncLog = SyncLog::create([
            'marketplace' => 'yandex',
            'integration_id' => 10,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
        ]);

        $result = SyncStartGuard::findActiveDuplicate('products', 'yandex_market', 10);

        $this->assertNotNull($result);
        $this->assertSame($syncLog->id, $result->id);
    }
}
