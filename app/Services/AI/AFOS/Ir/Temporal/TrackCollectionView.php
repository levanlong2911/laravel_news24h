<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * TrackCollectionView — read-only interface over the track layer of a temporal graph.
 *
 * Contains only track-access methods. Consumers that need only tracks (prompt planners,
 * action-section renderers, camera-section renderers) should type-hint this narrower
 * interface instead of the full TemporalGraphView, keeping the dependency surface minimal.
 *
 * Extended by: TemporalGraphView (adds edges and event lookup)
 * Implemented by: FrozenTemporalGraph (via TemporalGraphView)
 */
interface TrackCollectionView
{
    public function motion(): ?MotionTrack;
    public function camera(): ?CameraTrack;
    public function get(string $trackId): ?TimelineTrack;
    public function has(string $trackId): bool;
    /** @return TimelineTrack[] */
    public function all(): array;
    /** @return string[] Domain TrackIds. */
    public function trackIds(): array;
    public function trackCount(): int;
    public function isEmpty(): bool;
}
