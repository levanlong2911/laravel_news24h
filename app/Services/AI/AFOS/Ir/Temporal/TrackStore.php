<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * TrackStore — immutable generic container for TemporalTrack instances.
 *
 * PipelineState carries one TrackStore. Stages call put() to add their
 * track; put() returns a new store so PipelineState immutability is preserved.
 *
 * Keyed by FQCN so that any track type can be retrieved by class name:
 *   $store->get(MotionTrack::class)
 *   $store->put(CameraTrack::class, $track)
 *
 * Grows naturally as new track types are added (Physics, Lighting, Focus…)
 * without touching PipelineState or any existing stages.
 */
final class TrackStore
{
    /** @param array<string, TemporalTrack> $tracks FQCN → track instance */
    public function __construct(private readonly array $tracks = []) {}

    public function put(string $class, TemporalTrack $track): self
    {
        $updated = $this->tracks;
        $updated[$class] = $track;
        return new self($updated);
    }

    public function get(string $class): ?TemporalTrack
    {
        return $this->tracks[$class] ?? null;
    }

    public function has(string $class): bool
    {
        return isset($this->tracks[$class]);
    }

    /** @return TemporalTrack[] All stored tracks, in insertion order. */
    public function all(): array
    {
        return array_values($this->tracks);
    }

    /** @return string[] FQCNs of all stored track types. */
    public function types(): array
    {
        return array_keys($this->tracks);
    }

    public function count(): int
    {
        return count($this->tracks);
    }

    public function isEmpty(): bool
    {
        return $this->tracks === [];
    }
}
