<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fixes the video pipeline starvation bug: without this, an article that
// permanently fails (missing video framework, or repeated unparseable Claude
// output) keeps matching ProcessVideoArticles' whereDoesntHave query forever
// and re-fills every 15-minute batch, starving out articles that could
// actually succeed once enough permanently-broken articles accumulate.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->timestamp('video_skipped_at')->nullable()->after('published_at');
            $table->string('video_skip_reason', 255)->nullable()->after('video_skipped_at');
            $table->unsignedTinyInteger('video_failure_count')->default(0)->after('video_skip_reason');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['video_skipped_at', 'video_skip_reason', 'video_failure_count']);
        });
    }
};
