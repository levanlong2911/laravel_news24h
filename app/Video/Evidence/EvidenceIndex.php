<?php

namespace App\Video\Evidence;

/**
 * Span map của một bài báo. Deterministic, không gọi AI.
 *
 * Nhiệm vụ duy nhất: trả lời "đoạn chữ này có THẬT SỰ nằm trong bài không?"
 * Đây là điều Gatekeeper cần và là điều LLM không được phép tự khẳng định —
 * LLM trả quote, index này tự đi tìm. Tìm không thấy nghĩa là LLM bịa cả câu
 * trích, và claim bị loại.
 */
final class EvidenceIndex
{
    /** @var list<array{source: EvidenceSource, raw: string, normalized: string}> */
    private array $segments = [];

    public function add(EvidenceSource $source, string $text): self
    {
        $text = trim($text);

        if ($text !== '') {
            $this->segments[] = [
                'source'     => $source,
                'raw'        => $text,
                'normalized' => self::normalize($text),
            ];
        }

        return $this;
    }

    /**
     * Định vị quote. Trả null nghĩa là KHÔNG có bằng chứng ⇒ claim không tồn tại.
     *
     * Hai mức, theo thứ tự chặt dần:
     *   Direct     — quote nằm nguyên văn trong bài
     *   Normalized — khớp sau khi thống nhất hoa/thường, khoảng trắng, dấu nháy
     *                và dấu gạch (bài báo thật dùng ’ – — … lung tung)
     */
    public function find(string $quote): ?Evidence
    {
        $quote = trim($quote);

        if ($quote === '') {
            return null;
        }

        foreach ($this->segments as $segment) {
            $offset = mb_strpos($segment['raw'], $quote);

            if ($offset !== false) {
                return new Evidence($quote, $segment['source'], $offset, ProvenanceLevel::Direct);
            }
        }

        $needle = self::normalize($quote);

        if ($needle === '') {
            return null;
        }

        foreach ($this->segments as $segment) {
            $offset = mb_strpos($segment['normalized'], $needle);

            if ($offset !== false) {
                return new Evidence($quote, $segment['source'], $offset, ProvenanceLevel::Normalized);
            }
        }

        return null;
    }

    public function has(string $quote): bool
    {
        return $this->find($quote) !== null;
    }

    /**
     * Text đã normalize của một nguồn — dùng cho các kiểm tra cần quét toàn văn
     * (ví dụ: Event chỉ được tồn tại nếu bài có động từ tương ứng).
     */
    public function textOf(EvidenceSource $source): string
    {
        $parts = [];

        foreach ($this->segments as $segment) {
            if ($segment['source'] === $source) {
                $parts[] = $segment['normalized'];
            }
        }

        return implode(' ', $parts);
    }

    public function fullText(): string
    {
        return implode(' ', array_column($this->segments, 'normalized'));
    }

    /**
     * Văn bản THÔ kèm nguồn — đây là thứ cho Extractor xem.
     *
     * Phải là raw chứ không phải normalized: mô hình cần trích được nguyên văn
     * để `find()` khớp ở mức Direct. Cho nó xem bản đã hạ chữ thường thì mọi
     * quote quay về đều chỉ đạt Normalized, và ta mất khả năng phân biệt "trích
     * đúng" với "trích gần đúng".
     *
     * @return list<array{source: EvidenceSource, raw: string}>
     */
    public function rawSegments(): array
    {
        return array_map(
            fn (array $s) => ['source' => $s['source'], 'raw' => $s['raw']],
            $this->segments,
        );
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
    }

    /**
     * Thống nhất những khác biệt hình thức mà bài báo thật luôn có, KHÔNG đụng
     * tới nghĩa. Đây là ranh giới của Normalized: đổi hình thức thì được, thêm
     * tri thức thì không.
     */
    public static function normalize(string $text): string
    {
        $text = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{00A0}"],
            ["'", "'", '"', '"', '-', '-', ' '],
            $text,
        );

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(mb_strtolower($text));
    }
}
