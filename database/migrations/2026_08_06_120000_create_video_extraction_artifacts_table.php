<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bằng chứng pháp y của MỘT lần Truth Layer chạy.
 *
 * VÌ SAO CẦN: bài "ISA Amarcord 82" (2026-08-06) ra 0 fact dù bài có nguyên văn
 * "the aft edge of the pool is glass, as is the bottom", "helipad", "sporty
 * curves". Không ai trả lời được vì sao, vì mọi bằng chứng đều bị vứt ngay sau
 * khi dùng: `raw` sống trong biến cục bộ, `GatekeeperReport` chết cùng scope,
 * `model` thật không ai ghi. `claude_usage_logs` chỉ có token và tiền.
 *
 * Bảng này để sáu tháng sau trả lời được câu "vì sao video này không có bãi đáp
 * trực thăng?" bằng dữ liệu:
 *
 *     raw               → LLM có đề xuất helipad không
 *     diagnostics       → parser có nuốt nó không, và ở path nào
 *     candidate_graph   → parser hiểu được gì
 *     gatekeeper_report → cổng loại cái gì, vì lý do gì
 *
 * SCALAR vs JSON — chia có chủ đích, không phải tuỳ hứng: cột nào sẽ nằm trong
 * `WHERE` thì để scalar (`model`, `instruction_version`, `category`), cột nào
 * chỉ đọc khi điều tra thì để JSON. Bắt JSON gánh việc lọc là tự chuốc lấy
 * truy vấn chậm và index không dùng được.
 *
 * KHÔNG phải nguồn sự thật của bất kỳ tầng nào — không ai đọc bảng này lúc
 * chạy. Nó là dụng cụ đo, ghi một chiều.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_extraction_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Khoá tra cứu — KHÔNG ràng buộc ngoại: artifact phải sống sót cả
            // khi bài báo bị xoá, vì lúc đó nó là thứ DUY NHẤT còn kể lại được
            // chuyện gì đã xảy ra.
            $table->uuid('article_id')->index();
            $table->uuid('session_id')->nullable()->index();
            $table->string('category', 100)->nullable()->index();

            // Ghi GIÁ TRỊ ĐÃ DÙNG THẬT, không phải giá trị config lúc đọc bảng.
            // Bài học trực tiếp: ClaudeProducer khai 'haiku' suốt 6 ngày mà thực
            // tế chạy Sonnet — khai và chạy là hai chuyện khác nhau.
            $table->string('model', 100)->index();
            $table->string('instruction_version', 50)->index();

            // NULL cho tới khi knowledge profile thật sự tồn tại (Sprint 1).
            // Không nhét 'unknown' hay 'v1' giả: một giá trị bịa trong cột
            // provenance còn tệ hơn không có giá trị nào.
            $table->string('profile_version', 50)->nullable();

            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);

            // Bốn tầng bằng chứng, mỗi tầng trả lời một câu hỏi khác nhau.
            // `candidate_graph` là graph SAU parser và TRƯỚC Gatekeeper — nếu
            // chỉ lưu bản đã verify thì mất đúng phần cần để phân biệt "LLM
            // không tìm ra" với "cổng đã loại".
            $table->longText('raw')->nullable();
            $table->json('candidate_graph')->nullable();
            $table->json('gatekeeper_report')->nullable();
            $table->json('diagnostics')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_extraction_artifacts');
    }
};
