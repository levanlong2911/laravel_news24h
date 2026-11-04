<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->longText('request_snapshot')->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->nullable()->after('input_hash');
        });
    }

    public function down(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->dropColumn('request_snapshot');
        });
    }
};
