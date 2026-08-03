<?php

namespace App\Video\Llm;

use App\Services\Admin\ClaudeWriterService;
use Throwable;

/**
 * Nối LlmClient của Semantic OS với ClaudeWriterService của CMS.
 *
 * ĐÂY LÀ FILE DUY NHẤT trong app/Video/ biết CMS tồn tại. Nếu mai ClaudeWriterService
 * đổi chữ ký, đổi tên, hay bị thay bằng thứ khác — chỉ file này hỏng. Truth Layer
 * không biết và không cần build lại. Đó là toàn bộ lý do adapter này tồn tại.
 *
 * KHÔNG viết lại service đó. Nó đã encode những thứ trả giá mới có: retry backoff,
 * xử lý riêng lỗi 529 Overloaded (30s/60s/90s), giới hạn 40 request/phút và 8 luồng
 * đồng thời tới Anthropic. Viết lại là mất sạch, y như với providers Kling.
 */
final class ClaudeWriterAdapter implements LlmClient
{
    public function __construct(
        private readonly ClaudeWriterService $claudeWriterService,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        // Model do NGƯỜI GỌI quyết định (ClaudeExtractor/ClaudeProducer/
        // ClaudeDirector, mỗi class có default riêng) — adapter chỉ CHUYỂN TIẾP.
        //
        // Trước 2026-07-29 adapter tự giữ một `$modelType` riêng và phớt lờ
        // `$request->model`. Hệ quả: lựa chọn model theo từng cú gọi MẤT TÁC
        // DỤNG âm thầm — đổi `new ClaudeDirector($llm, 'haiku')` không có hiệu
        // lực gì, và `LlmResponse->model` báo sai model đã dùng.
        $modelType = $request->model;

        // Phải chặn ở đây, KHÔNG để rơi vào default của service: `generate()`
        // rơi về 'haiku' còn `costUsd()` rơi về 'sonnet' — hai fallback lệch
        // nhau, nên một lỗi chính tả sẽ gọi Haiku nhưng ghi hoá đơn giá Sonnet.
        // Hỏng ồn ào tốt hơn chạy sai model kèm sai giá.
        if (! ClaudeWriterService::supports($modelType)) {
            throw new LlmUnavailable(
                "Model không hỗ trợ: {$modelType} — xem ClaudeWriterService::MODELS",
            );
        }

        $startedAt = microtime(true);

        try {
            // instruction → system, input → user. Tách ra để instruction được
            // cache và để bài báo không lẫn vào chỉ dẫn.
            //
            // `$request->maxTokens` PHẢI được truyền xuống. Trước 2026-07-30
            // adapter bỏ qua nó, nên mọi cú gọi rơi về bảng MAX_TOKENS của
            // service (4096 cho haiku) dù LlmRequest đã khai 8192 — cùng LỚP LỖI
            // với `$request->model` bị bỏ qua trước đó: field có thật, ý định có
            // thật, rơi âm thầm ở đúng ranh giới này.
            $response = $this->claudeWriterService->generate(
                $request->input,
                $modelType,
                $request->instruction,
                $request->maxTokens,
            );
        } catch (Throwable $e) {
            throw new LlmUnavailable(
                "Claude không gọi được ({$modelType}): {$e->getMessage()}",
                previous: $e,
            );
        }

        if (trim($response->text) === '') {
            throw new LlmUnavailable('Claude trả về rỗng — coi là lỗi, KHÔNG coi là "bài báo không có sự thật nào"');
        }

        // Output bị cắt ở trần ⇒ nội dung KHÔNG hoàn chỉnh. Nổ NGAY ĐÂY, đúng
        // chỗ biết được nguyên nhân.
        //
        // Trước 2026-07-30 bản cắt dở chảy xuống `CandidateGraphParser` và nổ
        // thành "LLM không trả về JSON hợp lệ" — thông báo chỉ SAI CHỖ, vì LLM
        // trả JSON hợp lệ, chỉ là bị cắt cụt. Hệ quả: mất ~$0.09 qua 5 lần thử
        // lại mà không ai biết phải nâng trần.
        if ($response->wasTruncated()) {
            throw new LlmUnavailable(sprintf(
                'Claude bị CẮT ở trần %d token output (đã sinh %d) nên kết quả không hoàn chỉnh — '
                    .'ĐÃ TỐN PHÍ cú gọi này. Bài quá dài so với trần: tăng LlmRequest::$maxTokens '
                    .'(hiện %d) rồi chạy lại. Bấm lại nguyên trạng sẽ hỏng y hệt và mất thêm tiền.',
                $request->maxTokens,
                $response->outputTokens,
                $request->maxTokens,
            ));
        }

        // `totalInputTokens()` chứ KHÔNG phải `inputTokens`: Anthropic trả
        // `usage.input_tokens` là phần CHƯA cache, tổng đầu vào thật còn cộng
        // cache_creation + cache_read. Hôm nay project chưa bật cache nên hai số
        // bằng nhau — sửa trước để ngày bật thì thống kê không tụt âm thầm
        // (§18.24).
        //
        // costUsd cũng nhận đủ 4 loại token: cache GHI đắt hơn input 25%, cache
        // ĐỌC rẻ hơn 10 lần — không phải cùng một đơn giá.
        return new LlmResponse(
            $response->text,
            $modelType,
            $response->totalInputTokens(),
            $response->outputTokens,
            (int) round((microtime(true) - $startedAt) * 1000),
            ClaudeWriterService::costUsd(
                $response->inputTokens,
                $response->outputTokens,
                $modelType,
                $response->cacheWriteTokens,
                $response->cacheReadTokens,
            ),
            $response->text,
        );
    }
}
