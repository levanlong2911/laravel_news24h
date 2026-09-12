<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ba cot nullable va KHONG vao CHECK payload: revision 1-5 co truoc hop dong
 * nhom canh. Validator chiu trach nhiem bat buoc chung cho revision moi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_render_scenes', function (Blueprint $table) {
            $table->string('continuity_group', 60)->nullable()->after('transition_mode');
            $table->string('source_scene_code', 60)->nullable()->after('continuity_group');
            $table->string('camera_change_reason', 300)->nullable()->after('source_scene_code');
        });
    }

    public function down(): void
    {
        $live = DB::table('video_render_scenes')
            ->whereNotNull('continuity_group')
            ->orWhereNotNull('source_scene_code')
            ->orWhereNotNull('camera_change_reason')
            ->exists();

        if ($live) {
            throw new RuntimeException(
                'video_render_scenes con hang mang nhom canh. Rollback se xoa continuity_group, source_scene_code va camera_change_reason cua chung.'
            );
        }

        Schema::table('video_render_scenes', function (Blueprint $table) {
            $table->dropColumn(['continuity_group', 'source_scene_code', 'camera_change_reason']);
        });
    }
};
