<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * TemporalGraphView — read-only interface over a full temporal graph (tracks + edges).
 *
 * Extends TrackCollectionView (track-only access) with edge traversal and event lookup.
 * Consumers that need only track data should type-hint TrackCollectionView instead;
 * those that need graph traversal (edge queries, event correlation) use this interface.
 *
 * Implemented by: FrozenTemporalGraph (the sealed post-FREEZE product)
 */
interface TemporalGraphView extends TrackCollectionView
{
    public function edges(): EdgeStore;
    public function findEvent(string $id): ?TimelineEvent;
}
