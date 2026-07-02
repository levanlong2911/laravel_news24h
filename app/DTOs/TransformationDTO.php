<?php

namespace App\DTOs;

final class TransformationDTO
{
    public function __construct(
        public readonly string $theme,
        public readonly string $style,
        public readonly int    $duration,
        public readonly array  $emotionArc,
        public readonly string $colorPalette,
        public readonly string $pacing,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            theme:        $data['theme'],
            style:        $data['style'],
            duration:     (int) $data['duration'],
            emotionArc:   $data['emotion_arc'],
            colorPalette: $data['color_palette'],
            pacing:       $data['pacing'],
        );
    }

    public function toArray(): array
    {
        return [
            'theme'         => $this->theme,
            'style'         => $this->style,
            'duration'      => $this->duration,
            'emotion_arc'   => $this->emotionArc,
            'color_palette' => $this->colorPalette,
            'pacing'        => $this->pacing,
        ];
    }
}
