<?php

namespace App\Video\Evidence\Value;

use App\Video\Evidence\ProvenanceLevel;

/**
 * Số viết bằng chữ: "two powerful MTU engines" → 2 · "operates seven shipyards" → 7.
 *
 * Cần class riêng vì đây không phải chuyện regex số — không có chữ số nào trong
 * quote để mà bắt. CountNormalizer và OrdinalNormalizer đều mù hoàn toàn.
 *
 * Bảng chữ→số có phải "tri thức ngoài span" không? Không. Nó là TỪ VỰNG, cùng
 * loại với việc biết "Grey" và "grey" là một từ. Nó không nói thêm điều gì về
 * thế giới — "two" nghĩa là 2 trong mọi văn cảnh, không cần biết đang nói về
 * cái gì. Đối chiếu với "Feadship" → "Netherlands": cái đó cần biết Feadship là
 * ai, và đó mới là INFERRED.
 */
final class WordNumberNormalizer implements ValueNormalizer
{
    private const WORDS = [
        'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4,
        'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
        'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
        'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30,
        'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70,
        'eighty' => 80, 'ninety' => 90, 'hundred' => 100,
    ];

    public function supports(string $quote, mixed $value): bool
    {
        if (! is_numeric($value) || is_bool($value)) {
            return false;
        }

        $haystack = mb_strtolower($quote);

        foreach (self::WORDS as $word => $number) {
            if ((float) $number !== (float) $value) {
                continue;
            }

            if (preg_match('/(?<![\p{L}])' . $word . '(?![\p{L}])/u', $haystack)) {
                return true;
            }
        }

        return false;
    }

    public function level(): ProvenanceLevel
    {
        return ProvenanceLevel::NormalizedValue;
    }
}
