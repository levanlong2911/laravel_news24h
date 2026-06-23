<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Two gaps found when reviewing whether the seeded video prompts could
// actually produce a usable video: (1) no fixed art style per category, so
// Claude picked whatever style it felt like per scene (and per topic) instead
// of one tied to that category's intended look; (2) no mechanism to keep the
// main subject's visual appearance consistent across scenes/parts of the same
// article's video series. art_style closes #1 (configured once per category,
// like domain/audience/tone_notes already are). visual_anchor closes #2 --
// Story Planner writes it once per article, Script Generator's per-part calls
// all reuse the same anchor text instead of each part inventing its own.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('category_contexts', function (Blueprint $table) {
            $table->text('art_style')->nullable()->after('hook_style');
        });

        Schema::table('story_plans', function (Blueprint $table) {
            $table->text('visual_anchor')->nullable()->after('mood');
        });
    }

    public function down(): void
    {
        Schema::table('category_contexts', function (Blueprint $table) {
            $table->dropColumn('art_style');
        });

        Schema::table('story_plans', function (Blueprint $table) {
            $table->dropColumn('visual_anchor');
        });
    }
};
