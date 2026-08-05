<?php

namespace App\Services\Admin;

/**
 * Toàn bộ thứ Anthropic tính tiền cho MỘT request, không thiếu trường nào.
 *
 * ── Vì sao cần bốn trường token chứ không phải hai ──────────────────────────
 *
 * input_tokens KHÔNG bao gồm token cache. Tổng prompt thật là:
 *
 *     input_tokens + cache_creation_input_tokens + cache_read_input_tokens
 *
 * Ba khoản đó tính theo ba đơn giá khác nhau (1× / 1,25× / 0,1× giá input).
 * Chỉ cộng input + output là tính thiếu ngay khi bật prompt caching. Hiện
 * pipeline chưa bật caching nên hai trường cache luôn 0 — nhưng ghi sẵn để
 * ngày bật lên không phải sửa lại chỗ tính tiền, và để đo được caching tiết
 * kiệm bao nhiêu.
 *
 * ── Vì sao mang theo model chứ không chỉ modelType ──────────────────────────
 *
 * modelType ('haiku' / 'sonnet') là khoá tra bảng giá nội bộ. model là chuỗi
 * thật đã gửi đi (claude-sonnet-4-6). Cần cả hai: khi đối soát với
 * cost_report của Anthropic, họ nhóm theo model ID thật, không theo bí danh
 * nội bộ của mình.
 *
 * ── requestId ──────────────────────────────────────────────────────────────
 *
 * Header `request-id` Anthropic trả về trên mọi response. null khi không đọc
 * được. Dùng để đối chất khi cost_report lệch, và khi mở ticket support.
 */
final class TokenUsage
{
    public function __construct(
        public readonly string  $model,
        public readonly string  $modelType,
        public readonly int     $inputTokens         = 0,
        public readonly int     $outputTokens        = 0,
        public readonly int     $cacheCreationTokens = 0,
        public readonly int     $cacheReadTokens     = 0,
        public readonly ?string $requestId           = null,
    ) {}

    /** Request không tốn gì — 400 Bad Request, hoặc chưa từng gọi. */
    public static function none(string $model = '', string $modelType = 'sonnet'): self
    {
        return new self(model: $model, modelType: $modelType);
    }

    /**
     * Dựng từ object usage trong response JSON.
     *
     * @param  array<string, mixed>  $usage  $json['usage'] — có thể thiếu trường
     */
    public static function fromResponse(array $usage, string $model, string $modelType, ?string $requestId = null): self
    {
        return new self(
            model:               $model,
            modelType:           $modelType,
            inputTokens:         (int) ($usage['input_tokens']                ?? 0),
            outputTokens:        (int) ($usage['output_tokens']               ?? 0),
            cacheCreationTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            cacheReadTokens:     (int) ($usage['cache_read_input_tokens']     ?? 0),
            requestId:           $requestId,
        );
    }

    /** Tổng token đầu vào thật, gồm cả phần cache. */
    public function totalInputTokens(): int
    {
        return $this->inputTokens + $this->cacheCreationTokens + $this->cacheReadTokens;
    }

    public function isEmpty(): bool
    {
        return $this->totalInputTokens() === 0 && $this->outputTokens === 0;
    }

    public function costUsd(): float
    {
        return ClaudeWriterService::costOf($this);
    }
}
