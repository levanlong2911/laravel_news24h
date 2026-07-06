<?php

namespace Tests\Unit\AFOS\Passes\Temporal;

use App\Services\AI\AFOS\Ir\Temporal\EdgeStore;
use App\Services\AI\AFOS\Ir\Temporal\EventEdge;
use App\Services\AI\AFOS\Ir\Temporal\MotionBeat;
use App\Services\AI\AFOS\Ir\Temporal\NodeRef;
use App\Services\AI\AFOS\Ir\Temporal\RelationType;
use App\Services\AI\AFOS\Ir\Temporal\Validation\CycleError;
use App\Services\AI\AFOS\Ir\Temporal\Validation\DuplicateIdError;
use App\Services\AI\AFOS\Ir\Temporal\Validation\LayerConflictError;
use App\Services\AI\AFOS\Ir\Temporal\Validation\MissingReferenceError;
use App\Services\AI\AFOS\Ir\Temporal\Validation\TemporalConstraintError;
use App\Services\AI\AFOS\Ir\Temporal\Validation\TrackValidator;
use PHPUnit\Framework\TestCase;

final class TrackValidatorTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function beat(string $id, float $start, float $end, string $layer = 'motion'): MotionBeat
    {
        return new MotionBeat($id, $start, $end, 'subject', 'body', 'move', 'linear', 1.0, layer: $layer);
    }

    private function edge(string $fromId, string $toId, RelationType $type, string $trackId = 'motion'): EventEdge
    {
        return new EventEdge(new NodeRef($trackId, $fromId), new NodeRef($trackId, $toId), $type);
    }

    // ── Pass 1: Duplicate IDs ────────────────────────────────────────────────

    public function test_no_errors_on_valid_track(): void
    {
        $events = [$this->beat('a', 0.0, 1.0), $this->beat('b', 1.0, 2.0)];
        $edges  = EdgeStore::empty()->add($this->edge('b', 'a', RelationType::Follows));

        $result = TrackValidator::validate($events, $edges);
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->errors());
    }

    public function test_detects_duplicate_id(): void
    {
        $events = [$this->beat('a', 0.0, 1.0), $this->beat('a', 1.0, 2.0)];
        $errors = TrackValidator::checkDuplicateIds($events);

        $this->assertCount(1, $errors);
        $this->assertInstanceOf(DuplicateIdError::class, $errors[0]);
        $this->assertSame('a', $errors[0]->id);
    }

    // ── Pass 2: Missing references ───────────────────────────────────────────

    public function test_detects_missing_reference(): void
    {
        $events = [$this->beat('b', 0.0, 1.0)];
        $edges  = EdgeStore::empty()->add($this->edge('b', 'ghost', RelationType::Hard));

        $errors = TrackValidator::checkMissingReferences($events, $edges);

        $this->assertCount(1, $errors);
        $this->assertInstanceOf(MissingReferenceError::class, $errors[0]);
        $this->assertSame('b',     $errors[0]->sourceId);
        $this->assertSame('ghost', $errors[0]->targetId);
        $this->assertSame(RelationType::Hard, $errors[0]->relationType);
    }

    public function test_no_missing_reference_error_for_valid_relations(): void
    {
        $events = [$this->beat('a', 0.0, 1.0), $this->beat('b', 1.0, 2.0)];
        $edges  = EdgeStore::empty()->add($this->edge('b', 'a', RelationType::Follows));

        $this->assertEmpty(TrackValidator::checkMissingReferences($events, $edges));
    }

    public function test_cross_track_edges_do_not_trigger_missing_reference(): void
    {
        $events = [$this->beat('b', 0.0, 1.0)];
        // Cross-track edge: from motion to camera — 'camera_kf' not in our event set, but that's OK
        $edges = EdgeStore::empty()->add(
            new EventEdge(NodeRef::motion('b'), NodeRef::camera('camera_kf'), RelationType::Supports)
        );

        $this->assertEmpty(TrackValidator::checkMissingReferences($events, $edges));
    }

    // ── Pass 3: Cycle detection ───────────────────────────────────────────────

    public function test_detects_direct_cycle(): void
    {
        $events = [$this->beat('a', 0.0, 1.0), $this->beat('b', 1.0, 2.0)];
        $edges  = EdgeStore::empty()
            ->add($this->edge('a', 'b', RelationType::Hard))
            ->add($this->edge('b', 'a', RelationType::Hard));

        $errors = TrackValidator::checkCycles($events, $edges);

        $this->assertNotEmpty($errors);
        $this->assertInstanceOf(CycleError::class, $errors[0]);
    }

    public function test_no_cycle_for_linear_chain(): void
    {
        $events = [$this->beat('a', 0.0, 1.0), $this->beat('b', 1.0, 2.0), $this->beat('c', 2.0, 3.0)];
        $edges  = EdgeStore::empty()
            ->add($this->edge('b', 'a', RelationType::Hard))
            ->add($this->edge('c', 'b', RelationType::Hard));

        $this->assertEmpty(TrackValidator::checkCycles($events, $edges));
    }

    public function test_semantic_relations_do_not_trigger_cycle_check(): void
    {
        // Supports/Mirrors back-references are not cycles in the scheduling sense
        $events = [$this->beat('a', 0.0, 1.0), $this->beat('b', 1.0, 2.0)];
        $edges  = EdgeStore::empty()
            ->add($this->edge('a', 'b', RelationType::Supports))
            ->add($this->edge('b', 'a', RelationType::Supports));

        $this->assertEmpty(TrackValidator::checkCycles($events, $edges));
    }

    // ── Pass 4: Temporal constraints ──────────────────────────────────────────

    public function test_hard_relation_violation_when_b_starts_before_a_ends(): void
    {
        // b starts at 0.5 but a ends at 1.0 — temporal violation
        $events = [$this->beat('a', 0.0, 1.0), $this->beat('b', 0.5, 1.5)];
        $edges  = EdgeStore::empty()->add($this->edge('b', 'a', RelationType::Hard));

        $errors = TrackValidator::checkTemporalConstraints($events, $edges);

        $this->assertCount(1, $errors);
        $this->assertInstanceOf(TemporalConstraintError::class, $errors[0]);
        $this->assertSame('b', $errors[0]->eventId);
        $this->assertSame('a', $errors[0]->targetId);
        $this->assertSame(RelationType::Hard, $errors[0]->relationType);
    }

    public function test_hard_relation_valid_when_b_starts_at_a_end(): void
    {
        $events = [$this->beat('a', 0.0, 1.0), $this->beat('b', 1.0, 2.0)];
        $edges  = EdgeStore::empty()->add($this->edge('b', 'a', RelationType::Hard));

        $this->assertEmpty(TrackValidator::checkTemporalConstraints($events, $edges));
    }

    public function test_follows_relation_violation(): void
    {
        $events = [$this->beat('a', 0.0, 2.0), $this->beat('b', 1.0, 3.0)];
        $edges  = EdgeStore::empty()->add($this->edge('b', 'a', RelationType::Follows));

        $errors = TrackValidator::checkTemporalConstraints($events, $edges);
        $this->assertCount(1, $errors);
        $this->assertSame(RelationType::Follows, $errors[0]->relationType);
    }

    public function test_interrupts_relation_does_not_trigger_temporal_error(): void
    {
        // Interrupts: b starting before a ends is intentional
        $events = [$this->beat('a', 0.0, 2.0), $this->beat('b', 1.0, 3.0)];
        $edges  = EdgeStore::empty()->add($this->edge('b', 'a', RelationType::Interrupts));

        $this->assertEmpty(TrackValidator::checkTemporalConstraints($events, $edges));
    }

    // ── Pass 5: Layer conflicts ───────────────────────────────────────────────

    public function test_detects_layer_conflict_on_intersection(): void
    {
        // [0–2s] and [1–3s] in same layer — overlap at [1–2s]
        $events = [
            $this->beat('a', 0.0, 2.0, 'camera'),
            $this->beat('b', 1.0, 3.0, 'camera'),
        ];
        $errors = TrackValidator::checkLayerConflicts($events);

        $this->assertCount(1, $errors);
        $this->assertInstanceOf(LayerConflictError::class, $errors[0]);
        $this->assertSame('camera', $errors[0]->layer);
        $this->assertSame(1.0,     $errors[0]->overlapStart);
        $this->assertSame(2.0,     $errors[0]->overlapEnd);
    }

    public function test_no_layer_conflict_for_adjacent_events(): void
    {
        $events = [
            $this->beat('a', 0.0, 1.0, 'camera'),
            $this->beat('b', 1.0, 2.0, 'camera'),
        ];
        $this->assertEmpty(TrackValidator::checkLayerConflicts($events));
    }

    public function test_no_layer_conflict_across_different_layers(): void
    {
        $events = [
            $this->beat('a', 0.0, 2.0, 'motion'),
            $this->beat('b', 1.0, 3.0, 'camera'), // different layer — no conflict
        ];
        $this->assertEmpty(TrackValidator::checkLayerConflicts($events));
    }

    // ── TimelineValidationResult ──────────────────────────────────────────────

    public function test_validation_result_errors_of_type(): void
    {
        $events = [
            $this->beat('a', 0.0, 1.0),
            $this->beat('a', 1.0, 2.0), // duplicate
            $this->beat('b', 0.5, 1.5),
        ];
        $edges  = EdgeStore::empty()->add($this->edge('b', 'a', RelationType::Hard));

        $result = TrackValidator::validate($events, $edges);

        $this->assertFalse($result->isValid());
        $duplicates = $result->errorsOfType(DuplicateIdError::class);
        $this->assertCount(1, $duplicates);
    }

    public function test_validation_result_merge(): void
    {
        $r1 = TrackValidator::validate([$this->beat('x', 0.0, 1.0)], EdgeStore::empty());
        $r2 = TrackValidator::validate([$this->beat('x', 0.0, 1.0)], EdgeStore::empty());
        $this->assertTrue($r1->isValid());

        $merged = $r1->merge($r2);
        $this->assertTrue($merged->isValid());
    }

    public function test_error_messages_are_strings(): void
    {
        $events = [
            $this->beat('a', 0.0, 2.0, 'camera'),
            $this->beat('b', 1.0, 3.0, 'camera'),
        ];
        $result   = TrackValidator::validate($events, EdgeStore::empty());
        $messages = $result->errorMessages();

        $this->assertNotEmpty($messages);
        foreach ($messages as $msg) {
            $this->assertIsString($msg);
            $this->assertNotEmpty($msg);
        }
    }
}
