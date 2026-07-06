<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * NodeRef — a stable, domain-level pointer to a single event in the TemporalGraph.
 *
 * Uses TrackId (a domain string constant like 'motion', 'camera') rather than
 * FQCN so that renaming or moving a class never invalidates serialized references.
 *
 * Design:
 *   - trackId  = domain identity of the track, set as ::ID on each track class.
 *   - eventId  = deterministic ID of the event within that track.
 *   - key()    = composite string for use as array key / map key / hash.
 *
 * Factories for first-class tracks:
 *   NodeRef::motion('subject_body_stride')
 *   NodeRef::camera('keyframe_0')
 *
 * Cross-track edges in EdgeStore use NodeRef to point from one track to another:
 *   Motion 'subject_body_stride' ──Influences──▶ Camera 'follow_shot'
 */
final class NodeRef
{
    public function __construct(
        /** Domain track identity — 'motion', 'camera', 'lighting', 'physics' */
        public readonly string $trackId,
        /** Deterministic event ID within the track. */
        public readonly string $eventId,
    ) {}

    // ── Named constructors for first-class track types ────────────────────────

    public static function motion(string $eventId): self
    {
        return new self('motion', $eventId);
    }

    public static function camera(string $eventId): self
    {
        return new self('camera', $eventId);
    }

    public static function physics(string $eventId): self
    {
        return new self('physics', $eventId);
    }

    public static function lighting(string $eventId): self
    {
        return new self('lighting', $eventId);
    }

    // ── Value semantics ───────────────────────────────────────────────────────

    /** Composite string suitable as array key. */
    public function key(): string
    {
        return "{$this->trackId}:{$this->eventId}";
    }

    public function equals(self $other): bool
    {
        return $this->trackId === $other->trackId
            && $this->eventId  === $other->eventId;
    }

    public function isSameTrack(self $other): bool
    {
        return $this->trackId === $other->trackId;
    }
}
