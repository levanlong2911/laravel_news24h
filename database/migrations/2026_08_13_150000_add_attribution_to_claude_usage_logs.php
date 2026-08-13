<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claude_usage_logs', function (Blueprint $table) {
            $table->uuid('article_id')->nullable()->after('admin_id');
            $table->uuid('video_session_id')->nullable()->after('article_id');

            $table->foreign('article_id')->references('id')->on('articles')->nullOnDelete();
            $table->foreign('video_session_id')->references('id')->on('video_sessions')->nullOnDelete();
            $table->index(['video_session_id']);
        });
    }

    public function down(): void
    {
        Schema::table('claude_usage_logs', function (Blueprint $table) {
            $table->dropForeign(['article_id']);
            $table->dropForeign(['video_session_id']);
            $table->dropColumn(['article_id', 'video_session_id']);
        });
    }
};
