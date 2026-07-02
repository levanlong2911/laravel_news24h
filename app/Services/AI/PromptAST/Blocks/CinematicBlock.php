<?php

namespace App\Services\AI\PromptAST\Blocks;

use App\Services\AI\SceneGraph\Enums\Emotion;
use App\Services\AI\SceneGraph\Enums\Pacing;

/**
 * Narrative tone and semantic intent block.
 *
 * Describes the *meaning* of the shot — what the viewer should feel and
 * understand — not how the camera moves. Serializers use this to add
 * model-specific tonal guidance (Kling STYLE section, Veo style hints, etc.).
 *
 * CinematicBlock separates narrative intent from visual technique (StyleBlock).
 */
final class CinematicBlock
{
    public function __construct(
        public readonly Emotion $emotion,
        public readonly Pacing  $pace,
        /** One-sentence goal: what this shot accomplishes in the narrative */
        public readonly string  $goal,
        /** Where the viewer's conscious attention should land */
        public readonly string  $viewerAttention,
        /** The lasting emotional impression after the cut */
        public readonly string  $viewerTakeaway,
    ) {}
}
