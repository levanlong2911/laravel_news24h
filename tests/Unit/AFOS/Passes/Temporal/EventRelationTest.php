<?php

namespace Tests\Unit\AFOS\Passes\Temporal;

use App\Services\AI\AFOS\Ir\Temporal\EventOrigin;
use App\Services\AI\AFOS\Ir\Temporal\EventRelation;
use App\Services\AI\AFOS\Ir\Temporal\RelationType;
use PHPUnit\Framework\TestCase;

final class EventRelationTest extends TestCase
{
    // ── RelationType ──────────────────────────────────────────────────────────

    public function test_temporal_relations_are_temporal_constraints(): void
    {
        foreach ([RelationType::Hard, RelationType::Follows, RelationType::Interrupts, RelationType::Overlaps] as $type) {
            $this->assertTrue($type->isTemporalConstraint(), "{$type->value} should be temporal");
        }
    }

    public function test_semantic_relations_are_not_temporal_constraints(): void
    {
        foreach ([RelationType::Supports, RelationType::Mirrors, RelationType::BlendsInto] as $type) {
            $this->assertFalse($type->isTemporalConstraint(), "{$type->value} should not be temporal");
        }
    }

    public function test_relation_type_has_seven_cases(): void
    {
        $this->assertCount(7, RelationType::cases());
    }

    public function test_structural_relations(): void
    {
        foreach ([RelationType::Hard, RelationType::Supports, RelationType::Mirrors] as $type) {
            $this->assertTrue($type->isStructural(), "{$type->value} should be structural");
        }
    }

    public function test_non_structural_relations_can_be_rewritten_by_optimizer(): void
    {
        foreach ([RelationType::Follows, RelationType::Overlaps, RelationType::Interrupts, RelationType::BlendsInto] as $type) {
            $this->assertFalse($type->isStructural(), "{$type->value} should be optimizer-rewritable");
        }
    }

    public function test_hard_is_both_temporal_constraint_and_structural(): void
    {
        $this->assertTrue(RelationType::Hard->isTemporalConstraint());
        $this->assertTrue(RelationType::Hard->isStructural());
    }

    // ── EventRelation ─────────────────────────────────────────────────────────

    public function test_event_relation_stores_fields(): void
    {
        $rel = new EventRelation('beat_2', RelationType::Hard, 0.8);

        $this->assertSame('beat_2',         $rel->targetId);
        $this->assertSame(RelationType::Hard, $rel->type);
        $this->assertSame(0.8,              $rel->weight);
    }

    public function test_event_relation_default_weight_is_one(): void
    {
        $rel = new EventRelation('x', RelationType::Follows);
        $this->assertSame(1.0, $rel->weight);
    }

    // ── EventOrigin ───────────────────────────────────────────────────────────

    public function test_event_origin_enum_has_expected_cases(): void
    {
        $values = array_map(fn($c) => $c->value, EventOrigin::cases());

        $this->assertContains('MotionBeatStage',   $values);
        $this->assertContains('CameraArcStage',    $values);
        $this->assertContains('Optimizer',         $values);
        $this->assertContains('HumanEdited',       $values);
        $this->assertContains('LLMGenerated',      $values);
    }

    public function test_event_origin_from_value(): void
    {
        $origin = EventOrigin::from('MotionBeatStage');
        $this->assertSame(EventOrigin::MotionBeatStage, $origin);
    }
}
