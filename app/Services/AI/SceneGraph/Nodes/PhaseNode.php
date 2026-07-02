<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * A single time slice within the shot timeline.
 *
 * camera, environment, secondary are optional — not every phase has a
 * camera beat assigned by MotionPlanner or an environment injection from
 * enrichTimeline(). Renderers should render only non-empty fields.
 */
final class PhaseNode
{
    public function __construct(
        public readonly float  $start,
        public readonly float  $end,
        public readonly string $subject,
        public readonly string $camera,
        public readonly string $environment,
        public readonly string $secondary,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            start:       (float) ($data['start']       ?? 0.0),
            end:         (float) ($data['end']         ?? 1.0),
            subject:     $data['subject']     ?? '',
            camera:      $data['camera']      ?? '',
            environment: $data['environment'] ?? '',
            secondary:   $data['secondary']   ?? '',
        );
    }

    public function toArray(): array
    {
        $out = [
            'start'   => $this->start,
            'end'     => $this->end,
            'subject' => $this->subject,
        ];
        if ($this->camera !== '') {
            $out['camera'] = $this->camera;
        }
        if ($this->environment !== '') {
            $out['environment'] = $this->environment;
        }
        if ($this->secondary !== '') {
            $out['secondary'] = $this->secondary;
        }
        return $out;
    }

    public function duration(): float
    {
        return $this->end - $this->start;
    }
}
