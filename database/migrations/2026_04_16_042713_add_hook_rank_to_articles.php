<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // hook_rank: vị trí 1-based của bestHook trong candidates list
    // rank = 1 → model generate tốt ngay từ đầu
    // rank = 4-5 → scoring đang "cứu" output (signal để cải thiện prompt)
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->unsignedTinyInteger('hook_rank')->default(0)->after('hook_score');
            // 0 = fallback (no candidates), 1-5 = vị trí trong 5 candidates
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('hook_rank');
        });
    }
};
