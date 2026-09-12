<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE video_render_scenes MODIFY render_plan_id CHAR(36) NULL');

        Schema::table('video_render_scenes', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->after('render_plan_id');
            $table->unsignedInteger('revision')->nullable()->after('project_id');

            $table->text('delta_prompt')->nullable()->after('state_json');
            $table->string('prompt_version', 40)->nullable()->after('delta_prompt');
            $table->json('references_json')->nullable()->after('prompt_version');
            $table->char('reference_manifest_hash', 64)->nullable()->after('references_json');
            $table->uuid('design_image_id')->nullable()->after('reference_manifest_hash');

            $table->unique(['project_id', 'revision', 'scene_code'], 'video_render_scenes_project_scene_unique');
            $table->index(['project_id', 'revision', 'scene_index'], 'video_render_scenes_project_order_index');

            $table->foreign('project_id')->references('id')->on('video_projects')->cascadeOnDelete();
            $table->foreign('design_image_id')->references('id')->on('video_design_images')->nullOnDelete();
        });

        DB::statement('
            ALTER TABLE video_render_scenes
            ADD CONSTRAINT video_render_scenes_scope_ck
            CHECK ((project_id IS NULL) <> (render_plan_id IS NULL))
        ');

        DB::statement('
            ALTER TABLE video_render_scenes
            ADD CONSTRAINT video_render_scenes_project_payload_ck
            CHECK (
                project_id IS NULL
                OR (
                    revision IS NOT NULL
                    AND delta_prompt IS NOT NULL
                    AND prompt_version IS NOT NULL
                )
            )
        ');
    }

    public function down(): void
    {
        if (DB::table('video_render_scenes')->whereNull('render_plan_id')->exists()) {
            throw new RuntimeException(
                'video_render_scenes con hang gan truc tiep vao project. Rollback se xoa chung.'
            );
        }

        DB::statement('ALTER TABLE video_render_scenes DROP CONSTRAINT video_render_scenes_project_payload_ck');
        DB::statement('ALTER TABLE video_render_scenes DROP CONSTRAINT video_render_scenes_scope_ck');

        Schema::table('video_render_scenes', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['design_image_id']);
            $table->dropUnique('video_render_scenes_project_scene_unique');
            $table->dropIndex('video_render_scenes_project_order_index');

            $table->dropColumn([
                'project_id', 'revision', 'delta_prompt', 'prompt_version',
                'references_json', 'reference_manifest_hash', 'design_image_id',
            ]);
        });

        DB::statement('ALTER TABLE video_render_scenes MODIFY render_plan_id CHAR(36) NOT NULL');
    }
};
