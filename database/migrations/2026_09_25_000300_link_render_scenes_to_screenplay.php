<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_render_scenes', function (Blueprint $table): void {
            $table->uuid('screenplay_stage_id')->nullable()->after('project_id');
            $table->string('screenplay_scene_code', 60)->nullable()->after('screenplay_stage_id');
            $table->char('screenplay_hash', 64)->nullable()->after('screenplay_scene_code');
            $table->unsignedTinyInteger('shot_index')->nullable()->after('scene_index');

            $table->foreign('screenplay_stage_id', 'render_scenes_screenplay_stage_fk')
                ->references('id')->on('video_planning_stages')->nullOnDelete();
            $table->index(
                ['screenplay_stage_id', 'screenplay_scene_code', 'shot_index'],
                'render_scenes_screenplay_shot_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('video_render_scenes', function (Blueprint $table): void {
            $table->dropForeign('render_scenes_screenplay_stage_fk');
            $table->dropIndex('render_scenes_screenplay_shot_idx');
            $table->dropColumn([
                'screenplay_stage_id',
                'screenplay_scene_code',
                'screenplay_hash',
                'shot_index',
            ]);
        });
    }
};
