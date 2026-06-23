<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets the same prompt_frameworks table serve two purposes: the existing
// article-writing frameworks (purpose=article, the default -- preserves all
// existing rows untouched) and new video-pipeline frameworks (purpose=video:
// phase1_analyze=Fact Extractor, phase2_diagnose=Story Planner,
// phase3_generate=Script Generator). CategoryContext gains a second,
// independent FK (video_framework_id) so one category can resolve a
// different framework per purpose while still sharing the same
// domain/audience/terminology/tone_notes/hook_style context columns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_frameworks', function (Blueprint $table) {
            $table->enum('purpose', ['article', 'video'])->default('article')->after('name');
        });

        Schema::table('category_contexts', function (Blueprint $table) {
            $table->uuid('video_framework_id')->nullable()->after('framework_id');
            $table->foreign('video_framework_id')->references('id')->on('prompt_frameworks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('category_contexts', function (Blueprint $table) {
            $table->dropForeign(['video_framework_id']);
            $table->dropColumn('video_framework_id');
        });

        Schema::table('prompt_frameworks', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
