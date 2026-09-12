<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ba cot deu nullable va KHONG vao CHECK payload: revision 1 da co hang song
 * truoc khi hop dong v2 ra doi. Validator v2 chiu trach nhiem bat buoc chung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_render_scenes', function (Blueprint $table) {
            $table->json('milestone_keys')->nullable()->after('scene_type');
            $table->string('basis', 24)->nullable()->after('milestone_keys');
            $table->json('video_plan_json')->nullable()->after('references_json');
        });
    }

    public function down(): void
    {
        $live = DB::table('video_render_scenes')
            ->whereNotNull('milestone_keys')
            ->orWhereNotNull('basis')
            ->orWhereNotNull('video_plan_json')
            ->exists();

        if ($live) {
            throw new RuntimeException(
                'video_render_scenes con hang planning v2. Rollback se xoa milestone_keys, basis va video_plan_json cua chung.'
            );
        }

        Schema::table('video_render_scenes', function (Blueprint $table) {
            $table->dropColumn(['milestone_keys', 'basis', 'video_plan_json']);
        });
    }
};
