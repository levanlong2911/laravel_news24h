<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_render_scenes', function (Blueprint $table) {
            $table->string('transition_mode', 24)->nullable()->after('prompt_version');
        });

        DB::statement('ALTER TABLE video_render_scenes DROP CONSTRAINT video_render_scenes_project_payload_ck');

        DB::statement("
            ALTER TABLE video_render_scenes
            ADD CONSTRAINT video_render_scenes_project_payload_ck
            CHECK (
                project_id IS NULL
                OR (
                    revision IS NOT NULL
                    AND delta_prompt IS NOT NULL
                    AND prompt_version IS NOT NULL
                    AND transition_mode IS NOT NULL
                    AND transition_mode IN ('continuation_edit', 'hard_cut_edit')
                )
            )
        ");
    }

    public function down(): void
    {
        if (DB::table('video_render_scenes')->whereNotNull('project_id')->exists()) {
            throw new RuntimeException(
                'video_render_scenes con hang project-scoped. Rollback se xoa transition_mode cua chung.'
            );
        }

        DB::statement('ALTER TABLE video_render_scenes DROP CONSTRAINT video_render_scenes_project_payload_ck');

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

        Schema::table('video_render_scenes', function (Blueprint $table) {
            $table->dropColumn('transition_mode');
        });
    }
};
