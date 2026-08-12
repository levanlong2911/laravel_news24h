<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `plan_json` chốt LÚC BẮT ĐẦU compose: danh sách clip (render_id, path,
 * duration_ms, sequence_no) đã dùng để ghép. Đọc lại đúng cột này khi ghi
 * `video_final_renders`, KHÔNG truy vấn lại video_shots lúc nhận kết quả — một
 * shot bị duyệt render lại ngay lúc FFmpeg đang chạy (6-18 phút) không được
 * làm lệch provenance của bản final đã ghép xong. Cùng triết lý "sự kiện lịch
 * sử không viết lại" đã áp dụng cho video_renders (2026-08-07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_finals', function (Blueprint $table) {
            $table->json('plan_json')->nullable()->after('cost_total');
        });
    }

    public function down(): void
    {
        Schema::table('video_finals', function (Blueprint $table) {
            $table->dropColumn('plan_json');
        });
    }
};
