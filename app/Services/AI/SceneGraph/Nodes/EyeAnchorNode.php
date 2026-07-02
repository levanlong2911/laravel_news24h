<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * Visual eye anchor — where the viewer's eye should land first and second.
 *
 * Sprint 6 (Composition Engine) will use this to guide negative space,
 * depth of field, and lighting emphasis. Renderers can use primary/secondary
 * as explicit attention directives in the CAMERA or STYLE section.
 *
 * strength: 0.0 (wide shots, weak anchor) → 1.0 (macro/close, hard anchor)
 */
final class EyeAnchorNode
{
    public function __construct(
        /** Primary attention target — where the eye lands first */
        public readonly string $primary,
        /** Secondary attention target — where the eye moves next */
        public readonly string $secondary,
        /** Anchor pull strength: 0.0–1.0 */
        public readonly float  $strength,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            primary:   $data['primary']   ?? '',
            secondary: $data['secondary'] ?? '',
            strength:  min(1.0, max(0.0, (float) ($data['strength'] ?? 0.7))),
        );
    }

    public function toArray(): array
    {
        return [
            'primary'   => $this->primary,
            'secondary' => $this->secondary,
            'strength'  => $this->strength,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->primary === '';
    }
}
