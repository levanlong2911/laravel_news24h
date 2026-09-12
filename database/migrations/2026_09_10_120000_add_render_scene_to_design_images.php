<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_design_images', function (Blueprint $table) {
            $table->uuid('render_scene_id')->nullable()->after('identity_id');

            $table->foreign('render_scene_id')
                ->references('id')->on('video_render_scenes')
                ->cascadeOnDelete();

            $table->index(['project_id', 'render_scene_id'], 'video_design_images_scene_index');
        });
    }

    public function down(): void
    {
        if (DB::table('video_design_images')->whereNotNull('render_scene_id')->exists()) {
            throw new RuntimeException(
                'video_design_images con anh gan vao scene. Rollback se cat lien ket cua chung.'
            );
        }

        Schema::table('video_design_images', function (Blueprint $table) {
            $table->dropForeign(['render_scene_id']);
            $table->dropIndex('video_design_images_scene_index');
            $table->dropColumn('render_scene_id');
        });
    }
};
