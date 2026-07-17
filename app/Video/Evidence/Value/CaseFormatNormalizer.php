<?php

namespace App\Video\Evidence\Value;

use App\Video\Evidence\EvidenceIndex;
use App\Video\Evidence\ProvenanceLevel;

/** Cùng chuỗi, khác hình thức: "Grey" → "grey". Đổi hình thức, không đổi nghĩa. */
final class CaseFormatNormalizer implements ValueNormalizer
{
    public function supports(string $quote, mixed $value): bool
    {
        return is_string($value)
            && EvidenceIndex::normalize($quote) === EvidenceIndex::normalize($value);
    }

    public function level(): ProvenanceLevel
    {
        return ProvenanceLevel::Normalized;
    }
}
