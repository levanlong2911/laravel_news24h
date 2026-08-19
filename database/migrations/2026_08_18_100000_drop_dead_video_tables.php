<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Xoá 5 bảng chết. "Chết" ở đây là ĐO ĐƯỢC, không phải cảm nhận: mỗi bảng đều
 * KHÔNG có Model trong app/Models và tên bảng xuất hiện 0 lần trong app/.
 *
 *   video_claude_calls   11 hàng — pipeline fact_extractor/story_planner/
 *                        script_generator đã bỏ. Việc của nó nay chia cho
 *                        claude_usage_logs (tiền) và video_planning_stages (nội dung).
 *   video_jobs            1 hàng — di tích kiến trúc "chia video thành part để duyệt"
 *                        (story_plan_id, part_number, approval_status).
 *   video_analytics       0 hàng — khoá ngoài trỏ video_job_id, tức thuộc luôn
 *                        kiến trúc cũ. Thay bằng video_publication_metrics.
 *   video_assets          0 hàng — khoá theo shot_id, tức buộc tài sản vào MỘT shot.
 *                        Đó đúng là lỗi phạm vi đang làm mất 50% tiền ảnh; thay
 *                        bằng video_design_cells khoá theo project_id.
 *   video_outputs         0 hàng — trùng vai video_finals.
 */
return new class extends Migration
{
    /**
     * THỨ TỰ CÓ NGHĨA: `video_analytics.video_job_id` trỏ tới `video_jobs`, nên
     * con phải đi trước cha. Xếp ngược lại thì MariaDB trả 1451 và migration
     * dừng giữa chừng — đã gặp thật lúc chạy lần đầu.
     */
    private const DEAD = [
        'video_claude_calls',
        'video_analytics',
        'video_jobs',
        'video_assets',
        'video_outputs',
    ];

    public function up(): void
    {
        foreach (self::DEAD as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * KHÔNG dựng lại. Cùng lý do với migration 000003 của prompt framework:
     * dựng lại cái vỏ rỗng tạo ảo giác đã hoàn tác, trong khi 12 hàng dữ liệu
     * đã mất thật. Muốn quay lui thì phục hồi từ bản dump trước khi chạy.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Không hoàn tác được: 5 bảng này đã bị xoá cùng dữ liệu. Phục hồi từ dump.',
        );
    }
};
