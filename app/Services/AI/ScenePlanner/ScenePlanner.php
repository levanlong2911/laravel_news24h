<?php

namespace App\Services\AI\ScenePlanner;

use App\Services\AI\ScenePlanner\Plans\ActionPlan;
use App\Services\AI\ScenePlanner\Plans\CompositionPlan;
use App\Services\AI\ScenePlanner\Plans\ContinuityPlan;
use App\Services\AI\ScenePlanner\Plans\DirectorPlan;
use App\Services\AI\ScenePlanner\Plans\PhysicsPlan;
use App\Services\AI\ScenePlanner\Plans\SemanticPlan;
use App\Services\AI\SceneGraph\Nodes\TimelineNode;

/**
 * Orchestrates six scene-level planners that enrich a shot DSL before it
 * reaches the PromptCompiler. All planners are rule-based — zero AI cost.
 *
 * Pipeline (Sprint 3):
 *
 *   ActionPlanner  → action_plan{} — event-driven phases + camera_beats + physics_triggers
 *         ↓
 *   MotionPlanner  → timeline[] — camera descriptions placed at timed beat positions
 *         ↓
 *   PhysicsPlanner + DirectorPlanner + CompositionPlanner  (all read action_plan; independent)
 *         ↓
 *   enrichTimeline() — merges atmosphere + background from physics into timeline segments
 *         ↓
 *   ContinuityPlanner → continuity_plan{} — identity lock + dynamic state chain
 *         ↓
 *   semantic_intent{} — cross-model summary built from all above
 *
 * DSL keys added by enrich():
 *   action_plan{}      — rich ActionPlanner result
 *   timeline[]         — event-driven choreography with camera + environment + secondary
 *   physics{}          — {atmosphere, interaction, background, micro_motion}
 *   director{}         — {pacing, lens, height, stabilization, composition, rack_focus, …}
 *   composition{}      — {foreground, midground, background, subject_position, leading_lines, …}
 *   continuity_plan{}  — {character, environment, camera, constraints, previous_state}
 *   semantic_intent{}  — {goal, emotion, pace, primary_subject, secondary_subject, viewer_attention, story_phase}
 */
final class ScenePlanner
{
    /** emotion_code → story narrative phase */
    private const EMO_STORY_PHASE = [
        'HOOK'   => 'setup',   'REVEAL' => 'setup',
        'TENSE'  => 'build',   'DRAMA'  => 'build',  'CRAFT' => 'build',
        'POWER'  => 'climax',  'EPIC'   => 'climax', 'AWE'   => 'climax', 'JOY' => 'climax',
        'CALM'   => 'resolve', 'FEAR'   => 'resolve',
    ];

    public function __construct(
        private readonly ActionPlanner      $actionPlanner,
        private readonly MotionPlanner      $motionPlanner,
        private readonly PhysicsPlanner     $physicsPlanner,
        private readonly DirectorPlanner    $directorPlanner,
        private readonly CompositionPlanner $compositionPlanner,
        private readonly ContinuityPlanner  $continuityPlanner,
    ) {}

    /**
     * Run all planners and return a typed ScenePlanningResult.
     *
     * This is the primary method for the Sprint 4 pipeline:
     *   ScenePlanner::plan() → ScenePlanningResult → SceneGraphBuilder → ShotSceneGraph
     *
     * @param  array $dsl  Shot DSL — must include scene_id and shot_order (added by GraphAssembler)
     */
    public function plan(array $dsl): ScenePlanningResult
    {
        $context      = ShotContext::fromDsl($dsl);
        $actionResult = $this->actionPlanner->plan($dsl);

        // MotionPlanner needs action_plan in DSL for cam + motion_level access
        $dsl['action_plan'] = $actionResult;
        $timeline           = $this->motionPlanner->plan($dsl, $actionResult);

        $physics     = $this->physicsPlanner->plan($dsl, $actionResult);
        $director    = $this->directorPlanner->plan($dsl);
        $composition = $this->compositionPlanner->plan($dsl, $actionResult);

        $enrichedTimeline = $this->enrichTimeline($timeline, $physics);
        $continuityPlan   = $this->continuityPlanner->plan($dsl, $actionResult, $physics, $director);
        $semanticIntent   = $this->buildSemanticIntent($dsl, $director);

        return new ScenePlanningResult(
            context:     $context,
            action:      ActionPlan::fromArray($actionResult),
            physics:     PhysicsPlan::fromArray($physics),
            director:    DirectorPlan::fromArray($director),
            composition: CompositionPlan::fromArray($composition),
            continuity:  ContinuityPlan::fromArray($continuityPlan),
            semantic:    SemanticPlan::fromArray($semanticIntent),
            timeline:    TimelineNode::fromArray($enrichedTimeline),
        );
    }

    /**
     * Backward-compat: enrich DSL array for callers not yet migrated to ScenePlanningResult.
     *
     * @deprecated Use plan() + SceneGraphBuilder instead.
     * @param  array $dsl  Shot DSL
     * @return array       Same DSL with all planner outputs merged in
     */
    public function enrich(array $dsl): array
    {
        $result   = $this->plan($dsl);
        $enriched = $dsl;  // original DSL — result->dsl no longer exists in Sprint 5

        $enriched['action_plan']     = $result->action->toArray();
        $enriched['timeline']        = $result->timeline->toArray();
        $enriched['physics']         = $result->physics->toArray();
        $enriched['director']        = $result->director->toArray();
        $enriched['composition']     = $result->composition->toArray();
        $enriched['continuity_plan'] = $result->continuity->toArray();
        $enriched['semantic_intent'] = $result->semantic->toArray();

        return $enriched;
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Merge physics data into timeline segments so each segment can carry
     * environment and secondary fields — no Renderer inference needed.
     *
     * Distribution:
     *   Phase 0        → environment = atmosphere[0], secondary = background[0]
     *   Phase at ~70%  → environment = interaction[0]  (physics peak)
     *   Last phase     → secondary = last background item (crowd at full reaction)
     */
    private function enrichTimeline(array $timeline, array $physics): array
    {
        $total = count($timeline);
        if ($total === 0) {
            return $timeline;
        }

        $atmosphere  = $physics['atmosphere']  ?? [];
        $interaction = $physics['interaction'] ?? [];
        $background  = $physics['background']  ?? [];
        $bgCount     = count($background);
        $peakIdx     = (int) round($total * 0.70);

        if (isset($atmosphere[0]) && $atmosphere[0] !== '') {
            $timeline[0]['environment'] = $atmosphere[0];
        }
        if ($bgCount > 0 && $background[0] !== '') {
            $timeline[0]['secondary'] = $background[0];
        }
        if ($peakIdx < $total && isset($interaction[0]) && $interaction[0] !== '') {
            $timeline[$peakIdx]['environment'] = $interaction[0];
        }
        if ($bgCount > 1) {
            $timeline[$total - 1]['secondary'] = $background[$bgCount - 1];
        }

        return $timeline;
    }

    /**
     * Semantic intent: a model-neutral summary of what this shot is trying to achieve.
     * Veo, Seedance, Hailuo renderers all read from here to build their framing.
     */
    private function buildSemanticIntent(array $dsl, array $director): array
    {
        $emoCode = $dsl['emo'] ?? 'CRAFT';

        return [
            'goal'              => $dsl['camera_goal'] ?? $dsl['scene_title'] ?? '',
            'emotion'           => strtolower($emoCode),
            'pace'              => $director['pacing'] ?? 'medium',
            'primary_subject'   => $dsl['sub']['actor'] ?? 'subject',
            'secondary_subject' => $dsl['sub']['obj']   ?? '',
            'viewer_attention'  => ($director['shot_priority'] ?? 'subject') === 'subject'
                ? 'focus on subject execution'
                : 'take in the full environment',
            'story_phase'       => self::EMO_STORY_PHASE[$emoCode] ?? 'build',
        ];
    }
}
