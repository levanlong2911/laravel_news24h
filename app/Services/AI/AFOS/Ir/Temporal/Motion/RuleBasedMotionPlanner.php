<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Motion;

use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Ir\Temporal\EventEdge;
use App\Services\AI\AFOS\Ir\Temporal\MotionBeat;
use App\Services\AI\AFOS\Ir\Temporal\MotionIntent;
use App\Services\AI\AFOS\Ir\Temporal\MotionTrack;
use App\Services\AI\AFOS\Ir\Temporal\NodeRef;
use App\Services\AI\AFOS\Ir\Temporal\RelationType;
use App\Services\AI\AFOS\Types\GoalType;

/**
 * RuleBasedMotionPlanner — goal-type lookup table implementation of MotionPlanner.
 *
 * Returns a MotionPlan containing:
 *   - MotionTrack: pure event nodes (id, timing, actor/channel/verb, curve, strength, label)
 *   - EventEdge[]: typed graph edges for the TemporalGraph's EdgeStore
 *
 * Beats carry NO embedded relation data — all structure lives in EdgeEdge[].
 * This enforces: nodes on tracks, edges in EdgeStore.
 *
 * Beat IDs are deterministic (actor_channel_verb) so EventEdge from/to NodeRefs
 * reference stable string keys. Edge metadata encodes semantic context:
 *   Hard      → ['reason' => 'kinematics']
 *   Supports  → ['reason' => 'rhythm_anchor'] | ['reason' => 'velocity_response']
 *
 * $label fields on beats are debug-only — serializers must not read them.
 */
final class RuleBasedMotionPlanner implements MotionPlanner
{
    public function plan(ShotGoalIR $goal, CompositionIR $composition): MotionPlan
    {
        $subject = ucfirst(str_replace('_', ' ', $composition->primarySubjectEntity));
        $dur     = $goal->durationSec;
        $energy  = $goal->energy;

        [$beats, $edges] = match ($goal->goalType) {
            GoalType::REVEAL     => $this->reveal($dur, $energy, $subject),
            GoalType::ESTABLISH  => $this->establish($dur, $energy, $subject),
            GoalType::FOLLOW     => $this->follow($dur, $energy, $subject),
            GoalType::DISCOVER   => $this->discover($dur, $energy, $subject),
            GoalType::TRANSITION => $this->transition($dur, $energy, $subject),
            GoalType::RESOLVE    => $this->resolve($dur, $energy, $subject),
        };

        $intent = $this->planIntent($goal);
        $track  = new MotionTrack($beats, $intent);

        return new MotionPlan($track, $edges);
    }

    // ── Goal-type planners — each returns [MotionBeat[], EventEdge[]] ──────────

    private function reveal(float $dur, float $energy, string $subject): array
    {
        $curve = $this->energyCurve($energy);
        $beats = [
            new MotionBeat('subject_body_still',  0.0,         $dur * 0.4, 'subject', 'body', 'still',  'linear',   max(0.1, 1.0 - $energy),
                label: "{$subject} holds still as light begins to reveal"),
            new MotionBeat('subject_body_emerge', $dur * 0.4,  $dur * 0.8, 'subject', 'body', 'emerge', $curve,     $energy * 0.7,
                label: "{$subject} gradually becomes visible, clarity building"),
            new MotionBeat('subject_body_hold',   $dur * 0.8,  $dur,       'subject', 'body', 'hold',   'ease_out', $energy * 0.5,
                label: "{$subject} fully revealed — holds for impact"),
        ];
        $edges = [
            new EventEdge(NodeRef::motion('subject_body_emerge'), NodeRef::motion('subject_body_still'),  RelationType::Follows),
            new EventEdge(NodeRef::motion('subject_body_hold'),   NodeRef::motion('subject_body_emerge'), RelationType::Follows),
        ];
        return [$beats, $edges];
    }

    private function establish(float $dur, float $energy, string $subject): array
    {
        $curve = $this->energyCurve($energy);
        $beats = [
            new MotionBeat('subject_body_settle',            0.0,        $dur * 0.3, 'subject',     'body',       'settle',  'ease_in',  $energy * 0.6,
                label: "{$subject} settles into frame"),
            new MotionBeat('subject_body_present',           $dur * 0.3, $dur * 0.7, 'subject',     'body',       'present', $curve,     $energy * 0.8,
                label: "{$subject} commands the established frame"),
            new MotionBeat('environment_background_breathe', $dur * 0.7, $dur,       'environment', 'background', 'breathe', 'ease_out', $energy * 0.4,
                label: "Environment breathes around {$subject}"),
        ];
        $edges = [
            new EventEdge(NodeRef::motion('subject_body_present'),           NodeRef::motion('subject_body_settle'),  RelationType::Follows),
            new EventEdge(NodeRef::motion('environment_background_breathe'), NodeRef::motion('subject_body_present'), RelationType::Supports),
        ];
        return [$beats, $edges];
    }

    private function follow(float $dur, float $energy, string $subject): array
    {
        $curve = $this->energyCurve($energy);
        $str   = min(1.0, $energy * 1.2);
        $beats = [
            new MotionBeat('subject_foot_plant',          0.0,        $dur * 0.2, 'subject',     'foot',       'plant',  'ease_in', $str,
                label: "{$subject} plants foot, momentum building"),
            new MotionBeat('subject_body_stride',         $dur * 0.2, $dur * 0.5, 'subject',     'body',       'stride', $curve,    $str,
                label: "{$subject} drives forward at full stride"),
            new MotionBeat('subject_arm_pump',            $dur * 0.5, $dur * 0.8, 'subject',     'arm',        'pump',   $curve,    $str * 0.9,
                label: "Arms pump with stride rhythm"),
            new MotionBeat('environment_background_blur', $dur * 0.8, $dur,       'environment', 'background', 'blur',   'linear',  $str * 0.7,
                label: "Background streams past with velocity"),
        ];
        $edges = [
            new EventEdge(NodeRef::motion('subject_body_stride'),         NodeRef::motion('subject_foot_plant'),  RelationType::Hard,     1.0, ['reason' => 'kinematics']),
            new EventEdge(NodeRef::motion('subject_arm_pump'),            NodeRef::motion('subject_body_stride'), RelationType::Follows),
            new EventEdge(NodeRef::motion('subject_arm_pump'),            NodeRef::motion('subject_foot_plant'),  RelationType::Supports, 1.0, ['reason' => 'rhythm_anchor']),
            new EventEdge(NodeRef::motion('environment_background_blur'), NodeRef::motion('subject_body_stride'), RelationType::Supports, 1.0, ['reason' => 'velocity_response']),
        ];
        return [$beats, $edges];
    }

    private function discover(float $dur, float $energy, string $subject): array
    {
        $beats = [
            new MotionBeat('subject_body_scan',  0.0,         $dur * 0.35, 'subject', 'body', 'scan',  'ease_in',  $energy * 0.5,
                label: "{$subject} surveys the space"),
            new MotionBeat('subject_head_turn',  $dur * 0.35, $dur * 0.65, 'subject', 'head', 'turn',  'linear',   $energy * 0.7,
                label: "{$subject} turns toward point of discovery"),
            new MotionBeat('subject_body_react', $dur * 0.65, $dur,        'subject', 'body', 'react', 'ease_out', $energy,
                label: "{$subject} reacts — moment of discovery"),
        ];
        $edges = [
            new EventEdge(NodeRef::motion('subject_head_turn'),  NodeRef::motion('subject_body_scan'), RelationType::Follows),
            new EventEdge(NodeRef::motion('subject_body_react'), NodeRef::motion('subject_head_turn'), RelationType::Follows),
        ];
        return [$beats, $edges];
    }

    private function transition(float $dur, float $energy, string $subject): array
    {
        $curve = $this->energyCurve($energy);
        $beats = [
            new MotionBeat('subject_body_exit_pose',  0.0,         $dur * 0.25, 'subject', 'body', 'exit_pose',  'ease_in',  $energy * 0.8,
                label: "{$subject} exits previous state"),
            new MotionBeat('subject_body_transition', $dur * 0.25, $dur * 0.75, 'subject', 'body', 'transition', $curve,     $energy,
                label: "{$subject} moves through transition"),
            new MotionBeat('subject_body_arrive',     $dur * 0.75, $dur,        'subject', 'body', 'arrive',     'ease_out', $energy * 0.9,
                label: "{$subject} arrives at new position"),
        ];
        $edges = [
            new EventEdge(NodeRef::motion('subject_body_transition'), NodeRef::motion('subject_body_exit_pose'),  RelationType::Follows),
            new EventEdge(NodeRef::motion('subject_body_arrive'),     NodeRef::motion('subject_body_transition'), RelationType::Follows),
        ];
        return [$beats, $edges];
    }

    private function resolve(float $dur, float $energy, string $subject): array
    {
        $beats = [
            new MotionBeat('subject_body_decelerate', 0.0,         $dur * 0.4,  'subject', 'body', 'decelerate', 'ease_out', $energy * 0.8,
                label: "{$subject} decelerates toward rest"),
            new MotionBeat('subject_body_settle',     $dur * 0.4,  $dur * 0.75, 'subject', 'body', 'settle',     'ease_out', $energy * 0.5,
                label: "{$subject} settles into resolution"),
            new MotionBeat('subject_body_hold',       $dur * 0.75, $dur,        'subject', 'body', 'hold',       'linear',   $energy * 0.2,
                label: "{$subject} holds — stillness affirms the resolution"),
        ];
        $edges = [
            new EventEdge(NodeRef::motion('subject_body_settle'), NodeRef::motion('subject_body_decelerate'), RelationType::Follows),
            new EventEdge(NodeRef::motion('subject_body_hold'),   NodeRef::motion('subject_body_settle'),     RelationType::Follows),
        ];
        return [$beats, $edges];
    }

    // ── MotionIntent planning ─────────────────────────────────────────────────

    private function planIntent(ShotGoalIR $shot): MotionIntent
    {
        return new MotionIntent(
            energyArc: match ($shot->goalType) {
                GoalType::REVEAL     => 'build',
                GoalType::ESTABLISH  => 'sustain',
                GoalType::FOLLOW     => 'peak',
                GoalType::DISCOVER   => 'build',
                GoalType::TRANSITION => 'sustain',
                GoalType::RESOLVE    => 'resolve',
            },
            rhythm: match ($shot->goalType) {
                GoalType::REVEAL     => 'legato',
                GoalType::ESTABLISH  => 'steady',
                GoalType::FOLLOW     => 'staccato',
                GoalType::DISCOVER   => 'syncopated',
                GoalType::TRANSITION => 'legato',
                GoalType::RESOLVE    => 'legato',
            },
            emphasis: match ($shot->goalType) {
                GoalType::REVEAL     => 'body',
                GoalType::ESTABLISH  => 'body',
                GoalType::FOLLOW     => 'foot',
                GoalType::DISCOVER   => 'head',
                GoalType::TRANSITION => 'body',
                GoalType::RESOLVE    => 'body',
            },
            continuity: match ($shot->goalType) {
                GoalType::REVEAL     => 'flowing',
                GoalType::ESTABLISH  => 'cyclic',
                GoalType::FOLLOW     => 'flowing',
                GoalType::DISCOVER   => 'interrupted',
                GoalType::TRANSITION => 'flowing',
                GoalType::RESOLVE    => 'flowing',
            },
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function energyCurve(float $energy): string
    {
        if ($energy >= 0.8) {
            return 'elastic';
        }
        if ($energy >= 0.5) {
            return 'ease_in';
        }
        return 'ease_out';
    }
}
