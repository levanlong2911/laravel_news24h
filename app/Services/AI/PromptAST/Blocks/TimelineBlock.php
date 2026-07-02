<?php

namespace App\Services\AI\PromptAST\Blocks;

use App\Services\AI\SceneGraph\Nodes\EmotionPoint;
use App\Services\AI\SceneGraph\Nodes\PhaseNode;

/**
 * Temporal choreography block.
 *
 * Holds the event-driven phase sequence (what happens and when).
 * Semantic content lives here; language for each phase lives in the Serializer.
 *
 * Sprint 6 note: Timeline is temporal, Action is semantic.
 * SubjectNode owns the action grammar; TimelineBlock owns timing.
 */
final class TimelineBlock
{
    /**
     * @param PhaseNode[]    $phases       Ordered event-driven segments
     * @param EmotionPoint[] $emotionCurve 3-point emotion intensity curve (Sprint 5)
     */
    public function __construct(
        public readonly array $phases,
        public readonly array $emotionCurve,
        /** Shot total duration in seconds — used for fallback choreography */
        public readonly float $shotDuration,
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->phases);
    }
}
