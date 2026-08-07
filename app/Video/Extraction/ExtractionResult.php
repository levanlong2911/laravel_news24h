<?php

namespace App\Video\Extraction;

/**
 * Output của Extractor: giả thuyết + toàn bộ dấu vết của cú gọi AI.
 *
 * HAI LOẠI PROVENANCE, đừng lẫn:
 *   - provenance của FACT  → Evidence (quote/offset/source). Sống ở World Graph,
 *     KHÔNG bao giờ qua ranh giới sang Python.
 *   - provenance của AI    → chính object này (model, version, token, raw). Sống
 *     ở tầng extraction, dùng để truy hallucination.
 *
 * Gatekeeper CHỈ đọc `candidates`. Phần còn lại tồn tại để sáu tháng sau, khi
 * một sự thật sai lọt lưới, còn biết được lúc đó chạy model nào, instruction
 * version nào, và JSON thô Claude trả về là gì. Thiếu nó thì chỉ còn nước đoán.
 */
final class ExtractionResult
{
    public function __construct(
        public readonly CandidateWorldGraph $candidates,
        public readonly string $model,
        public readonly string $instructionVersion,
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        public readonly int $latencyMs = 0,
        public readonly float $costUsd = 0.0,
        public readonly string $raw = '',
        /**
         * Parser đã bỏ mất gì trên đường từ `raw` sang `candidates`.
         *
         * NULLABLE CÓ CHỦ Ý, và `null` KHÁC "có nhưng sạch":
         *   null            → chưa ai đo (RecordedExtractor, FakeExtractor)
         *   isClean() true  → đã đo, và không mất gì
         * Gộp hai cái đó thành một sẽ khiến "bản phát lại" trông như "lượt chạy
         * hoàn hảo" — đúng kiểu ngộ nhận mà cả lớp diagnostics sinh ra để dẹp.
         *
         * Đặt CUỐI constructor để ba chỗ đang dựng ExtractionResult không phải
         * sửa: `FakeExtractor` và `RecordedExtractor` bỏ qua, CI vẫn $0.
         */
        public readonly ?ParserDiagnostics $diagnostics = null,
    ) {}

    public function candidateCount(): int
    {
        $claims = array_sum(array_map(
            fn (CandidateEntity $e) => count($e->claims),
            $this->candidates->entities,
        ));

        return count($this->candidates->entities)
            + count($this->candidates->relations)
            + count($this->candidates->events)
            + $claims;
    }

    public function describe(): string
    {
        return sprintf(
            '%d giả thuyết · %s (instruction %s) · %d/%d token · %dms · $%.4f',
            $this->candidateCount(),
            $this->model,
            $this->instructionVersion,
            $this->tokensIn,
            $this->tokensOut,
            $this->latencyMs,
            $this->costUsd,
        );
    }
}
