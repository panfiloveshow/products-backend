<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uzum_extension_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('shop_id', 100)->nullable();

            // products | moderation | stocks | orders | finance | ads
            $table->string('payload_type', 30)->index();

            // ponytail: одна таблица = snapshot (raw_payload) + sync-лог (счётчики/статус).
            $table->json('raw_payload');           // собранные items как есть
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);

            $table->string('status', 20)->default('ok'); // ok | partial | error
            $table->text('error_message')->nullable();

            $table->string('extension_version', 30)->nullable();
            $table->string('extractor_version', 30)->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'payload_type', 'collected_at'], 'uzum_ext_snap_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uzum_extension_snapshots');
    }
};
