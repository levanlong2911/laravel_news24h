<?php

namespace App\Video\World;

use App\Video\Evidence\Evidence;
use App\Video\Evidence\ProvenanceLevel;

/**
 * Một thuộc tính ĐÃ qua Gatekeeper. Đây là sự thật.
 *
 * Evidence được giữ lại để debug và audit ở phía Laravel, và KHÔNG BAO GIỜ
 * được serialize sang RenderPlan. Xem ARCHITECTURE.md §1.
 */
final class VerifiedAttribute
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
        public readonly Evidence $evidence,
        public readonly ProvenanceLevel $level,
    ) {
    }
}
