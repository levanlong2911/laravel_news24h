<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_projects', function (Blueprint $table): void {
            $table->uuid('selected_screenplay_stage_id')->nullable()->after('active_session_id');
            $table->uuid('selected_scene_plan_stage_id')->nullable()->after('selected_screenplay_stage_id');
            $table->unsignedInteger('production_selection_version')->default(0)
                ->after('selected_scene_plan_stage_id');

            $table->foreign('selected_screenplay_stage_id', 'video_projects_selected_screenplay_fk')
                ->references('id')->on('video_planning_stages')->nullOnDelete();
            $table->foreign('selected_scene_plan_stage_id', 'video_projects_selected_scene_plan_fk')
                ->references('id')->on('video_planning_stages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', function (Blueprint $table): void {
            $table->dropForeign('video_projects_selected_screenplay_fk');
            $table->dropForeign('video_projects_selected_scene_plan_fk');
            $table->dropColumn([
                'selected_screenplay_stage_id',
                'selected_scene_plan_stage_id',
                'production_selection_version',
            ]);
        });
    }
};
