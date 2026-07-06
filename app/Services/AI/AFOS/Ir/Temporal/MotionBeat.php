<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * MotionBeat — one atomic motion event on the timeline.
 *
 * Pure event node: carries semantic fields (actor, channel, verb, curve, strength)
 * and timing (startSec, endSec) only. Graph structure (relations to other beats)
 * lives in the TemporalGraph's EdgeStore as EventEdge[] — not here.
 *
 * Actors:    'subject' | 'camera' | 'cloth' | 'hair' | 'crowd' | 'environment'
 * Channels:  'hips' | 'shoulder' | 'wrist' | 'foot' | 'body' | 'arm' | 'head' | 'background' | 'lens'
 * Verbs:     'still' | 'emerge' | 'hold' | 'settle' | 'present' | 'breathe' |
 *            'plant' | 'stride' | 'pump' | 'blur' | 'scan' | 'turn' | 'react' |
 *            'exit_pose' | 'transition' | 'arrive' | 'decelerate' | 'follow_through' |
 *            'open' | 'snap' | 'rotate'
 * Curves:    'ease_in' | 'ease_out' | 'linear' | 'step' | 'elastic'
 */
final class MotionBeat extends TimelineEvent
{
    public function __construct(
        string          $id,
        float           $startSec,
        float           $endSec,
        public readonly string       $actor,
        public readonly string       $channel,
        public readonly string       $verb,
        public readonly string       $curve,
        public readonly float        $strength,   // 0.0 (imperceptible) – 1.0 (maximum)
        float           $confidence = 1.0,
        EventOrigin     $origin     = EventOrigin::MotionBeatStage,
        int             $priority   = 0,
        string          $layer      = 'motion',
        /** Debug/observability only — NEVER used by serializers or optimizers. */
        ?string         $label      = null,
    ) {
        parent::__construct($id, $startSec, $endSec, $confidence, $origin, $priority, $layer, $label);
    }

    /**
     * Debug-only: human-readable description of this beat.
     * Must NOT be called from serializers or prompt planning passes.
     */
    public function toLabel(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }
        $actor   = ucfirst($this->actor);
        $channel = str_replace('_', ' ', $this->channel);
        $verb    = str_replace('_', ' ', $this->verb);
        return "{$actor} {$channel} {$verb}";
    }
}
