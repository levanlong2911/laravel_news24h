<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from CompositionPlanner::plan().
 *
 * eyeAnchor holds the raw anchor data from CompositionPlanner; the
 * CompositionResolver converts it to EyeAnchorNode for the ShotSceneGraph.
 */
final class CompositionPlan
{
    public function __construct(
        public readonly string $foreground,
        public readonly string $midground,
        public readonly string $background,
        public readonly string $negativeSpace,
        public readonly string $subjectPosition,
        public readonly bool   $ruleOfThirds,
        public readonly string $leadingLines,
        /** Raw eye anchor data: {primary, secondary, strength} */
        public readonly array  $eyeAnchor,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            foreground:      $data['foreground']       ?? '',
            midground:       $data['midground']        ?? '',
            background:      $data['background']       ?? '',
            negativeSpace:   $data['negative_space']   ?? 'none',
            subjectPosition: $data['subject_position'] ?? 'center',
            ruleOfThirds:    (bool) ($data['rule_of_thirds'] ?? false),
            leadingLines:    $data['leading_lines']    ?? '',
            eyeAnchor:       $data['eye_anchor']       ?? [],
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
            'eye_anchor'       => $this->eyeAnchor,
        ];
    }
}
