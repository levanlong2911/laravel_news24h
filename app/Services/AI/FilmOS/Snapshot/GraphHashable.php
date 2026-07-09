<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Marker interface: this graph element participates in canonical hashing.
 *
 * Concrete contracts are split by element type:
 *   HashableNode — for graph nodes (returns CanonicalNode)
 *   HashableEdge — for graph edges (returns CanonicalEdge)
 *
 * GraphHash::of() enforces that every node in a Graph implements HashableNode.
 * Edges without HashableEdge fall back to a minimal {from, to} representation.
 *
 * Phase C canonical contract for CapabilityNode / ProviderNode:
 *   canonicalNode() → CanonicalNode(id, type='capability', kind=capabilityType)
 *   NOT: display name, description, version string
 *
 * Phase D canonical contract for EventNode:
 *   canonicalNode() → CanonicalNode(id, type='event', kind=eventCategory)
 *   NOT: timestamp, uuid, payload
 */
interface GraphHashable {}
