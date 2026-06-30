<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uzum_extension_snapshots', function (Blueprint $table) {
            $table->string('payload_hash', 64)->nullable()->after('payload_type');
            $table->unique(
                ['integration_id', 'payload_type', 'payload_hash'],
                'uzum_ext_snapshots_payload_hash_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('uzum_extension_snapshots', function (Blueprint $table) {
            $table->dropUnique('uzum_ext_snapshots_payload_hash_unique');
            $table->dropColumn('payload_hash');
        });
    }
};
