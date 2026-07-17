<?php

namespace App\Video\Evidence;

/**
 * Bằng chứng cho MỘT claim — do Gatekeeper tạo, không bao giờ do LLM tạo.
 *
 * `offset` được EvidenceIndex tính bằng cách tự đi tìm quote. LLM không được
 * cấp offset: nó đếm ký tự rất tệ và sẽ trả về offset trông hợp lý nhưng sai,
 * và tin vào đó là đánh mất tính deterministic ở một chỗ kín đáo.
 *
 * Object này KHÔNG BAO GIỜ được đi qua ranh giới sang Python.
 * Xem docs/video/ARCHITECTURE.md §1 "Evidence never crosses the boundary".
 */
final class Evidence
{
    /**
     * @param int $offset Vị trí trong text đã normalize của segment nguồn
     */
    public function __construct(
        public readonly string $quote,
        public readonly EvidenceSource $source,
        public readonly int $offset,
        public readonly ProvenanceLevel $level,
    ) {
    }

    public function length(): int
    {
        return mb_strlen($this->quote);
    }

    /** Chỉ để log/debug ở Laravel — không serialize sang RenderPlan. */
    public function describe(): string
    {
        return sprintf(
            '%s@%d [%s] "%s"',
            $this->source->value,
            $this->offset,
            $this->level->value,
            mb_strimwidth($this->quote, 0, 60, '…'),
        );
    }
}
