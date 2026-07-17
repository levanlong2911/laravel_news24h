<?php

namespace App\Video\Evidence\Value;

use App\Video\Evidence\ProvenanceLevel;

/**
 * Thứ hạng: "ranking 166th on Forbes' 2026 list" → 166.
 *
 * Cần class riêng vì `\b166\b` KHÔNG khớp trong "166th" — giữa "166" và "th"
 * không có ranh giới từ, cả hai đều là ký tự từ. CountNormalizer mù trước nó.
 * Đây là lý do thật để tách class, không phải để cho gọn.
 */
final class OrdinalNormalizer implements ValueNormalizer
{
    private const PATTERN = '/(?<!\d)(\d+)(?:st|nd|rd|th)\b/iu';

    public function supports(string $quote, mixed $value): bool
    {
        if (! is_numeric($value) || is_bool($value)) {
            return false;
        }

        if (! preg_match_all(self::PATTERN, $quote, $matches)) {
            return false;
        }

        foreach ($matches[1] as $found) {
            if (abs((float) $found - (float) $value) < 1e-9) {
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
