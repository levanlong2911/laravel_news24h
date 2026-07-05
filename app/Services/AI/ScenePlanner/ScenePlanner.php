<?php

namespace App\Services\AI\ScenePlanner;

use App\Services\AI\ScenePlanner\Plans\ActionPlan;
use App\Services\AI\ScenePlanner\Plans\CameraEnergyPlan;
use App\Services\AI\ScenePlanner\Plans\CameraMotivationPlan;
use App\Services\AI\ScenePlanner\Plans\CinematicBeatPlan;
use App\Services\AI\ScenePlanner\Plans\CompositionEvolutionPlan;
use App\Services\AI\ScenePlanner\Plans\CompositionPlan;
use App\Services\AI\ScenePlanner\Plans\ContinuityPlan;
use App\Services\AI\ScenePlanner\Plans\CuriosityPlan;
use App\Services\AI\ScenePlanner\Plans\DirectorPlan;
use App\Services\AI\ScenePlanner\Plans\EmotionArcPlan;
use App\Services\AI\ScenePlanner\Plans\EyeGuidancePlan;
use App\Services\AI\ScenePlanner\Plans\PhysicsPlan;
use App\Services\AI\ScenePlanner\Plans\RevealPlan;
use App\Services\AI\ScenePlanner\Plans\RhythmPlan;
use App\Services\AI\ScenePlanner\Plans\SemanticPlan;
use App\Services\AI\ScenePlanner\Plans\VisualContrastPlan;
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
        private readonly ActionPlanner          $actionPlanner,
        private readonly MotionPlanner          $motionPlanner,
        private readonly PhysicsPlanner         $physicsPlanner,
        private readonly DirectorPlanner        $directorPlanner,
        private readonly CompositionPlanner     $compositionPlanner,
        private readonly ContinuityPlanner      $continuityPlanner,
        private readonly CinematicBeatPlanner   $cinematicBeatPlanner,
        private readonly CameraEnergyPlanner    $cameraEnergyPlanner,
        private readonly SecondaryMotionPlanner $secondaryMotionPlanner,
        private readonly RhythmPlanner               $rhythmPlanner,
        private readonly CuriosityPlanner            $curiosityPlanner,
        private readonly RevealPlanner               $revealPlanner,
        private readonly CompositionEvolutionPlanner $compositionEvolutionPlanner,
        private readonly EyeGuidancePlanner          $eyeGuidancePlanner,
        private readonly VisualContrastPlanner       $visualContrastPlanner,
        private readonly EmotionArcPlanner           $emotionArcPlanner,
        private readonly CameraMotivationPlanner     $cameraMotivationPlanner,
        private readonly BeatFusionEngine            $beatFusionEngine,
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

        // CinematicBeat → structured arc with category detection.
        $cinematicBeatData = $this->cinematicBeatPlanner->plan($dsl, $actionResult);
        $cinematicBeat     = CinematicBeatPlan::fromArray($cinematicBeatData);

        // CameraEnergy → velocity contrast tokens per beat.
        $cameraEnergyData = $this->cameraEnergyPlanner->plan(
            $cinematicBeat->beats,
            $cinematicBeat->category,
            $dsl['emo'] ?? 'CRAFT',
        );
        $cameraEnergy = CameraEnergyPlan::fromArray($cameraEnergyData);

        // RhythmPlanner → timing variation pattern; applied to base timeline in-place.
        $rhythmData = $this->rhythmPlanner->plan($cinematicBeat->category, $dsl);
        $rhythm     = RhythmPlan::fromArray($rhythmData);

        // SecondaryMotion injects environment cues per beat before physics overlay.
        $secondaryMotionData = $this->secondaryMotionPlanner->plan($cinematicBeat->category, $dsl);
        $beatMotion          = $secondaryMotionData['beat_motion'] ?? [];

        // CuriosityPlanner → per-beat subject overrides (concealed → partial beats).
        $curiosityData = $this->curiosityPlanner->plan($cinematicBeat->category, $cinematicBeat->beats);
        $curiosity     = CuriosityPlan::fromArray($curiosityData);

        // RevealPlanner → mechanism + camera instruction for the reveal beat.
        $revealData = $this->revealPlanner->plan($cinematicBeat->category, $dsl);
        $reveal     = RevealPlan::fromArray($revealData);

        // Sprint 2 + Sprint 3: visual direction layer.
        $composEvol  = CompositionEvolutionPlan::fromArray(
            $this->compositionEvolutionPlanner->plan($cinematicBeat->category, $cinematicBeat->beats)
        );
        $eyeGuidance = EyeGuidancePlan::fromArray(
            $this->eyeGuidancePlanner->plan($cinematicBeat->category, $cinematicBeat->beats)
        );
        $visualContrast = VisualContrastPlan::fromArray(
            $this->visualContrastPlanner->plan($cinematicBeat->category, $cinematicBeat->beats)
        );
        $emotionArc = EmotionArcPlan::fromArray(
            $this->emotionArcPlanner->plan($cinematicBeat->category, $cinematicBeat->beats)
        );
        $cameraMotivation = CameraMotivationPlan::fromArray(
            $this->cameraMotivationPlanner->plan($cinematicBeat->category, $cinematicBeat->beats)
        );

        // Build beat timeline: rhythm + secondary motion + curiosity layer first,
        // then BeatFusionEngine fuses all remaining Sprint 2 layers into cinematic prose.
        $baseTimeline  = $cameraEnergy->isEmpty() ? $cinematicBeat->toTimeline() : $cameraEnergy->toTimeline();
        $baseTimeline  = $this->applyRhythmTiming($baseTimeline, $rhythm->timingMap());
        $withMotion    = $this->injectSecondaryMotion($baseTimeline, $beatMotion);
        $withCuriosity = $this->injectCuriosityLayer($withMotion, $curiosity->beatStates);
        $withFusion    = $this->beatFusionEngine->fuse(
            $withCuriosity,
            $cinematicBeat->category,
            $reveal,
            $eyeGuidance,
            $visualContrast,
            $composEvol,
            $cameraMotivation,
        );
        $finalTimeline = $this->injectPhysicsIntoBeatTimeline($withFusion, $enrichedTimeline);

        $continuityPlan = $this->continuityPlanner->plan($dsl, $actionResult, $physics, $director);
        $semanticIntent = $this->buildSemanticIntent($dsl, $director);

        return new ScenePlanningResult(
            context:       $context,
            action:        ActionPlan::fromArray($actionResult),
            physics:       PhysicsPlan::fromArray($physics),
            director:      DirectorPlan::fromArray($director),
            composition:   CompositionPlan::fromArray($composition),
            continuity:    ContinuityPlan::fromArray($continuityPlan),
            semantic:      SemanticPlan::fromArray($semanticIntent),
            timeline:      TimelineNode::fromArray($finalTimeline),
            cinematicBeat: $cinematicBeat,
            cameraEnergy:  $cameraEnergy,
            rhythm:               $rhythm,
            curiosity:            $curiosity,
            reveal:               $reveal,
            compositionEvolution: $composEvol,
            eyeGuidance:          $eyeGuidance,
            visualContrast:       $visualContrast,
            emotionArc:           $emotionArc,
            cameraMotivation:     $cameraMotivation,
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
        $enriched['cinematic_beat']  = $result->cinematicBeat->toArray();
        $enriched['camera_energy']   = $result->cameraEnergy->toArray();
        $enriched['rhythm']               = $result->rhythm->toArray();
        $enriched['curiosity']            = $result->curiosity->toArray();
        $enriched['reveal']               = $result->reveal->toArray();
        $enriched['composition_evolution'] = $result->compositionEvolution->toArray();
        $enriched['eye_guidance']          = $result->eyeGuidance->toArray();
        $enriched['visual_contrast']       = $result->visualContrast->toArray();
        $enriched['emotion_arc']           = $result->emotionArc->toArray();
        $enriched['camera_motivation']     = $result->cameraMotivation->toArray();
        $enriched['secondary_motion'] = $this->secondaryMotionPlanner
            ->plan($result->cinematicBeat->category, $dsl);

        return $enriched;
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Injects beat-specific motion cues (from SecondaryMotionPlanner) into
     * each beat's environment field. Physics overlay runs after this, so
     * physics can overwrite only where it has atmosphere/interaction data.
     *
     * @param array $beatTimeline  Timeline segments with 'beat' keys
     * @param array $beatMotion    {beat_name: motion_string} from SecondaryMotionPlanner
     */
    private function injectSecondaryMotion(array $beatTimeline, array $beatMotion): array
    {
        if ($beatMotion === []) {
            return $beatTimeline;
        }

        foreach ($beatTimeline as &$seg) {
            $beatName = $seg['beat'] ?? '';
            if ($beatName !== '' && isset($beatMotion[$beatName])) {
                $seg['environment'] = $beatMotion[$beatName];
            }
        }
        unset($seg);

        return $beatTimeline;
    }

    /**
     * Applies RhythmPlanner timing overrides to an existing beat timeline in-place.
     * Only start/end are updated — camera, subject, velocity_token, beat are preserved.
     *
     * @param array $beatTimeline  Beat segments with 'beat' keys
     * @param array $timingMap     {beat_name: {start, end}} from RhythmPlan::timingMap()
     */
    private function applyRhythmTiming(array $beatTimeline, array $timingMap): array
    {
        if ($timingMap === []) {
            return $beatTimeline;
        }

        foreach ($beatTimeline as &$seg) {
            $beatName = $seg['beat'] ?? '';
            if ($beatName !== '' && isset($timingMap[$beatName])) {
                $seg['start'] = $timingMap[$beatName]['start'];
                $seg['end']   = $timingMap[$beatName]['end'];
            }
        }
        unset($seg);

        return $beatTimeline;
    }

    /**
     * Injects CuriosityPlanner subject overrides into concealed/partial beats.
     * Non-null override REPLACES the subject text to avoid contradicting the
     * "identity withheld" intent with a subject-named CinematicBeatPlanner phrase.
     *
     * @param array $beatTimeline  Beat segments with 'beat' and 'subject' keys
     * @param array $beatStates    {beat_name: {state, subject_override}} from CuriosityPlan
     */
    private function injectCuriosityLayer(array $beatTimeline, array $beatStates): array
    {
        if ($beatStates === []) {
            return $beatTimeline;
        }

        foreach ($beatTimeline as &$seg) {
            $beatName = $seg['beat'] ?? '';
            if ($beatName === '' || !isset($beatStates[$beatName])) {
                continue;
            }
            $override = $beatStates[$beatName]['subject_override'] ?? null;
            if ($override !== null) {
                $seg['subject'] = $override;
            }
        }
        unset($seg);

        return $beatTimeline;
    }

    /**
     * Re-injects physics environment/secondary data (produced by enrichTimeline)
     * into the beat timeline produced by CinematicBeatPlanner.
     *
     * Distribution mirrors enrichTimeline():
     *   Beat 0 (hook)  → environment = atmosphere, secondary = first background
     *   Beat at ~70%   → environment = interaction (physics peak)
     *   Last beat      → secondary = last background (crowd reaction)
     */
    private function injectPhysicsIntoBeatTimeline(array $beatTimeline, array $enrichedTimeline): array
    {
        if ($enrichedTimeline === [] || $beatTimeline === []) {
            return $beatTimeline;
        }

        $firstEnriched = $enrichedTimeline[0] ?? [];
        $lastEnriched  = $enrichedTimeline[count($enrichedTimeline) - 1] ?? [];
        $peakIdx       = (int) round(count($enrichedTimeline) * 0.70);
        $peakEnriched  = $enrichedTimeline[$peakIdx] ?? [];

        $total = count($beatTimeline);

        if (($firstEnriched['environment'] ?? '') !== '') {
            $beatTimeline[0]['environment'] = $firstEnriched['environment'];
        }
        if (($firstEnriched['secondary'] ?? '') !== '') {
            $beatTimeline[0]['secondary'] = $firstEnriched['secondary'];
        }

        $beatPeakIdx = (int) round($total * 0.70);
        if ($beatPeakIdx < $total && ($peakEnriched['environment'] ?? '') !== '') {
            $beatTimeline[$beatPeakIdx]['environment'] = $peakEnriched['environment'];
        }

        if (($lastEnriched['secondary'] ?? '') !== '') {
            $beatTimeline[$total - 1]['secondary'] = $lastEnriched['secondary'];
        }

        return $beatTimeline;
    }

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
