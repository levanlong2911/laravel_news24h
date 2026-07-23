<?php

namespace App\Video\Llm;

/**
 * Bọc quanh một LlmClient thật, cộng dồn token/cost/latency qua NHIỀU cú gọi
 * (Extractor + Producer + N×Director cho 1 bài báo). Chỉ dùng cho benchmark
 * (`video:benchmark`) — pipeline sản xuất (VideoSessionService) không biết
 * class này tồn tại, đúng boundary: benchmark wrap, production không đổi.
 *
 * Đặt NGOÀI GatedLlmClient (bọc GatedLlmClient, không phải bị bọc) — cú gọi bị
 * gate chặn ném ApprovalRequired trước khi có LlmResponse nên không có gì để
 * cộng dồn; thứ tự bọc không ảnh hưởng hành vi, chỉ để rõ ràng.
 */
final class CostAccumulatingLlmClient implements LlmClient
{
    private int $callCount = 0;
    private int $tokensIn = 0;
    private int $tokensOut = 0;
    private float $costUsd = 0.0;
    private int $latencyMs = 0;

    public function __construct(
        private readonly LlmClient $inner,
    ) {
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $response = $this->inner->complete($request);

        $this->callCount++;
        $this->tokensIn += $response->tokensIn;
        $this->tokensOut += $response->tokensOut;
        $this->costUsd += $response->costUsd;
        $this->latencyMs += $response->latencyMs;

        return $response;
    }

    /**
     * @return array{call_count: int, tokens_in: int, tokens_out: int, cost_usd: float, latency_ms: int}
     */
    public function totals(): array
    {
        return [
            'call_count' => $this->callCount,
            'tokens_in'  => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'cost_usd'   => $this->costUsd,
            'latency_ms' => $this->latencyMs,
        ];
    }

    public function reset(): void
    {
        $this->callCount = 0;
        $this->tokensIn = 0;
        $this->tokensOut = 0;
        $this->costUsd = 0.0;
        $this->latencyMs = 0;
    }
}
