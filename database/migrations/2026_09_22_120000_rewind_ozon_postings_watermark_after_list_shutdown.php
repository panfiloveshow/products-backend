<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 31.08.2026 Ozon отключил /v3/posting/fbs/list и /v2/posting/fbo/list. Ошибка API
 * читалась как пустая страница, и водяной знак постингов уезжал вперёд, а окно
 * 31.08 → сейчас осталось пустым. Откатываем знак на 25.08, чтобы следующий синк
 * (уже через /v4 FBS и /v3 FBO) добрал пропуск. upsert идемпотентен.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('integrations')
            ->where('marketplace', 'ozon')
            ->where('ozon_postings_synced_until', '>', '2026-08-25 00:00:00')
            ->update(['ozon_postings_synced_until' => '2026-08-25 00:00:00']);
    }

    public function down(): void
    {
        // Откат знака не отменяется: следующий синк просто продвинет его сам.
    }
};
