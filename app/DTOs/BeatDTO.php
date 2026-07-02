<?php

namespace App\DTOs;

final class BeatDTO
{
    public const INFORMATION_TYPES = ['FACT', 'PROCESS', 'COMPARISON', 'EMOTION', 'SPECULATION', 'DETAIL', 'SUMMARY'];
    public const VISUAL_PRIORITIES = ['HIGH', 'MEDIUM', 'LOW'];

    public function __construct(
        public readonly int    $beatNumber,
        public readonly string $goal,
        public readonly string $viewerQuestion,
        public readonly string $informationType,
        public readonly string $visualPriority,
        public readonly string $emotion,
        public readonly float  $duration,
        public readonly string $transition,
        public readonly string $narrativeIntent,
    ) {}

    public static function fromArray(array $data, int $beatNumber): self
    {
        return new self(
            beatNumber:      $beatNumber,
            goal:            $data['goal'],
            viewerQuestion:  $data['viewer_question'],
            informationType: strtoupper($data['information_type']),
            visualPriority:  strtoupper($data['visual_priority']),
            emotion:         $data['emotion'],
            duration:        (float) $data['duration'],
            transition:      $data['transition'] ?? 'cut',
            narrativeIntent: $data['narrative_intent'],
        );
    }

    public function toArray(): array
    {
        return [
            'beat_number'      => $this->beatNumber,
            'goal'             => $this->goal,
            'viewer_question'  => $this->viewerQuestion,
            'information_type' => $this->informationType,
            'visual_priority'  => $this->visualPriority,
            'emotion'          => $this->emotion,
            'duration'         => $this->duration,
            'transition'       => $this->transition,
            'narrative_intent' => $this->narrativeIntent,
        ];
    }
}
