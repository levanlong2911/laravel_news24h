<?php

namespace App\Services\AI\SceneGraph\Nodes;

use App\Services\AI\SceneGraph\Enums\Emotion;
use App\Services\AI\SceneGraph\Enums\Pacing;
use App\Services\AI\SceneGraph\Enums\StoryPhase;

/**
 * Model-neutral semantic summary of what a shot is trying to achieve.
 *
 * emotion, pace, and storyPhase are backed enums — always valid by construction.
 * viewerTakeaway (Sprint 5) is the lasting emotional impression the shot leaves.
 */
final class SemanticNode
{
    public function __construct(
        public readonly string     $goal,
        public readonly Emotion    $emotion,
        public readonly Pacing     $pace,
        public readonly string     $primarySubject,
        public readonly string     $secondarySubject,
        public readonly string     $viewerAttention,
        public readonly StoryPhase $storyPhase,
        /** NEW Sprint 5: one-sentence lasting impression this shot should leave */
        public readonly string     $viewerTakeaway,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            goal:             $data['goal']              ?? '',
            emotion:          Emotion::fromDsl($data['emotion'] ?? 'CRAFT'),
            pace:             Pacing::fromString($data['pace']  ?? 'medium'),
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
