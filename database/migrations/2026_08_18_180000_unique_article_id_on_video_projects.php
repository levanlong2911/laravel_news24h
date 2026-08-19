<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "1 bài viết = 1 dự án" đang chỉ là quy ước ở tầng code: `firstOrCreate()` là
 * SELECT rồi INSERT, hai câu tách rời, nên bấm đúp nút 🎬 là ra hai dự án cho
 * cùng một bài — asset của một con tàu bị chẻ đôi mà màn hình không báo gì.
 *
 * Laravel 10 đã có sẵn phần chống: `firstOrCreate()` → `createOrFirst()` bắt
 * `UniqueConstraintViolationException` rồi SELECT lại. Nhưng nó chỉ chạy khi
 * database ném 1062, mà không có UNIQUE thì MySQL chèn trùng êm ru. Migration
 * này biến cái `catch` đó từ code chết thành code sống, không phải viết thêm
 * dòng nào.
 *
 * CẢNH BÁO cho ngày làm chức năng xoá dự án: hàng đã xoá mềm vẫn giữ chỗ trong
 * ràng buộc, nên phải tìm bằng `withTrashed()` rồi khôi phục — nếu không,
 * `firstOrCreate()` sẽ đâm 1062, SELECT lại vẫn không thấy (bị SoftDeletes lọc)
 * và ném lỗi ra tận trình duyệt. Ghép `UNIQUE(article_id, deleted_at)` KHÔNG
 * cứu được: MySQL coi mỗi NULL là khác nhau nên hai hàng sống vẫn lọt cả hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Thêm UNIQUE TRƯỚC: index thường đang là cái đỡ cho FK `article_id`,
        // xoá nó khi chưa có index thay thế thì MySQL báo 1553.
        Schema::table('video_projects', function (Blueprint $table) {
            $table->unique('article_id');
        });

        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropIndex('video_projects_article_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', function (Blueprint $table) {
            $table->index('article_id');
        });

        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropUnique(['article_id']);
        });
    }
};
