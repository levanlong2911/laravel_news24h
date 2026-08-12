<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §18.30 vá race condition thứ hai (2026-08-12, review độc lập): status=planning
 * một mình không đủ để chống HAI tiến trình `video:build-plan --session=` chạy
 * cùng lúc cho cùng một session (chạy tay lặp, hoặc bug spawn kép) — cả hai đều
 * đọc `planning`, cả hai đều gọi Claude, tốn tiền gấp đôi.
 *
 * `planning_claimed_at` là claim, KHÔNG phải đổi `status`: đổi sang `composing`
 * trước khi có `renderplan_json` sẽ phá nghĩa state đã chốt (Python
 * `GET /video-sessions/composing` poll session đó rồi vỡ vì thiếu renderplan).
 *
 * ĐÍNH CHÍNH (cùng ngày, review độc lập lần ba): bản đầu cho claim HẾT HẠN
 * sau 10 phút — SAI, đã bỏ. Một cú gọi Claude đơn lẻ gặp 529 Overloaded có
 * thể mất tới ~10 phút RIÊNG NÓ (xem ClaudeWriterService::MAX_RETRIES/
 * DELAY_529_S), mà pipeline gọi 11+ lần, nên hạn thời gian không an toàn.
 * Cột `planning_claim_token` (migration 2026_08_12_200000, đọc docblock ở đó)
 * thay thế bằng cơ chế không tự hết hạn + xác minh quyền sở hữu lúc ghi kết
 * quả + mở khoá thủ công qua `video:reset-planning-claim`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->timestamp('planning_claimed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->dropColumn('planning_claimed_at');
        });
    }
};
