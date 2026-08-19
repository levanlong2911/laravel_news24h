<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `video_projects` là bảng DUY NHẤT chưa được đưa về hình dạng lược đồ mới —
 * migration 140000 chỉ thêm 3 cột khoá, còn 150000 mở rộng sessions/shots/
 * renders/finals mà bỏ sót bảng này.
 *
 * KHÔNG thêm cột `status`. Trạng thái đã sống ở `video_sessions.status`; một bản
 * sao ở cấp dự án sẽ cần code duy trì, và thứ được duy trì bằng tay thì sẽ lệch.
 * Muốn biết dự án đang ở đâu thì suy từ lượt mới nhất — luôn khớp thực tế.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `name` → `title`: cùng nghĩa, khác tên. ĐỔI TÊN chứ không thêm cột thứ
        // hai — hai cột cùng nghĩa là mời chúng lệch nhau. Nới 120 → 255 vì tiêu
        // đề bài viết đang bị cắt: `findOrCreateByArticle()` phải Str::limit(110)
        // mới nhét vừa, tức tên dự án dài hơn 110 ký tự là mất phần đuôi.
        DB::statement('ALTER TABLE video_projects CHANGE name title VARCHAR(255) NOT NULL');

        Schema::table('video_projects', function (Blueprint $table) {
            $table->string('project_type', 50)->default('article_video')->after('title');

            // Con trỏ tới lượt ĐANG chạy — trả lời "dự án này đang chạy lượt nào"
            // mà không phải quét cả bảng session và tự đoán cái nào là mới nhất.
            $table->uuid('active_session_id')->nullable()->after('project_type');

            $table->json('metadata_json')->nullable();
            $table->softDeletes();

            $table->foreign('active_session_id')->references('id')->on('video_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropForeign(['active_session_id']);
            $table->dropSoftDeletes();
            $table->dropColumn(['project_type', 'active_session_id', 'metadata_json']);
        });

        // Cắt về 120 có thể mất đuôi của tên dài — chấp nhận, vì đó đúng là hình
        // dạng cũ và người lùi migration đang cố ý quay về đó.
        DB::statement('ALTER TABLE video_projects CHANGE title name VARCHAR(120) NOT NULL');
    }
};
