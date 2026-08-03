<?php

namespace App\Services\Admin;

final class ClaudeResponse
{
    /**
     * @param  string  $stopReason  Vì sao model dừng sinh: 'end_turn' (xong bình thường),
     *                              'max_tokens' (BỊ CẮT ở trần), 'stop_sequence'…
     *
     * Thêm 2026-07-30 vì bug thật: output bị cắt ở 4096 token, service CHỈ ghi
     * một dòng log warning rồi trả bản cắt dở như thể bình thường. Caller không
     * có cách nào biết, nên JSON cụt chảy xuống tận `CandidateGraphParser` và nổ
     * ra "LLM không trả về JSON hợp lệ" — thông báo chỉ SAI CHỖ, vì LLM trả JSON
     * hợp lệ, chỉ là bị cắt cụt. Bằng chứng: 5 lần cắt trần trong log, 3 lần
     * khớp TỪNG GIÂY với MalformedExtraction, ~$0.09 trả cho dữ liệu bị vứt.
     *
     * Rỗng khi không xác định được (vd đường lỗi 400) — caller phải coi chuỗi
     * rỗng là "không biết", KHÔNG phải "bình thường".
     */
    /**
     * @param  int  $cacheWriteTokens  `usage.cache_creation_input_tokens` — token GHI vào
     *                                 cache, tính đắt hơn input thường 25%.
     * @param  int  $cacheReadTokens  `usage.cache_read_input_tokens` — token ĐỌC từ cache,
     *                                rẻ gần 10 lần.
     *
     * Mang theo hai trường này (2026-07-31) vì `usage.input_tokens` của Anthropic
     * chỉ là PHẦN CHƯA CACHE, không phải toàn bộ prompt. Hiện project chưa gửi
     * `cache_control` nên cả hai luôn 0 — giữ ở đây để ngày bật cache thì chi phí
     * không tụt xuống âm thầm.
     */
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $stopReason = '',
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
    ) {}

    /**
     * Tổng token ĐẦU VÀO thật — `inputTokens` một mình luôn thiếu khi có cache.
     *
     * Tên tránh chữ "prompt" CÓ CHỦ ĐÍCH: `app/Video/` gọi hàm này, mà ở đó chữ
     * đó bị Architecture Test cấm (§1). Class này nằm ngoài `app/Video/` nên
     * không bị quét, nhưng chỗ GỌI thì bị — đặt tên sai là làm CI đỏ ở nơi khác.
     */
    public function totalInputTokens(): int
    {
        return $this->inputTokens + $this->cacheWriteTokens + $this->cacheReadTokens;
    }

    /** Output bị cắt ở trần `max_tokens` ⇒ nội dung KHÔNG hoàn chỉnh, đừng parse. */
    public function wasTruncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
