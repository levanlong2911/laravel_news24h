<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_renders', function (Blueprint $table) {
            $table->char('design_image_id', 36)->nullable()->after('shot_id');
            $table->foreign('design_image_id')->references('id')->on('video_design_images')->nullOnDelete();

            // Hai unique cu deu khoa tren `shot_id`. Khi `shot_id` duoc phep NULL
            // thi MariaDB cho lap vo han trong unique chua NULL — nghia la render
            // cua design image mat sach chong-trung. Phai co cap unique rieng, neu
            // khong thi mot lan bam hai lan se tra tien hai lan.
            $table->unique(['design_image_id', 'attempt_no'], 'video_renders_design_image_attempt_unique');
            $table->unique(['design_image_id', 'idempotency_key'], 'video_renders_design_image_idempotency_unique');
        });

        DB::statement('ALTER TABLE video_renders MODIFY shot_id CHAR(36) NULL');
        DB::statement('ALTER TABLE video_renders ADD CONSTRAINT video_renders_one_owner
            CHECK ((shot_id IS NULL) <> (design_image_id IS NULL))');
    }

    public function down(): void
    {
        // Mot dong so cai la mot khoan tien DA TIEU. Rollback khong duoc phep xoa
        // no de lay lai NOT NULL — dung lai va de nguoi quyet dinh.
        $orphans = DB::table('video_renders')->whereNull('shot_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(sprintf(
                'video_renders con %d dong render cua design image (shot_id NULL). '
                .'Rollback se phai xoa lich su chi tieu — hay xu ly tay truoc.',
                $orphans,
            ));
        }

        DB::statement('ALTER TABLE video_renders DROP CONSTRAINT video_renders_one_owner');
        DB::statement('ALTER TABLE video_renders MODIFY shot_id CHAR(36) NOT NULL');

        Schema::table('video_renders', function (Blueprint $table) {
            $table->dropUnique('video_renders_design_image_idempotency_unique');
            $table->dropUnique('video_renders_design_image_attempt_unique');
            $table->dropForeign(['design_image_id']);
            $table->dropColumn('design_image_id');
        });
    }
};
