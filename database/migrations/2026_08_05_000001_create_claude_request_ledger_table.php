<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sổ cái từng HTTP request gửi tới nhà cung cấp LLM. INSERT-ONLY.
 *
 * ── Đây là event log, không phải bảng kế toán ───────────────────────────────
 *
 * Một dòng = một lượt HTTP, KỂ CẢ lượt thất bại. Không UPDATE, không DELETE.
 * Cố ý không có cột updated_at: có nó là mời người ta sửa.
 *
 * Cố ý KHÔNG lưu cost. Chi phí là dữ liệu suy ra từ (token × bảng giá), và hôm
 * nay đã chứng minh vì sao điều đó quan trọng: giá Haiku trong code sai 20%
 * suốt một thời gian dài, còn claude_usage_logs chỉ lưu tổng tiền nên 26 bản
 * ghi cũ không tài nào tính lại được — chỉ nói được một khoảng chặn. Lưu token
 * + pricing_version thì sai giá bao lâu cũng dựng lại được chính xác.
 *
 * ── Vì sao ghi cả lượt thất bại ─────────────────────────────────────────────
 *
 * Khi một request timeout mà Anthropic đã sinh xong token, họ vẫn tính tiền còn
 * phía mình không nhận được response để đọc usage. Khoản đó vĩnh viễn không
 * biết là bao nhiêu — nhưng ghi lại được là ĐÃ XẢY RA BAO NHIÊU LƯỢT. Cột
 * `billed` tách riêng chiều đó: đối soát với cost_report sau này thành phép
 * trừ có nghĩa thay vì phỏng đoán.
 *
 *     lệch ≈ số dòng billed=unknown   → giải thích được
 *     lệch ≫ số dòng billed=unknown   → còn đường gọi chưa ghi
 *
 * ── Không đặt khoá ngoại tới articles ───────────────────────────────────────
 *
 * Sổ cái ghi việc đã xảy ra. Bài viết bị xoá thì tiền vẫn đã tiêu, dòng ledger
 * phải sống sót. FK CASCADE sẽ xoá mất bằng chứng chi tiêu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_request_ledger', function (Blueprint $table) {
            $table->id();

            // ── Gom nhóm ──────────────────────────────────────────────────────
            // Một generate() có thể sinh tối đa MAX_RETRIES lượt HTTP. call_uuid
            // + attempt cho biết chúng thuộc cùng một lượt gọi logic.
            $table->uuid('call_uuid')->index();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('parent_request_id')->nullable(); // request_id của lượt ngay trước

            // ── Ngữ cảnh nghiệp vụ ────────────────────────────────────────────
            // Nullable có chủ ý: quên set context thì dòng vẫn được ghi với
            // article_id rỗng — sai sót NHÌN THẤY ĐƯỢC, thay vì mất tiền im lặng.
            $table->uuid('article_id')->nullable()->index();
            $table->uuid('pipeline_run_id')->nullable()->index();
            $table->string('phase', 40)->nullable();  // FACT_EXTRACTION | HOOK | WRITE | WRITE_RETRY | ...

            // ── Nhà cung cấp ──────────────────────────────────────────────────
            // vendor có từ ngày đầu: repo kia đã dùng fal/Kling thật, thêm sau
            // thì phải backfill hàng trăm nghìn dòng không có thông tin.
            $table->string('vendor', 20)->default('anthropic');
            $table->string('model', 60);       // chuỗi thật đã gửi: claude-sonnet-4-6
            $table->string('model_type', 20);  // bí danh nội bộ: haiku | sonnet

            $table->string('request_id')->nullable()->index(); // header request-id

            // ── Token ─────────────────────────────────────────────────────────
            // Cột riêng để query nhanh; usage_json giữ nguyên bản để không mất
            // trường mà Anthropic thêm sau này (họ đã thêm cache_creation,
            // server_tool_use, iterations qua các đợt mà không báo trước).
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_creation_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->json('usage_json')->nullable();

            // Đủ để dựng lại bảng giá và tính ngược, chưa cần bảng price_books.
            $table->string('pricing_version', 40);  // anthropic-v2026-08-05

            // ── Vân tay ───────────────────────────────────────────────────────
            // Lưu hash chứ không lưu prompt: trả lời được "vì sao bài này đắt
            // gấp ba" mà không phình bảng và không lưu lại nội dung.
            $table->char('prompt_hash', 64)->nullable()->index();
            $table->char('response_hash', 64)->nullable();

            // ── Thời gian ─────────────────────────────────────────────────────
            // latency phân biệt timeout do mạng với model chậm dần.
            $table->timestamp('started_at', 3);
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // ── Kết quả ───────────────────────────────────────────────────────
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('billed', 10);                    // yes | no | unknown
            $table->string('retry_reason', 30)->nullable();   // vì sao lượt TRƯỚC hỏng
            $table->text('error')->nullable();

            $table->timestamp('created_at', 3)->useCurrent();

            $table->index(['article_id', 'phase']);
            $table->index(['created_at', 'vendor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_request_ledger');
    }
};
