<?php

declare(strict_types=1);

namespace App\Video\Scene\DTO;

final readonly class FrozenScene
{
    /** @param array<string, mixed> $camera @param array<string, mixed> $motion @param array<string, mixed>|null $metadata */
    public function __construct(
        public string $sceneId,
        public int $sequenceIndex,
        public string $title,
        public string $visualGoal,
        public ?string $fromStateId,
        public string $toStateId,
        public ?string $transitionId,
        public string $continuityMode,
        public array $camera,
        public array $motion,
        public float $durationSeconds,
        public ?array $metadata = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scene_id' => $this->sceneId,
            'sequence_index' => $this->sequenceIndex,
            'title' => $this->title,
            'visual_goal' => $this->visualGoal,
            'from_state_id' => $this->fromStateId,
            'to_state_id' => $this->toStateId,
            'transition_id' => $this->transitionId,
            'continuity_mode' => $this->continuityMode,
            'camera' => $this->camera,
            'motion' => $this->motion,
            'duration_seconds' => $this->durationSeconds,
            'metadata' => $this->metadata,
        ];
    }
}
