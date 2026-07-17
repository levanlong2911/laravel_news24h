<?php

namespace App\Video\Evidence\Value;

use App\Video\Evidence\EvidenceIndex;
use App\Video\Evidence\ProvenanceLevel;

/**
 * Giá trị là một cụm nằm TRONG quote: "grey hull" chống lưng cho `hull_color = grey`.
 *
 * Không có normalizer này thì Gatekeeper vô dụng — bài báo viết "the grey hull
 * measures 101 metres", không ai viết một câu chỉ có đúng chữ "grey".
 *
 * PHỦ ĐỊNH — vì sao có guard ở đây:
 * Chính việc cho phép containment mở ra lỗ hổng: "not grey" cũng CHỨA "grey".
 * Nhận bừa thì Gatekeeper sẽ khẳng định thân tàu màu xám trong khi bài báo nói
 * ngược lại — đúng loại sai mà Gatekeeper sinh ra để chặn. Guard không phải
 * tính năng thêm thắt; nó là cái giá bắt buộc của containment.
 *
 * Giới hạn đã biết: guard chỉ nhìn cụm phủ định ngay TRƯỚC vị trí khớp. Phủ định
 * ở xa ("the hull, unlike the earlier boat, is not grey") vẫn lọt. Khắc phục
 * triệt để cần phân tích cú pháp — chưa trả rent (Rule 0). Cách đúng để tránh
 * là để Extractor trích quote hẹp quanh sự thật, đừng trích cả câu dài.
 */
final class ContainedPhraseNormalizer implements ValueNormalizer
{
    private const NEGATIONS = [
        'not', 'no', 'never', 'without', 'instead of', 'rather than',
        'isn\'t', 'aren\'t', 'wasn\'t', 'weren\'t', 'lacks', 'lacking',
    ];

    /** Số ký tự nhìn ngược lại để bắt phủ định. */
    private const LOOKBEHIND = 24;

    public function supports(string $quote, mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $haystack = EvidenceIndex::normalize($quote);
        $needle   = EvidenceIndex::normalize($value);

        if ($needle === '' || ! preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $haystack, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        return ! $this->isNegated($haystack, $m[0][1]);
    }

    private function isNegated(string $haystack, int $byteOffset): bool
    {
        $start   = max(0, $byteOffset - self::LOOKBEHIND);
        $context = substr($haystack, $start, $byteOffset - $start);

        foreach (self::NEGATIONS as $negation) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($negation, '/') . '(?![\p{L}\p{N}])/u', $context)) {
                return true;
            }
        }

        return false;
    }

    public function level(): ProvenanceLevel
    {
        return ProvenanceLevel::Normalized;
    }
}
