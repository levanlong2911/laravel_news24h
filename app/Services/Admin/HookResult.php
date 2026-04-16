<?php

namespace App\Services\Admin;

/**
 * Value Object — output của HookEngine.
 * Immutable. Truyền bestHook vào sonnetPrompt() như title anchor.
 */
final class HookResult
{
    public function __construct(
        public readonly string $bestHook,       // hook được chọn → anchor cho Sonnet
        public readonly string $detectedType,   // type_code Phase 2 detect → articles.hook_type
        public readonly array  $candidates,     // toàn bộ hooks đã generate (debug / A/B test)
        public readonly int    $bestScore,      // virality score của hook được chọn → articles.hook_score
        public readonly int    $hookRank,       // vị trí 1-based trong candidates → articles.hook_rank
        // rank=1 → model ngay từ đầu tốt; rank=4-5 → scoring đang "cứu" output
    ) {}
}
