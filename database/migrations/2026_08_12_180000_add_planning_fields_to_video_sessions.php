<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §18.30 — pipeline Claude (creatVideoById) chuyển sang chạy nền qua tiến
 * trình Artisan tách rời (`video:build-plan --session=`), không còn nằm trong
 * request HTTP.
 *
 * `requested_by_admin_id`: ghi lúc TẠO session (trong request HTTP, nơi
 * auth()->id() đáng tin), để tiến trình nền tự đọc lại admin và re-auth —
 * KHÔNG nhận admin id qua tham số dòng lệnh (script vẫn chạy tay được, ai
 * cũng gõ --admin= tuỳ ý được nếu cho phép).
 *
 * KHÔNG đặt khoá ngoại: `nullOnDelete()` sẽ tự xoá đúng giá trị cần kiểm
 * NGAY LÚC admin bị xoá — thử thật thấy `runVideoPlanningPipeline()` không
 * còn phân biệt được "admin đã xoá" với "chưa từng có admin", vô hiệu hoá
 * chính guard "admin không tồn tại thì dừng" mà tính năng này cần. Toàn vẹn
 * tham chiếu do tầng ứng dụng tự kiểm (`Admin::find()`), không phải DB.
 *
 * `error_message`: tiến trình nền không còn response HTTP nào để
 * back()->with('error', ...) — lỗi phải nằm lại trên chính session để đọc
 * lại lúc F5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->uuid('requested_by_admin_id')->nullable()->after('article_id');
            $table->text('error_message')->nullable()->after('cost_actual');
        });
    }

    public function down(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->dropColumn(['requested_by_admin_id', 'error_message']);
        });
    }
};
