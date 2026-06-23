<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Командный канал моста Uzum: Sellico ставит команду «дёрни эндпоинт кабинета»,
 * расширение опрашивает pending, выполняет с Bearer кабинета и пишет ответ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uzum_extension_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('method', 8)->default('GET');
            $table->text('path'); // /api/seller/shop/93581/product?productId=X
            $table->json('body')->nullable();
            $table->string('status', 16)->default('pending'); // pending|done|error
            $table->integer('http_status')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['integration_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uzum_extension_commands');
    }
};
