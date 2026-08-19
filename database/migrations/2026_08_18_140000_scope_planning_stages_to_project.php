<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SỬA LỖI CHẶN: `video_planning_stages.session_id` đang NOT NULL, nhưng chặng
 * `concept` sinh ra `design_identity` — thứ thuộc về DỰ ÁN, không thuộc lượt chạy.
 *
 * Để nguyên thì hỏng theo hai đường, và đường thứ hai tệ hơn:
 *   (a) Bấm 🎬 tạo dự án xong không lấy được prompt ảnh neo, vì concept đòi session.
 *   (b) Mỗi session tự chạy concept lại → danh tính khác → prompt ảnh neo khác →
 *       prompt_sha256 khác → UNIQUE(project_id, prompt_sha256) KHÔNG BAO GIỜ khớp.
 *       Cơ chế chống lặp vừa dựng thành trang trí.
 *
 * Ranh giới mới có nghĩa thật: cùng một con tàu, nhiều video khác nhau —
 * danh tính cố định, mạch truyện thay đổi.
 *
 *   inspiration · concept  → project   chạy 1 lần / dự án
 *   creative_arc           → session   chạy mỗi lượt
 *
 * `video_projects` cũng nhận ba cột: `article_id` (thay `subject_id` — cột đó tên
 * gây hiểu nhầm vì nó đang chứa article id), `admin_id`, `design_id`. Cột cũ GIỮ
 * NGUYÊN ở bước này; xoá nó là việc của lần sửa code đọc nó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->after('id');
            $table->foreign('project_id')->references('id')->on('video_projects')->cascadeOnDelete();
        });

        // Hai câu riêng: đổi NOT NULL → NULL phải qua SQL thô vì Laravel cần
        // doctrine/dbal cho `->change()`, mà repo không cài gói đó.
        DB::statement('ALTER TABLE video_planning_stages MODIFY session_id CHAR(36) NULL');

        // Đúng MỘT trong hai khoá được đặt. MariaDB 10.2+ thi hành CHECK thật,
        // không bỏ qua như MySQL 5.7.
        DB::statement(
            'ALTER TABLE video_planning_stages
             ADD CONSTRAINT chk_planning_stage_scope
             CHECK ((project_id IS NULL) <> (session_id IS NULL))',
        );

        Schema::table('video_projects', function (Blueprint $table) {
            $table->uuid('article_id')->nullable()->after('id');
            $table->uuid('admin_id')->nullable()->after('article_id');
            $table->char('design_id', 64)->nullable()->after('design_ref');  // sha256 khớp brief.json bên Python

            $table->foreign('article_id')->references('id')->on('articles')->nullOnDelete();
            $table->foreign('admin_id')->references('id')->on('admins')->nullOnDelete();
            $table->index('article_id');
        });

        // `subject_id` đang chứa article id — chép sang cột đúng tên, giữ cột cũ
        // cho tới khi code thôi đọc nó.
        DB::statement(
            'UPDATE video_projects p
             JOIN articles a ON a.id = p.subject_id
             SET p.article_id = p.subject_id',
        );
    }

    public function down(): void
    {
        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropForeign(['article_id']);
            $table->dropForeign(['admin_id']);
            $table->dropIndex(['article_id']);
            $table->dropColumn(['article_id', 'admin_id', 'design_id']);
        });

        DB::statement('ALTER TABLE video_planning_stages DROP CONSTRAINT chk_planning_stage_scope');

        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });

        // Hàng nào đang thuộc project sẽ chặn câu này — đúng ý: không được âm
        // thầm vứt chúng đi để quay lui cho gọn.
        DB::statement('ALTER TABLE video_planning_stages MODIFY session_id CHAR(36) NOT NULL');
    }
};
