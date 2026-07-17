<?php

namespace App\Video\Llm;

/**
 * Bọc quanh một LlmClient thật và chặn mọi cú gọi chưa được duyệt.
 *
 * Decorator chứ không phải kiểm tra rải rác trong từng extractor: cổng duyệt
 * mà nằm trong extractor thì extractor sau sẽ quên, và cái quên đó im lặng —
 * chỉ hiện ra ở hoá đơn cuối tháng.
 *
 * Mặc định DenyByDefaultGate: tiêu tiền phải là hành động có chủ ý.
 */
final class GatedLlmClient implements LlmClient
{
    public function __construct(
        private readonly LlmClient $inner,
        private readonly ApprovalGate $gate = new DenyByDefaultGate(),
        /**
         * USD mỗi 1M token input, chỉ để ƯỚC LƯỢNG lúc xin duyệt.
         *
         * Mặc định lấy giá sonnet trong bảng giá của chính project
         * (ClaudeWriterService::PRICE_INPUT) để con số ở đây và con số trong
         * ClaudeUsageLog không nói hai chuyện khác nhau. Bảng đó có ghi chú
         * "update when Anthropic changes rates" — nếu nó cũ thì ước lượng này
         * cũng cũ theo, nhưng ít nhất cũ ĐỒNG BỘ với phần còn lại của hệ thống.
         */
        private readonly float $inputPricePerMillion = 3.0,
    ) {
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $tokens = $request->estimatedInputTokens();
        $cost   = $tokens / 1_000_000 * $this->inputPricePerMillion;

        if (! $this->gate->allows($request, $cost)) {
            throw new ApprovalRequired(
                sprintf('Gọi %s (instruction %s)', $request->model, $request->instructionVersion),
                $cost,
                $tokens,
            );
        }

        return $this->inner->complete($request);
    }
}
