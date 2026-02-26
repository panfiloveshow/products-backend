<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wb_webhook_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->unique();
            $table->string('webhook_url', 500);
            $table->string('secret_key', 64);
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_webhook_configs');
    }
};
