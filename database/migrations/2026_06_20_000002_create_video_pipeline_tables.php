<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per article -- Fact Extractor output, shared by both Phase 1
        // (short-form parts) and Phase 2 (long-form) for the same article, so
        // fact-checking cost is never paid twice for the same subject matter.
        Schema::create('article_facts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('article_id');
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->json('facts_json');                 // [{id, statement, category, visual_relevance, source_excerpt}]
            $table->enum('confidence', ['low', 'medium', 'high']);
            $table->boolean('escalated_to_sonnet')->default(false); // true if Haiku confidence was low/medium and Sonnet re-ran
            $table->json('entities_json')->nullable();   // {people, places, objects, time_periods}
            $table->timestamps();

            $table->unique('article_id'); // one fact-extraction per article, ever
        });

        // One row per article -- Story Planner output. total_parts/parts_outline
        // drive the cliffhanger series; Phase 2 reuses the same row's facts but
        // would get a separate, longer-form outline (not modeled here yet).
        Schema::create('story_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('article_id');
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->string('hook', 500);                  // taken from article.fb_image_text (or title fallback), not re-invented
            $table->text('narrative_arc');
            $table->string('mood', 30);                    // drives Music Generator's mood-tagged track selection
            $table->unsignedTinyInteger('total_parts');
            $table->json('parts_outline_json');             // [{part_number, beat, cliffhanger_question, is_final_part, cta}]
            $table->timestamps();

            $table->unique('article_id');
        });

        // One row per Part -- Script Generator output + the Python pipeline's
        // job-tracking lifecycle. This IS the "video job" Python claims/renders.
        Schema::create('video_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('story_plan_id');
            $table->foreign('story_plan_id')->references('id')->on('story_plans')->cascadeOnDelete();
            $table->unsignedTinyInteger('part_number');
            $table->enum('status', [
                'script_ready', 'claimed', 'rendering',
                'quality_check_passed', 'quality_check_failed',
                'uploaded', 'upload_failed',
            ])->default('script_ready');
            $table->json('script_json');                  // {hook, target_seconds, scenes: [{scene_id, beat, narration, visual_description, image_prompt, fact_refs}]}
            $table->string('claimed_by', 100)->nullable(); // worker identifier, not a user
            $table->timestamp('claimed_at')->nullable();
            $table->decimal('cost_total', 10, 6)->default(0); // Python-side spend, reported back via /status
            $table->string('video_path', 500)->nullable();    // Laravel's own stored copy, after asset push-back
            $table->string('thumbnail_path', 500)->nullable();
            $table->string('youtube_video_id', 50)->nullable();
            $table->string('facebook_post_id', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['story_plan_id', 'part_number']);
            $table->index('status'); // the claim endpoint's main query: WHERE status = 'script_ready'
        });

        // Generic Claude-call cost log for the video pipeline only -- deliberately
        // NOT reusing prompt_metrics/PromptMetric, whose schema (hook_score,
        // hook_rank, guard_confidence, schema_version, prompt_fingerprint, ...)
        // is specific to the article-hook pipeline and doesn't apply to Fact
        // Extractor/Story Planner/Script Generator. ClaudeWriterService::costUsd()
        // is still reused for the cost math itself.
        Schema::create('video_claude_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('article_id');
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->string('stage', 30); // fact_extractor | story_planner | script_generator
            $table->string('model_used', 30);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['article_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_claude_calls');
        Schema::dropIfExists('video_jobs');
        Schema::dropIfExists('story_plans');
        Schema::dropIfExists('article_facts');
    }
};
