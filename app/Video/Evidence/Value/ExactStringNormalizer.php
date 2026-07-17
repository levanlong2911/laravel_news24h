<?php

namespace App\Video\Evidence\Value;

use App\Video\Evidence\ProvenanceLevel;

/** Giá trị chính là đoạn quote, không đổi một ký tự. */
final class ExactStringNormalizer implements ValueNormalizer
{
    public function supports(string $quote, mixed $value): bool
    {
        return is_string($value) && trim($quote) === $value;
    }

    public function level(): ProvenanceLevel
    {
        return ProvenanceLevel::Direct;
    }
}
