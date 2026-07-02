<?php

namespace App\Services\AI\ScenePlanner\Plans;

use App\Services\AI\SceneGraph\Enums\Emotion;
use App\Services\AI\SceneGraph\Enums\Pacing;
use App\Services\AI\SceneGraph\Enums\StoryPhase;

/**
 * Typed result from ScenePlanner::buildSemanticIntent().
 *
 * viewerTakeaway is new in Sprint 5: the one emotional impression the viewer
 * should leave the shot with. Used by Sprint 6 Emotional Engine.
 */
final class SemanticPlan
{
    public function __construct(
        public readonly string     $goal,
        public readonly Emotion    $emotion,
        public readonly Pacing     $pace,
        public readonly string     $primarySubject,
        public readonly string     $secondarySubject,
        public readonly string     $viewerAttention,
        public readonly StoryPhase $storyPhase,
        /** NEW Sprint 5: the lasting emotional impression this shot leaves */
        public readonly string     $viewerTakeaway,
    ) {}

    public static function fromArray(array $data): self
    {
        $emoCode = strtoupper($data['emotion'] ?? 'CRAFT');

        return new self(
            goal:             $data['goal']              ?? '',
            emotion:          Emotion::fromDsl($emoCode),
            pace:             Pacing::fromString($data['pace']           ?? 'medium'),
            primarySubject:   $data['primary_subject']   ?? 'subject',
            secondarySubject: $data['secondary_subject'] ?? '',
            viewerAttention:  $data['viewer_attention']  ?? 'focus on subject execution',
            storyPhase:       StoryPhase::fromString($data['story_phase'] ?? 'build'),
            viewerTakeaway:   $data['viewer_takeaway']   ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'goal'              => $this->goal,
            'emotion'           => $this->emotion->label(),
            'pace'              => $this->pace->value,
            'primary_subject'   => $this->primarySubject,
            'secondary_subject' => $this->secondarySubject,
            'viewer_attention'  => $this->viewerAttention,
            'story_phase'       => $this->storyPhase->value,
            'viewer_takeaway'   => $this->viewerTakeaway,
        ];
    }
}
