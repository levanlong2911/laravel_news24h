<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "cell" la tu long cua rieng du an — doc luoc do khong doan duoc bang chua gi,
 * trong khi video_shots / video_finals thi doan duoc ngay.
 *
 * Truc phan biet that KHONG phai anh-hay-video ma la PHAM VI: bang nay thuoc
 * DU AN va song qua moi luot, con video_shots thuoc mot luot dung video.
 *
 * Khoa JSON cua API GIU NGUYEN (`design_cells`, `cell_code`, `source_cell_code`)
 * — listDesignCellsForSession() anh xa ten luc tra ve, nen doi ten cot khong
 * buoc phai sua repo Python.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Go FK truoc khi doi ten: MySQL giu rang buoc theo ten bang, doi ten
        // xong ma con FK tro toi ten cu la 1553.
        Schema::table('video_artifacts', function (Blueprint $table) {
            $table->dropForeign(['design_cell_id']);
        });

        Schema::table('video_design_cells', function (Blueprint $table) {
            $table->dropForeign(['source_cell_id']);
        });

        Schema::rename('video_design_cells', 'video_design_images');

        DB::statement('ALTER TABLE video_design_images CHANGE cell_code image_code VARCHAR(60) NOT NULL');
        DB::statement('ALTER TABLE video_design_images CHANGE cell_type image_type VARCHAR(40) NOT NULL');
        DB::statement('ALTER TABLE video_design_images CHANGE source_cell_id source_image_id CHAR(36) NULL');
        DB::statement('ALTER TABLE video_artifacts CHANGE design_cell_id design_image_id CHAR(36) NULL');

        Schema::table('video_design_images', function (Blueprint $table) {
            $table->foreign('source_image_id')->references('id')->on('video_design_images')->nullOnDelete();
        });

        Schema::table('video_artifacts', function (Blueprint $table) {
            $table->foreign('design_image_id')->references('id')->on('video_design_images')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_artifacts', function (Blueprint $table) {
            $table->dropForeign(['design_image_id']);
        });

        Schema::table('video_design_images', function (Blueprint $table) {
            $table->dropForeign(['source_image_id']);
        });

        DB::statement('ALTER TABLE video_artifacts CHANGE design_image_id design_cell_id CHAR(36) NULL');
        DB::statement('ALTER TABLE video_design_images CHANGE source_image_id source_cell_id CHAR(36) NULL');
        DB::statement('ALTER TABLE video_design_images CHANGE image_type cell_type VARCHAR(40) NOT NULL');
        DB::statement('ALTER TABLE video_design_images CHANGE image_code cell_code VARCHAR(60) NOT NULL');

        Schema::rename('video_design_images', 'video_design_cells');

        Schema::table('video_design_cells', function (Blueprint $table) {
            $table->foreign('source_cell_id')->references('id')->on('video_design_cells')->nullOnDelete();
        });

        Schema::table('video_artifacts', function (Blueprint $table) {
            $table->foreign('design_cell_id')->references('id')->on('video_design_cells')->nullOnDelete();
        });
    }
};
