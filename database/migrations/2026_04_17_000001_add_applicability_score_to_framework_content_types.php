<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('framework_content_types', function (Blueprint $table) {
            // Weighted detection: keyword_matches × applicability_score
            // 1.0 = fully applicable, 0.5 = partial, 0.0 = disabled via is_active
            $table->float('applicability_score')->default(1.0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('framework_content_types', function (Blueprint $table) {
            $table->dropColumn('applicability_score');
        });
    }
};
