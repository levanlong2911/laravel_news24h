<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chỉnh hai điểm trên sổ cái, khi bảng còn rỗng.
 *
 * 1. parent_request_id -> previous_request_id
 *    Tên cũ gợi ý quan hệ cha-con giữa các lượt gọi logic, trong khi thực tế nó
 *    trỏ tới LƯỢT THỬ NGAY TRƯỚC trong cùng một generate(). Quan hệ gom nhóm đã
 *    do call_uuid + attempt đảm nhiệm rồi.
 *
 * 2. Thêm index (vendor, model, created_at)
 *    Dashboard sẽ hỏi kiểu "Anthropic / Sonnet / 7 ngày gần đây" — lọc hai cột
 *    đầu rồi quét khoảng thời gian. Index (created_at, vendor) đã có phục vụ
 *    kiểu hỏi ngược lại (theo ngày trước, lọc sau), giữ cả hai.
 *
 * Dùng ALTER thô thay vì renameColumn(): Laravel 10 cần doctrine/dbal cho
 * renameColumn, mà composer.json không có gói đó.
 *
 * KHÔNG sửa migration 000001 dù nó mới commit hôm nay và bảng đang rỗng — sửa
 * một migration đã commit là đúng thứ đã sinh ra mớ hỗn độn phải dọn sáng nay.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE claude_request_ledger CHANGE parent_request_id previous_request_id VARCHAR(255) NULL');

        Schema::table('claude_request_ledger', function (Blueprint $table) {
            $table->index(['vendor', 'model', 'created_at'], 'ledger_vendor_model_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('claude_request_ledger', function (Blueprint $table) {
            $table->dropIndex('ledger_vendor_model_created_idx');
        });

        DB::statement('ALTER TABLE claude_request_ledger CHANGE previous_request_id parent_request_id VARCHAR(255) NULL');
    }
};
