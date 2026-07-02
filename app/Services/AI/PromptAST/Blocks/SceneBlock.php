<?php

namespace App\Services\AI\PromptAST\Blocks;

use App\Services\AI\SceneGraph\Enums\Emotion;
use App\Services\AI\SceneGraph\Enums\StoryPhase;

/**
 * Semantic scene context block.
 *
 * Model-agnostic: no lookup-table text, no Kling/Veo wording.
 * KlingSerializer maps lightCode → "warm amber stadium floodlights", etc.
 */
final class SceneBlock
{
    public function __construct(
        public readonly string     $sceneTitle,
        /** DSL light code: W1, W2, G1, N1, N2, D1, S1, S2, C1, C2 */
        public readonly string     $lightCode,
        public readonly Emotion    $emotion,
        public readonly StoryPhase $storyPhase,
    ) {}
}
