<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// L12 Analytics Feedback Loop -- one row per platform per video_job per day.
// Ingested by VideoAnalyticsController::store() called from n8n/Make webhook
// after pulling YouTube/Facebook/TikTok stats. Feeds back to L1 Planner via
// viral_score updates and CTR history the StoryPlannerService can query.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_analytics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_job_id');
            $table->string('platform', 20);           // youtube|facebook|tiktok|instagram
            $table->date('date');
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('watch_time_seconds')->default(0);
            $table->float('avg_view_duration')->default(0);  // seconds
            $table->float('retention_rate')->default(0);     // 0–1
            $table->float('ctr')->default(0);                // 0–1 (impressions→clicks)
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->unsignedInteger('shares')->default(0);
            $table->unsignedInteger('saves')->default(0);
            $table->json('raw_payload')->nullable();         // original API response
            $table->timestamps();

            $table->foreign('video_job_id')->references('id')->on('video_jobs')->cascadeOnDelete();
            $table->unique(['video_job_id', 'platform', 'date']);
            $table->index(['platform', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_analytics');
    }
};
