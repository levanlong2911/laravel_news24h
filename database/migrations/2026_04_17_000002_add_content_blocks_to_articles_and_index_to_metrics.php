<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Structured article rendering blocks (hook/paragraph/stat/quote/kicker)
        Schema::table('articles', function (Blueprint $table) {
            $table->json('content_blocks')->nullable()->after('content');
        });

        // Composite index for summaryByType() GROUP BY queries
        Schema::table('prompt_metrics', function (Blueprint $table) {
            $table->index(['context_id', 'content_type_detected'], 'metrics_context_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('content_blocks');
        });

        Schema::table('prompt_metrics', function (Blueprint $table) {
            $table->dropIndex('metrics_context_type_idx');
        });
    }
};
