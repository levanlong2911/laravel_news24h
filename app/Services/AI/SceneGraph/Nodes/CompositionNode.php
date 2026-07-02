<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * Resolved visual composition layout for a single shot.
 *
 * eyeAnchor (Sprint 5): where the viewer's eye should land first and second.
 * null means no explicit anchor was computed (e.g. WIDE or AERIAL shots).
 *
 * SceneGraphValidator enforces: full_frame + ruleOfThirds=true is invalid.
 * CompositionResolver normalizes it before validation, so this should never fire.
 */
final class CompositionNode
{
    public function __construct(
        public readonly string        $foreground,
        public readonly string        $midground,
        public readonly string        $background,
        public readonly string        $negativeSpace,
        public readonly string        $subjectPosition,
        public readonly bool          $ruleOfThirds,
        public readonly string        $leadingLines,
        public readonly ?EyeAnchorNode $eyeAnchor,
    ) {}

    public static function from(array $data): self
    {
        $eyeRaw = $data['eye_anchor'] ?? [];

        return new self(
            foreground:      $data['foreground']       ?? '',
            midground:       $data['midground']        ?? '',
            background:      $data['background']       ?? '',
            negativeSpace:   $data['negative_space']   ?? 'none',
            subjectPosition: $data['subject_position'] ?? 'center',
            ruleOfThirds:    (bool) ($data['rule_of_thirds'] ?? false),
            leadingLines:    $data['leading_lines']    ?? '',
            eyeAnchor:       $eyeRaw !== [] ? EyeAnchorNode::from($eyeRaw) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'foreground'       => $this->foreground,
            'midground'        => $this->midground,
            'background'       => $this->background,
            'negative_space'   => $this->negativeSpace,
            'subject_position' => $this->subjectPosition,
            'rule_of_thirds'   => $this->ruleOfThirds,
            'leading_lines'    => $this->leadingLines,
            'eye_anchor'       => $this->eyeAnchor?->toArray() ?? [],
        ];
    }
}
