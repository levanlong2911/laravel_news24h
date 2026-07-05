<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from CinematicBeatPlanner::plan().
 *
 * Holds the 4-beat (or 5-beat for 10s clips) cinematic arc:
 *   Hook → Escalation → Reveal → Payoff (→ Resolution)
 *
 * toTimeline() converts to the format KlingRenderer::buildTimelineBlock() expects,
 * so the beat arc can directly replace the MotionPlanner flat timeline without any
 * change to the renderer.
 */
final class CinematicBeatPlan
{
    /**
     * @param array  $beats        [{beat, start, end, camera, subject, intensity}]
     * @param string $arc          'hook_escalation_reveal_payoff' or '…_resolution'
     * @param float  $durationSec  Clip duration this arc was built for
     * @param string $category     Subject category: aerial_vehicle | athletic_action | landscape_nature | product_craft | generic
     */
    public function __construct(
        public readonly array  $beats,
        public readonly string $arc,
        public readonly float  $durationSec,
        public readonly string $category,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            beats:       $data['beats']        ?? [],
            arc:         $data['arc']          ?? 'hook_escalation_reveal_payoff',
            durationSec: (float) ($data['duration_sec'] ?? 5.0),
            category:    $data['category']     ?? 'generic',
        );
    }

    public function toArray(): array
    {
        return [
            'beats'        => $this->beats,
            'arc'          => $this->arc,
            'duration_sec' => $this->durationSec,
            'category'     => $this->category,
        ];
    }

    /**
     * Returns beats as timeline segments compatible with KlingRenderer::buildTimelineBlock().
     * environment and secondary are intentionally omitted here — ScenePlanner::plan()
     * injects physics data back in via injectPhysicsIntoBeatTimeline().
     */
    public function toTimeline(): array
    {
        return array_map(fn(array $b) => [
            'start'   => $b['start'],
            'end'     => $b['end'],
            'camera'  => $b['camera'],
            'subject' => $b['subject'],
            'beat'    => $b['beat'] ?? '',
        ], $this->beats);
    }

    public function isEmpty(): bool
    {
        return $this->beats === [];
    }
}
