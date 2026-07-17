<?php

namespace App\Video\Evidence\Value;

use App\Video\Evidence\ProvenanceLevel;

/**
 * "€325M" chống lưng cho 325000000 · "$1.2 billion" cho 1200000000
 *
 * Chỉ nhân theo hậu tố quy mô có MẶT trong chữ. Không quy đổi tiền tệ — tỷ giá
 * là tri thức ngoài span, và còn thay đổi theo thời gian.
 */
final class CurrencyNormalizer implements ValueNormalizer
{
    private const PATTERN = '/(?:[€$£¥]|\b(?:EUR|USD|GBP|JPY)\b)\s*'
        . '(\d{1,3}(?:,\d{3})*(?:\.\d+)?|\d+(?:\.\d+)?)\s*'
        . '(k|m|bn|tn|thousand|million|billion|trillion)?\b/iu';

    private const SCALE = [
        ''         => 1,
        'k'        => 1_000,
        'thousand' => 1_000,
        'm'        => 1_000_000,
        'million'  => 1_000_000,
        'bn'       => 1_000_000_000,
        'billion'  => 1_000_000_000,
        'tn'       => 1_000_000_000_000,
        'trillion' => 1_000_000_000_000,
    ];

    public function supports(string $quote, mixed $value): bool
    {
        if (! is_numeric($value) || is_bool($value)) {
            return false;
        }

        if (! preg_match_all(self::PATTERN, $quote, $matches, PREG_SET_ORDER)) {
            return false;
        }

        foreach ($matches as $match) {
            $amount = (float) str_replace(',', '', $match[1]);
            $scale  = self::SCALE[strtolower($match[2] ?? '')] ?? 1;

            if (abs($amount * $scale - (float) $value) < 1e-9) {
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
