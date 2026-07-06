<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * TemporalPlan — assembled spatio-temporal description of a single shot.
 *
 * Produced by TemporalAssemblyStage from a TrackStore. Tier3Stage reads
 * this to generate time-coded ACTION and CAMERA sections in the PromptIR.
 *
 * Typed convenience accessors (motion(), camera()) cover the built-in track
 * types. Generic get(FQCN) handles future tracks (Physics, Lighting, Focus…)
 * without requiring changes to this class.
 *
 * This is a read-only snapshot — it is never modified after assembly.
 */
final class TemporalPlan
{
    /** @var array<string, TemporalTrack> FQCN → track */
    private readonly array $tracks;

    public function __construct(
        public readonly float $durationSec,
        TemporalTrack ...$tracks,
    ) {
        $indexed = [];
        foreach ($tracks as $track) {
            $indexed[get_class($track)] = $track;
        }
        $this->tracks = $indexed;
    }

    // ── Typed accessors for first-class track types ───────────────────────────

    public function motion(): ?MotionTrack
    {
        return $this->tracks[MotionTrack::class] ?? null;
    }

    public function camera(): ?CameraTrack
    {
        return $this->tracks[CameraTrack::class] ?? null;
    }

    // ── Generic access ────────────────────────────────────────────────────────

    public function get(string $class): ?TemporalTrack
    {
        return $this->tracks[$class] ?? null;
    }

    public function has(string $class): bool
    {
        return isset($this->tracks[$class]);
    }

    /** @return TemporalTrack[] */
    public function all(): array
    {
        return array_values($this->tracks);
    }

    public function trackCount(): int
    {
        return count($this->tracks);
    }

    public function isEmpty(): bool
    {
        return $this->tracks === [];
    }
}
