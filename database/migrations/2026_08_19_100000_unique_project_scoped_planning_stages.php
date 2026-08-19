<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->unique(
                ['project_id', 'planning_revision', 'stage'],
                'video_planning_stages_project_revision_stage_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->dropUnique('video_planning_stages_project_revision_stage_unique');
        });
    }
};
