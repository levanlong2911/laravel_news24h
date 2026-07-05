<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from RevealPlanner::plan().
 *
 * Holds the selected reveal mechanism and the camera instruction that
 * ScenePlanner::injectRevealMechanism() prepends to the reveal beat's
 * camera directive. The mechanism determines HOW the subject is disclosed
 * at the reveal beat — not just that it is disclosed.
 */
final class RevealPlan
{
    /**
     * @param string $mechanism          Type token: through_cloud | through_occluder | light_bloom |
     *                                   focus_pull | parallax_pass | orbit_reveal | fog_clear | silhouette_break
     * @param string $triggerBeat        Beat where the reveal fires (default: 'reveal')
     * @param string $cameraInstruction  Text to prepend to the trigger beat camera directive
     * @param string $description        Human-readable summary of the mechanism (for enrich() output)
     */
    public function __construct(
        public readonly string $mechanism,
        public readonly string $triggerBeat,
        public readonly string $cameraInstruction,
        public readonly string $description,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            mechanism:         $data['mechanism']           ?? 'focus_pull',
            triggerBeat:       $data['trigger_beat']        ?? 'reveal',
            cameraInstruction: $data['camera_instruction']  ?? '',
            description:       $data['description']         ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'mechanism'          => $this->mechanism,
            'trigger_beat'       => $this->triggerBeat,
            'camera_instruction' => $this->cameraInstruction,
            'description'        => $this->description,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->cameraInstruction === '';
    }
}
