<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Validation;

use App\Services\AI\AFOS\Ir\Temporal\EdgeStore;
use App\Services\AI\AFOS\Ir\Temporal\RelationType;
use App\Services\AI\AFOS\Ir\Temporal\TimelineEvent;

/**
 * TrackValidator — multi-pass validator for a TimelineTrack's event set.
 *
 * Each public static method is an independent pass, testable in isolation.
 * validate() runs all passes in order and aggregates results.
 *
 * Validation passes (in order):
 *   1. DuplicateId         — no two events share the same id
 *   2. MissingReference    — every same-track edge target refers to an existing event
 *   3. Cycle               — no directed cycles via Hard same-track edges
 *   4. TemporalConstraint  — Hard/Follows: B.start >= A.end (same-track)
 *   5. LayerConflict       — no two events in the same layer have overlapping windows
 *
 * Passes 2–4 require an EdgeStore (provided by the owning TemporalGraph).
 * Cross-track edge targets are not validated here — that is the graph's concern.
 */
final class TrackValidator
{
    /**
     * @param TimelineEvent[] $events
     */
    public static function validate(array $events, EdgeStore $edges): TimelineValidationResult
    {
        $result = TimelineValidationResult::ok();

        $result = $result->merge(new TimelineValidationResult(self::checkDuplicateIds($events)));
        $result = $result->merge(new TimelineValidationResult(self::checkMissingReferences($events, $edges)));
        $result = $result->merge(new TimelineValidationResult(self::checkCycles($events, $edges)));
        $result = $result->merge(new TimelineValidationResult(self::checkTemporalConstraints($events, $edges)));
        $result = $result->merge(new TimelineValidationResult(self::checkLayerConflicts($events)));

        return $result;
    }

    // ── Pass 1: Duplicate IDs ────────────────────────────────────────────────

    /** @return DuplicateIdError[] */
    public static function checkDuplicateIds(array $events): array
    {
        $seen   = [];
        $errors = [];

        foreach ($events as $event) {
            if (isset($seen[$event->id])) {
                $errors[] = new DuplicateIdError($event->id);
            }
            $seen[$event->id] = true;
        }

        return $errors;
    }

    // ── Pass 2: Missing references ───────────────────────────────────────────

    /**
     * For every same-track edge where the source event is in this event set,
     * verify the target event also exists. Cross-track edges are skipped.
     *
     * @return MissingReferenceError[]
     */
    public static function checkMissingReferences(array $events, EdgeStore $edges): array
    {
        $eventIds = [];
        foreach ($events as $event) {
            $eventIds[$event->id] = true;
        }

        $errors = [];
        foreach ($edges->all() as $edge) {
            // Skip cross-track edges — foreign-track targets are another track's concern
            if ($edge->from->trackId !== $edge->to->trackId) {
                continue;
            }
            if (isset($eventIds[$edge->from->eventId]) && !isset($eventIds[$edge->to->eventId])) {
                $errors[] = new MissingReferenceError($edge->from->eventId, $edge->to->eventId, $edge->type);
            }
        }

        return $errors;
    }

    // ── Pass 3: Cycle detection (Hard same-track edges only) ─────────────────

    /** @return CycleError[] */
    public static function checkCycles(array $events, EdgeStore $edges): array
    {
        $eventIds = [];
        $adj      = [];
        $colors   = [];

        foreach ($events as $event) {
            $eventIds[$event->id]  = true;
            $colors[$event->id]    = 'white';
            $adj[$event->id]       = [];
        }

        foreach ($edges->all() as $edge) {
            if ($edge->type !== RelationType::Hard) {
                continue;
            }
            if ($edge->from->trackId !== $edge->to->trackId) {
                continue;
            }
            if (!isset($eventIds[$edge->from->eventId])) {
                continue;
            }
            $adj[$edge->from->eventId][] = $edge->to->eventId;
        }

        $errors = [];
        $path   = [];

        $visit = function (string $id) use (&$visit, &$adj, &$colors, &$errors, &$path): void {
            $colors[$id] = 'grey';
            $path[]      = $id;

            foreach ($adj[$id] ?? [] as $dep) {
                if (!isset($colors[$dep])) {
                    continue; // missing dep already caught in pass 2
                }
                if ($colors[$dep] === 'grey') {
                    $errors[] = new CycleError(array_merge($path, [$dep]));
                } elseif ($colors[$dep] === 'white') {
                    $visit($dep);
                }
            }

            array_pop($path);
            $colors[$id] = 'black';
        };

        foreach (array_keys($colors) as $id) {
            if ($colors[$id] === 'white') {
                $visit($id);
            }
        }

        return $errors;
    }

    // ── Pass 4: Temporal constraint check ────────────────────────────────────

    /**
     * Hard/Follows: the from-event must start at or after the to-event ends.
     * Only checks same-track edges where both endpoints are in this event set.
     *
     * @return TemporalConstraintError[]
     */
    public static function checkTemporalConstraints(array $events, EdgeStore $edges): array
    {
        $index  = [];
        $errors = [];

        foreach ($events as $event) {
            $index[$event->id] = $event;
        }

        foreach ($edges->all() as $edge) {
            if ($edge->from->trackId !== $edge->to->trackId) {
                continue;
            }
            if (!isset($index[$edge->from->eventId])) {
                continue;
            }

            $target = $index[$edge->to->eventId] ?? null;
            if ($target === null) {
                continue; // missing target already caught in pass 2
            }

            $event = $index[$edge->from->eventId];

            if ($edge->type === RelationType::Hard || $edge->type === RelationType::Follows) {
                // from-event must start at or after to-event ends
                if ($event->startSec < $target->endSec) {
                    $errors[] = new TemporalConstraintError(
                        $event->id, $target->id, $edge->type,
                        $event->startSec, $target->endSec,
                    );
                }
            }
            // Interrupts: from-event starting before to-event ends is intentional — no check
        }

        return $errors;
    }

    // ── Pass 5: Layer conflict ────────────────────────────────────────────────

    /** @return LayerConflictError[] */
    public static function checkLayerConflicts(array $events): array
    {
        $byLayer = [];
        foreach ($events as $event) {
            $byLayer[$event->layer][] = $event;
        }

        $errors = [];

        foreach ($byLayer as $layer => $layerEvents) {
            usort($layerEvents, fn($a, $b) => $a->startSec <=> $b->startSec);

            for ($i = 0; $i < count($layerEvents) - 1; $i++) {
                $a = $layerEvents[$i];
                $b = $layerEvents[$i + 1];

                if ($a->endSec > $b->startSec) {
                    $errors[] = new LayerConflictError(
                        $a->id, $b->id, (string) $layer,
                        $b->startSec, min($a->endSec, $b->endSec),
                    );
                }
            }
        }

        return $errors;
    }
}
