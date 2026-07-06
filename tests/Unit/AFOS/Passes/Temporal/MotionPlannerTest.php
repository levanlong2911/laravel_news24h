<?php

namespace Tests\Unit\AFOS\Passes\Temporal;

use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Ir\Temporal\EventEdge;
use App\Services\AI\AFOS\Ir\Temporal\Motion\MotionPlan;
use App\Services\AI\AFOS\Ir\Temporal\Motion\MotionPlanner;
use App\Services\AI\AFOS\Ir\Temporal\Motion\NullMotionVerbRegistry;
use App\Services\AI\AFOS\Ir\Temporal\Motion\RuleBasedMotionPlanner;
use App\Services\AI\AFOS\Ir\Temporal\MotionBeat;
use App\Services\AI\AFOS\Ir\Temporal\MotionTrack;
use App\Services\AI\AFOS\Ir\Temporal\RelationType;
use App\Services\AI\AFOS\Types\CompositionRule;
use App\Services\AI\AFOS\Types\Emotion;
use App\Services\AI\AFOS\Types\EyeFlowDirection;
use App\Services\AI\AFOS\Types\GoalType;
use App\Services\AI\AFOS\Types\NarrativeFunction;
use App\Services\AI\AFOS\Types\NegativeSpaceDirection;
use PHPUnit\Framework\TestCase;

final class MotionPlannerTest extends TestCase
{
    private function makeShot(GoalType $goalType, float $energy = 0.7, float $dur = 6.0): ShotGoalIR
    {
        return new ShotGoalIR(
            shotId:              'test_shot',
            durationSec:         $dur,
            goalType:            $goalType,
            goalTarget:          'athlete',
            viewerShouldNotice:  ['motion'],
            viewerShouldIgnore:  [],
            emotion:             Emotion::POWER,
            energy:              $energy,
            narrativeFunction:   NarrativeFunction::BUILD,
        );
    }

    private function makeComposition(string $subject = 'athlete'): CompositionIR
    {
        return new CompositionIR(
            shotId:                'test_shot',
            primarySubjectEntity:  $subject,
            primarySubjectFrameX:  0.5,
            primarySubjectFrameY:  0.5,
            primarySubjectScale:   0.6,
            negativeSpaceDirection: NegativeSpaceDirection::RIGHT,
            negativeSpaceAmount:   0.3,
            foregroundEntity:      null,
            midgroundEntity:       null,
            backgroundEntity:      null,
            compositionRule:       CompositionRule::RULE_OF_THIRDS,
            eyeFlowDirection:      EyeFlowDirection::LEFT_TO_RIGHT,
            decisionRationale:     'test',
        );
    }

    // ── Interface contract ────────────────────────────────────────────────────

    public function test_rule_based_planner_implements_motion_planner(): void
    {
        $this->assertInstanceOf(MotionPlanner::class, new RuleBasedMotionPlanner());
    }

    public function test_plan_returns_motion_plan(): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::REVEAL), $this->makeComposition());
        $this->assertInstanceOf(MotionPlan::class, $plan);
        $this->assertInstanceOf(MotionTrack::class, $plan->track);
    }

    // ── Beat counts per goal type ─────────────────────────────────────────────

    /** @dataProvider goalTypeBeatCounts */
    public function test_beat_count_per_goal_type(GoalType $type, int $expectedBeats): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot($type), $this->makeComposition());
        $this->assertCount($expectedBeats, $plan->track->beats());
    }

    public static function goalTypeBeatCounts(): array
    {
        return [
            'REVEAL'     => [GoalType::REVEAL,     3],
            'ESTABLISH'  => [GoalType::ESTABLISH,  3],
            'FOLLOW'     => [GoalType::FOLLOW,      4],
            'DISCOVER'   => [GoalType::DISCOVER,    3],
            'TRANSITION' => [GoalType::TRANSITION,  3],
            'RESOLVE'    => [GoalType::RESOLVE,     3],
        ];
    }

    // ── Beat timing spans duration ────────────────────────────────────────────

    public function test_beats_span_full_duration(): void
    {
        $dur     = 8.0;
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::FOLLOW, dur: $dur), $this->makeComposition());

        $this->assertEqualsWithDelta(0.0, $plan->track->startTime(), 0.001);
        $this->assertEqualsWithDelta($dur, $plan->track->endTime(), 0.001);
    }

    // ── Edge wiring ───────────────────────────────────────────────────────────

    public function test_reveal_plan_has_follows_edges(): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::REVEAL), $this->makeComposition());

        // emerge → Follows → still
        $this->assertEdge($plan->edges, 'subject_body_emerge', 'subject_body_still', RelationType::Follows);
        // hold → Follows → emerge
        $this->assertEdge($plan->edges, 'subject_body_hold', 'subject_body_emerge', RelationType::Follows);
    }

    public function test_follow_plan_has_hard_and_supports_edges(): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::FOLLOW), $this->makeComposition());

        // stride → Hard → plant (kinematics)
        $this->assertEdge($plan->edges, 'subject_body_stride', 'subject_foot_plant', RelationType::Hard);
        // blur → Supports → stride (velocity_response)
        $this->assertEdge($plan->edges, 'environment_background_blur', 'subject_body_stride', RelationType::Supports);
    }

    public function test_follow_stride_hard_edge_has_kinematics_metadata(): void
    {
        $planner  = new RuleBasedMotionPlanner();
        $plan     = $planner->plan($this->makeShot(GoalType::FOLLOW), $this->makeComposition());
        $hardEdge = null;

        foreach ($plan->edges as $edge) {
            if ($edge->from->eventId === 'subject_body_stride' && $edge->type === RelationType::Hard) {
                $hardEdge = $edge;
                break;
            }
        }

        $this->assertNotNull($hardEdge);
        $this->assertSame(['reason' => 'kinematics'], $hardEdge->metadata);
    }

    public function test_plan_edges_is_array_of_event_edges(): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::ESTABLISH), $this->makeComposition());

        $this->assertIsArray($plan->edges);
        foreach ($plan->edges as $edge) {
            $this->assertInstanceOf(EventEdge::class, $edge);
        }
    }

    // ── MotionIntent ──────────────────────────────────────────────────────────

    public function test_reveal_intent_has_build_energy_arc(): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::REVEAL), $this->makeComposition());
        $this->assertSame('build', $plan->track->intent->energyArc);
    }

    public function test_follow_intent_has_peak_energy_arc_and_staccato_rhythm(): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::FOLLOW), $this->makeComposition());
        $this->assertSame('peak',     $plan->track->intent->energyArc);
        $this->assertSame('staccato', $plan->track->intent->rhythm);
        $this->assertSame('foot',     $plan->track->intent->emphasis);
    }

    public function test_discover_intent_has_interrupted_continuity(): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::DISCOVER), $this->makeComposition());
        $this->assertSame('interrupted', $plan->track->intent->continuity);
    }

    // ── Energy curves ─────────────────────────────────────────────────────────

    public function test_high_energy_produces_elastic_curve_on_variable_beats(): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::REVEAL, energy: 0.9), $this->makeComposition());
        $emerge  = $this->findBeat($plan->track->beats(), 'subject_body_emerge');
        $this->assertSame('elastic', $emerge->curve);
    }

    public function test_low_energy_produces_ease_out_curve(): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::REVEAL, energy: 0.3), $this->makeComposition());
        $emerge  = $this->findBeat($plan->track->beats(), 'subject_body_emerge');
        $this->assertSame('ease_out', $emerge->curve);
    }

    // ── Subject label in beat labels ──────────────────────────────────────────

    public function test_subject_entity_appears_in_beat_labels(): void
    {
        $planner = new RuleBasedMotionPlanner();
        $plan    = $planner->plan($this->makeShot(GoalType::REVEAL), $this->makeComposition('luxury_yacht'));

        $labels = array_map(fn(MotionBeat $b) => $b->label, $plan->track->beats());
        $this->assertStringContainsString('Luxury yacht', implode(' ', $labels));
    }

    // ── NullMotionVerbRegistry ────────────────────────────────────────────────

    public function test_null_registry_canonical_form_is_identity(): void
    {
        $reg = new NullMotionVerbRegistry();
        $this->assertSame('stride', $reg->canonicalForm('stride'));
        $this->assertSame('emerge', $reg->canonicalForm('emerge'));
    }

    public function test_null_registry_equivalents_returns_only_self(): void
    {
        $reg = new NullMotionVerbRegistry();
        $this->assertSame(['stride'], $reg->equivalents('stride'));
    }

    public function test_null_registry_is_substitutable_only_for_same_verb(): void
    {
        $reg = new NullMotionVerbRegistry();
        $this->assertTrue($reg->isSubstitutable('stride', 'stride'));
        $this->assertFalse($reg->isSubstitutable('stride', 'walk'));
    }

    public function test_null_registry_similarity_is_one_for_same_verb(): void
    {
        $reg = new NullMotionVerbRegistry();
        $this->assertSame(1.0, $reg->similarity('stride', 'stride'));
    }

    public function test_null_registry_similarity_is_zero_for_different_verbs(): void
    {
        $reg = new NullMotionVerbRegistry();
        $this->assertSame(0.0, $reg->similarity('stride', 'walk'));
        $this->assertSame(0.0, $reg->similarity('emerge', 'hold'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param MotionBeat[] $beats */
    private function findBeat(array $beats, string $id): ?MotionBeat
    {
        foreach ($beats as $beat) {
            if ($beat->id === $id) {
                return $beat;
            }
        }
        return null;
    }

    /**
     * Assert that $edges contains an edge from $fromId to $toId of the given type.
     *
     * @param EventEdge[] $edges
     */
    private function assertEdge(array $edges, string $fromId, string $toId, RelationType $type): void
    {
        foreach ($edges as $edge) {
            if ($edge->from->eventId === $fromId && $edge->to->eventId === $toId && $edge->type === $type) {
                $this->assertTrue(true);
                return;
            }
        }
        $this->fail(
            "No {$type->value} edge from '{$fromId}' to '{$toId}' in plan edges. "
            . "Edges: " . implode(', ', array_map(
                fn($e) => "{$e->from->eventId} →[{$e->type->value}]→ {$e->to->eventId}",
                $edges
            ))
        );
    }
}
