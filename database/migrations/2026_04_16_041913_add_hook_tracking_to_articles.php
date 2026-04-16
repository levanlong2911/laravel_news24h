<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Fix 3: hook quality tracking — phục vụ auto-optimize + A/B test sau này
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // type_code Phase 2 detect được (victory, defeat, injury...)
            $table->string('hook_type', 30)->nullable()->after('human_review');

            // Virality score của hook được chọn (0–15+) — so sánh cross-article
            $table->unsignedSmallInteger('hook_score')->default(0)->after('hook_type');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['hook_type', 'hook_score']);
        });
    }
};
