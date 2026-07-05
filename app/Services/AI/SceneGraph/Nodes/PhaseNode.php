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
        /** DSL token from CameraEnergyPlanner — KlingSerializer translates to model-specific phrase */
        public readonly string $velocityToken = '',
        /** Beat name from CinematicBeatPlanner — used by SecondaryMotionPlanner injection */
        public readonly string $beat          = '',
    ) {}

    public static function from(array $data): self
    {
        return new self(
            start:         (float) ($data['start']          ?? 0.0),
            end:           (float) ($data['end']            ?? 1.0),
            subject:       $data['subject']      ?? '',
            camera:        $data['camera']       ?? '',
            environment:   $data['environment']  ?? '',
            secondary:     $data['secondary']    ?? '',
            velocityToken: $data['velocity_token'] ?? '',
            beat:          $data['beat']           ?? '',
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
        if ($this->velocityToken !== '') {
            $out['velocity_token'] = $this->velocityToken;
        }
        if ($this->beat !== '') {
            $out['beat'] = $this->beat;
        }
        return $out;
    }

    public function duration(): float
    {
        return $this->end - $this->start;
    }
}
