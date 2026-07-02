<?php

namespace App\DTOs;

/**
 * Internal DTO — lives only in memory during SceneShotPlanner execution.
 * NOT persisted to output_json. Logged (minimally) in decision_trace only.
 * Claude generates the fields; code assigns beat_number and moment_index.
 */
final class VisualMomentDTO
{
    public const IMPORTANCE_HIGH   = 'HIGH';
    public const IMPORTANCE_MEDIUM = 'MEDIUM';
    public const IMPORTANCE_LOW    = 'LOW';

    public const IMPORTANCES = [self::IMPORTANCE_HIGH, self::IMPORTANCE_MEDIUM, self::IMPORTANCE_LOW];

    public function __construct(
        public readonly int    $beatNumber,
        public readonly int    $momentIndex,
        public readonly string $visualIntent,   // "Reveal leather stitching on new seat"
        public readonly string $subject,         // free-text subject description
        public readonly string $action,          // what is happening in frame
        public readonly float  $durationHint,   // Claude's suggested seconds
        public readonly string $importance,      // HIGH | MEDIUM | LOW
    ) {}

    public static function fromArray(array $data, int $beatNumber, int $momentIndex): self
    {
        return new self(
            beatNumber:   $beatNumber,
            momentIndex:  $momentIndex,
            visualIntent: $data['visual_intent'],
            subject:      $data['subject'],
            action:       $data['action'],
            durationHint: (float) $data['duration_hint'],
            importance:   strtoupper($data['importance'] ?? 'MEDIUM'),
        );
    }

    /** Minimal representation for decision_trace (no full prompt data). */
    public function toTraceArray(): array
    {
        return [
            'beat'        => $this->beatNumber,
            'moment'      => $this->momentIndex,
            'intent'      => $this->visualIntent,
            'importance'  => $this->importance,
        ];
    }
}
