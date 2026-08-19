<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `unique(session_id, plan_hash)` dat o migration 120000 la nham lan giua DAU VAN
 * TAY va DANH TINH. Hash dung de tra loi "ban nay co khac ban truoc khong"; cam
 * hai ban trung hash tuc la cam he thong ghi lai su that "dung lai lan nua ra
 * ket qua y het" — ma do chinh la thu dang muon biet.
 *
 * Bat duoc bang test that: recompose mot session voi cung kich ban nem 1062.
 * Khoa that su la unique(session_id, revision), van con nguyen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_render_plans', function (Blueprint $table) {
            $table->dropUnique('video_render_plans_session_id_plan_hash_unique');
            $table->index(['session_id', 'plan_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('video_render_plans', function (Blueprint $table) {
            $table->dropIndex(['session_id', 'plan_hash']);
            $table->unique(['session_id', 'plan_hash']);
        });
    }
};
