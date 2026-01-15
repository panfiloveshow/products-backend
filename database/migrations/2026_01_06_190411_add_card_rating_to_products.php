<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет рейтинг карточки (качество заполнения) — аналог WB "Рейтинг карточки 10/10"
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('card_rating', 3, 1)->nullable()->after('reviews_count')
                ->comment('Рейтинг карточки (качество заполнения) 0-10');
            $table->json('card_rating_details')->nullable()->after('card_rating')
                ->comment('Детали рейтинга: title, description, photos, characteristics');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['card_rating', 'card_rating_details']);
        });
    }
};
