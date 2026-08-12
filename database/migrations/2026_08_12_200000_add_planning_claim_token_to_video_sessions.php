<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §18.30 vá race condition thứ ba (2026-08-12, review độc lập lần ba):
 * lease `planning_claimed_at` tự hết hạn sau 10 phút là KHÔNG AN TOÀN — một
 * cú gọi Claude đơn lẻ, gặp 529 Overloaded, đã có thể mất tới ~10 phút chỉ
 * riêng nó (5 lần thử × 60s timeout + backoff 30/60/90/120s, xem
 * ClaudeWriterService::MAX_RETRIES/DELAY_529_S), mà pipeline gọi Claude 11+
 * lần. Worker cũ vẫn sống quá hạn 10 phút → worker mới nghĩ claim đã chết,
 * claim lại, CẢ HAI cùng gọi Claude.
 *
 * `planning_claim_token`: KHÔNG tự hết hạn nữa — `planning_claimed_at` khác
 * null là chặn TUYỆT ĐỐI. Ghi kết quả (thành công hay thất bại) phải kèm
 * đúng token đã nhận lúc claim; token không khớp (đã bị reset thủ công rồi
 * claim lại bởi worker khác) thì bỏ kết quả, không ghi đè. Mở khoá lại chỉ
 * qua `video:reset-planning-claim` — thao tác CÓ CHỦ Ý của người vận hành,
 * sau khi tự xác nhận tiến trình cũ đã chết thật.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->string('planning_claim_token', 36)->nullable()->after('planning_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->dropColumn('planning_claim_token');
        });
    }
};
