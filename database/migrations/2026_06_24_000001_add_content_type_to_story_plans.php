<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// content_type tells the Python renderer which rendering pipeline to use:
//   'informational' -- talking-head / Ken Burns style (default)
//   'visual'        -- scene-heavy, cinematic (yachts, travel, luxury, construction)
// Determined by StoryPlannerService at plan-creation time from the generated
// narrative_arc + category domain, so Python never has to guess.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_plans', function (Blueprint $table) {
            $table->string('content_type', 20)->default('informational')->after('mood');
        });

        // Backfill existing rows using the same keyword logic StoryPlannerService
        // will use for new plans -- narrative_arc is the most reliable signal
        // because it's already written in terms of what the video is about.
        DB::statement("
            UPDATE story_plans SET content_type = 'visual'
            WHERE narrative_arc LIKE '%yacht%'
               OR narrative_arc LIKE '%construction%'
               OR narrative_arc LIKE '%travel%'
               OR narrative_arc LIKE '%luxury%'
        ");
    }

    public function down(): void
    {
        Schema::table('story_plans', function (Blueprint $table) {
            $table->dropColumn('content_type');
        });
    }
};
