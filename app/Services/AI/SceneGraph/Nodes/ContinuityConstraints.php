<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * Renderer constraint flags for the CONTINUITY section.
 *
 * Each flag tells the renderer which aspects MUST match the locked identity.
 * Veo, Kling, Seedance all phrase these directives differently — having
 * structured booleans lets each renderer decide the exact wording.
 */
final class ContinuityConstraints
{
    public function __construct(
        public readonly bool $mustKeepFace,
        public readonly bool $mustKeepJersey,
        public readonly bool $mustKeepWeather,
        public readonly bool $mustKeepPalette,
        public readonly bool $mustKeepCamera,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            mustKeepFace:    (bool) ($data['must_keep_face']    ?? false),
            mustKeepJersey:  (bool) ($data['must_keep_jersey']  ?? false),
            mustKeepWeather: (bool) ($data['must_keep_weather'] ?? false),
            mustKeepPalette: (bool) ($data['must_keep_palette'] ?? false),
            mustKeepCamera:  (bool) ($data['must_keep_camera']  ?? true),
        );
    }

    public function toArray(): array
    {
        return [
            'must_keep_face'    => $this->mustKeepFace,
            'must_keep_jersey'  => $this->mustKeepJersey,
            'must_keep_weather' => $this->mustKeepWeather,
            'must_keep_palette' => $this->mustKeepPalette,
            'must_keep_camera'  => $this->mustKeepCamera,
        ];
    }

    public function hasAnyConstraint(): bool
    {
        return $this->mustKeepFace
            || $this->mustKeepJersey
            || $this->mustKeepWeather
            || $this->mustKeepPalette
            || $this->mustKeepCamera;
    }
}
