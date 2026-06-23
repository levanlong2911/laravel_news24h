<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Prevents the race where two people (or a person + the 15-minute cron) pick
// the SAME article for video generation at the same moment: both would pass
// the "no ArticleFact/StoryPlan yet" check, both call Claude, and the second
// INSERT would hit the article_facts.article_id / story_plans.article_id
// unique constraint -- wasted Claude cost and a confusing failure log instead
// of a clean "already being processed" message.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->timestamp('video_processing_started_at')->nullable()->after('video_failure_count');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('video_processing_started_at');
        });
    }
};
