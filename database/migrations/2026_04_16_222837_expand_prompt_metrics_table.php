<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_metrics', function (Blueprint $table) {
            // Hook Engine signals
            $table->unsignedSmallInteger('hook_score')->default(0)->after('content_type_detected');
            $table->unsignedTinyInteger('hook_rank')->default(1)->after('hook_score');
            $table->unsignedTinyInteger('hook_candidates')->default(0)->after('hook_rank');

            // PostGuard signals
            $table->float('guard_confidence')->default(0)->after('hook_candidates');
            $table->string('final_reason', 30)->default('ok')->after('guard_confidence');

            // Retry signals
            $table->unsignedTinyInteger('retry_count')->default(0)->after('final_reason');
            $table->string('retry_reason', 30)->nullable()->after('retry_count');

            // Prompt identity
            $table->string('schema_version', 8)->nullable()->after('retry_reason');
            $table->string('prompt_fingerprint', 12)->nullable()->after('schema_version');

            // Content quality
            $table->unsignedSmallInteger('word_count')->default(0)->after('viral_score');
            $table->unsignedInteger('processing_time_ms')->default(0)->after('word_count');
            $table->boolean('needs_review')->default(false)->after('processing_time_ms');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'hook_score', 'hook_rank', 'hook_candidates',
                'guard_confidence', 'final_reason',
                'retry_count', 'retry_reason',
                'schema_version', 'prompt_fingerprint',
                'word_count', 'processing_time_ms', 'needs_review',
            ]);
        });
    }
};
