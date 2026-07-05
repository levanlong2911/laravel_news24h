<?php

namespace App\Services\AI\AFOS\Planning;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Ontology\EntityExtractor;
use App\Services\AI\AFOS\Types\CameraPhilosophy;
use App\Services\AI\AFOS\Types\ColorPhilosophy;
use App\Services\AI\AFOS\Types\CutFrequency;
use App\Services\AI\AFOS\Types\Emotion;
use App\Services\AI\AFOS\Types\Experience;
use App\Services\AI\AFOS\Types\GoalType;
use App\Services\AI\AFOS\Types\Narrative;
use App\Services\AI\AFOS\Types\NarrativeFunction;
use App\Services\AI\AFOS\Types\Tempo;
use App\Services\AI\SceneGraph\Enums\Emotion as LegacyEmotion;
use App\Services\AI\SceneGraph\Enums\Pacing;
use App\Services\AI\SceneGraph\Enums\StoryPhase;
use App\Services\AI\ScenePlanner\ScenePlanningResult;

/**
 * ShotGoalIRAdapter — Phase A integration bridge.
 *
 * Converts the existing ScenePlanningResult (legacy pipeline) into
 * the typed AFOS IR objects (ShotGoalIR, DirectorProfile, CinematographyProfile, Intent).
 *
 * This adapter is a one-way translation layer. In Phase B it is REPLACED
 * by the LLM-based IntentExtractor + ShotGoalPlanner pipeline. The adapter
 * allows Phase A to reuse the existing ScenePlanner without rewriting it.
 *
 * All conversions are deterministic — zero LLM cost.
 */
final class ShotGoalIRAdapter
{
    public static function toShotGoalIR(ScenePlanningResult $result): ShotGoalIR
    {
        $ctx     = $result->context;
        $sem     = $result->semantic;
        $comp    = $result->composition;
        $energy  = $result->cameraEnergy;

        $emotion     = self::mapEmotion($sem->emotion);
        $goalType    = self::mapGoalType($sem->storyPhase, $sem->goal);
        $narrative   = self::mapNarrativeFunction($sem->storyPhase);
        $energyScore = self::extractEnergy($energy->velocityCurve, $ctx->motionLevel->value);

        // Ontology: resolve a typed EntityRef from scene_title + primarySubject.
        // This eliminates the "subject" placeholder — goalTarget now carries a
        // meaningful entity ID (e.g. "pool_reflection", "terrace", "villa_facade")
        // that KlingPromptPlanningPass can look up in its vocabulary table.
        $entity = EntityExtractor::fromDsl($ctx->sceneTitle, $sem->primarySubject);

        $notice = array_filter([
            $entity->entityId,
            $sem->secondarySubject ? self::toEntityRef($sem->secondarySubject) : null,
        ]);

        // viewerShouldIgnore: empty for Phase A — depth-layer entities added in Phase B DomainBible
        $ignore = [];

        return ShotGoalIR::fromArray([
            'shotId'              => $ctx->shotId,
            'durationSec'         => $ctx->dur,
            'goalType'            => $goalType->value,
            'goalTarget'          => $entity->entityId,
            'viewerShouldNotice'  => array_values($notice),
            'viewerShouldIgnore'  => array_values($ignore),
            'emotion'             => $emotion->value,
            'energy'              => $energyScore,
            'narrativeFunction'   => $narrative->value,
        ]);
    }

    public static function toDirectorProfile(ScenePlanningResult $result): DirectorProfile
    {
        $ctx = $result->context;
        $dir = $result->director;
        $sem = $result->semantic;

        $pacing       = $sem->pace;
        $cameraMove   = $ctx->cameraMove->value;
        $motionLevel  = $ctx->motionLevel->value;

        return DirectorProfile::fromArray([
            'name'               => 'phase_a_derived',
            'observationWeight'  => self::observationWeight($pacing),
            'motionWeight'       => self::motionWeightFromMove($cameraMove, self::motionWeight($motionLevel)),
            'revealWeight'       => $sem->storyPhase === StoryPhase::CLIMAX ? 0.80 : 0.40,
            'negativeSpaceWeight' => self::negativeSpaceWeight($dir->composition),
            'symmetryWeight'     => str_contains(strtolower($dir->composition), 'symmet') ? 0.85 : 0.30,
            'cutFrequency'       => self::mapCutFrequency($pacing)->value,
            'cameraPhilosophy'   => self::mapCameraPhilosophy($cameraMove, $motionLevel)->value,
            'colorPhilosophy'    => self::mapColorPhilosophy($ctx->lightCode)->value,
        ]);
    }

    public static function toCinematographyProfile(ScenePlanningResult $result): CinematographyProfile
    {
        $ctx = $result->context;
        $dir = $result->director;

        $lensCode = (int) preg_replace('/[^0-9]/', '', $ctx->lensCode ?: '85');
        if ($lensCode < 16) {
            $lensCode = 85;
        }

        // Build a small vocabulary: the selected lens + one step up/down
        $lenses = [$lensCode];
        if ($lensCode <= 35)  { $lenses[] = 85;  }
        if ($lensCode >= 85)  { array_unshift($lenses, 35); }
        if ($lensCode >= 135) { $lenses[] = 200; }
        sort($lenses);

        return CinematographyProfile::fromArray([
            'name'                => 'phase_a_derived',
            'lensVocabularyMm'    => array_values(array_unique($lenses)),
            'lightingStyle'       => self::lightingStyle($ctx->lightCode),
            'motionStyle'         => $dir->acceleration === 'fast' ? 'ENERGETIC' : 'SLOW_PUSH',
            'depthLayersPreferred' => 3,
        ]);
    }

    public static function toIntent(ScenePlanningResult $result): Intent
    {
        $sem = $result->semantic;
        $ctx = $result->context;

        return Intent::fromArray([
            'primaryEmotion'   => self::mapEmotion($sem->emotion)->value,
            'secondaryEmotion' => null,
            'narrative'        => self::mapNarrative($sem->goal)->value,
            'tempo'            => self::mapTempo($sem->pace)->value,
            'viewerExperience' => Experience::ASPIRATION->value,  // Phase A: single domain
            'desiredTakeaway'  => $sem->viewerTakeaway ?: $sem->viewerAttention,
        ]);
    }

    // ── EMOTION MAPPING ───────────────────────────────────────────────────────

    private static function mapEmotion(LegacyEmotion $legacy): Emotion
    {
        return match ($legacy) {
            LegacyEmotion::CALM              => Emotion::SERENITY,
            LegacyEmotion::CRAFT             => Emotion::LUXURY,
            LegacyEmotion::POWER             => Emotion::POWER,
            LegacyEmotion::EPIC              => Emotion::POWER,
            LegacyEmotion::JOY               => Emotion::TRIUMPH,
            LegacyEmotion::AWE               => Emotion::WONDER,
            LegacyEmotion::HOOK              => Emotion::CURIOSITY,
            LegacyEmotion::REVEAL            => Emotion::WONDER,
            LegacyEmotion::DRAMA             => Emotion::CURIOSITY,
            LegacyEmotion::TENSE, LegacyEmotion::FEAR => Emotion::TENSION,
        };
    }

    // ── GOAL TYPE ─────────────────────────────────────────────────────────────

    private static function mapGoalType(StoryPhase $phase, string $goal): GoalType
    {
        $lower = strtolower($goal);

        if (str_contains($lower, 'reveal') || $phase === StoryPhase::CLIMAX) {
            return GoalType::REVEAL;
        }
        if (str_contains($lower, 'establish') || $phase === StoryPhase::SETUP) {
            return GoalType::ESTABLISH;
        }
        if (str_contains($lower, 'follow') || str_contains($lower, 'track')) {
            return GoalType::FOLLOW;
        }
        if (str_contains($lower, 'discover') || str_contains($lower, 'explore')) {
            return GoalType::DISCOVER;
        }
        if ($phase === StoryPhase::RESOLVE) {
            return GoalType::RESOLVE;
        }

        return GoalType::ESTABLISH;
    }

    private static function mapNarrativeFunction(StoryPhase $phase): NarrativeFunction
    {
        return match ($phase) {
            StoryPhase::SETUP   => NarrativeFunction::ESTABLISH,
            StoryPhase::BUILD   => NarrativeFunction::BUILD,
            StoryPhase::CLIMAX  => NarrativeFunction::REVEAL,
            StoryPhase::RESOLVE => NarrativeFunction::RESOLVE,
        };
    }

    // ── ENERGY ───────────────────────────────────────────────────────────────

    private static function extractEnergy(array $velocityCurve, string $motionLevel): float
    {
        if (!empty($velocityCurve)) {
            $avg = array_sum($velocityCurve) / count($velocityCurve);
            return round(min(1.0, $avg / 100.0), 3);  // velocity_pct 0–100 → 0–1
        }

        return match ($motionLevel) {
            'high'   => 0.80,
            'medium' => 0.50,
            'low'    => 0.25,
            default  => 0.40,
        };
    }

    // ── DIRECTOR WEIGHTS ──────────────────────────────────────────────────────

    private static function observationWeight(Pacing $pacing): float
    {
        return match ($pacing) {
            Pacing::SLOW    => 0.85,
            Pacing::MEDIUM  => 0.60,
            Pacing::UPBEAT  => 0.45,
            Pacing::DYNAMIC => 0.35,
            Pacing::FAST, Pacing::MONTAGE => 0.25,
        };
    }

    private static function motionWeight(string $motionLevel): float
    {
        return match ($motionLevel) {
            'high'   => 0.75,
            'medium' => 0.50,
            'low'    => 0.25,
            default  => 0.45,
        };
    }

    private static function negativeSpaceWeight(string $composition): float
    {
        $lower = strtolower($composition);
        if (str_contains($lower, 'negative space') || str_contains($lower, 'minimal')) {
            return 0.75;
        }
        if (str_contains($lower, 'balanced') || str_contains($lower, 'thirds')) {
            return 0.55;
        }
        return 0.45;
    }

    private static function mapCutFrequency(Pacing $pacing): CutFrequency
    {
        return match ($pacing) {
            Pacing::SLOW    => CutFrequency::MINIMAL,
            Pacing::MEDIUM  => CutFrequency::SLOW,
            Pacing::UPBEAT  => CutFrequency::MEDIUM,
            Pacing::DYNAMIC => CutFrequency::FAST,
            Pacing::FAST, Pacing::MONTAGE => CutFrequency::AGGRESSIVE,
        };
    }

    private static function mapCameraPhilosophy(string $cameraMove, string $motionLevel): CameraPhilosophy
    {
        $lower = strtolower($cameraMove);

        if ($lower === 'static' || str_contains($lower, 'lock')) {
            return CameraPhilosophy::ARCHITECTURAL_STATIC;
        }
        // Legacy short codes: O1/O2 = orbital, D1/D2 = dolly
        if (in_array($lower, ['o1', 'o2'], true) || str_contains($lower, 'orbit') || str_contains($lower, 'arc')) {
            return CameraPhilosophy::CINEMATIC_ORBIT;
        }
        // D1/D2 = dolly (tracking-style movement)
        if (in_array($lower, ['d1', 'd2'], true) || str_contains($lower, 'track') || str_contains($lower, 'follow')) {
            return CameraPhilosophy::DYNAMIC_TRACKING;
        }
        if (str_contains($lower, 'macro') || str_contains($lower, 'close')) {
            return CameraPhilosophy::MACRO_INTIMACY;
        }
        if (str_contains($lower, 'fpv') || str_contains($lower, 'drone')) {
            return CameraPhilosophy::FPV_EXPLORATION;
        }
        // P1 = push in, P2 = pull out, T1 = tilt up, T2 = tilt down, H1 = handheld
        // Crane/push/pull/tilt → SLOW_OBSERVATION so SimpleCameraBuilder derives
        // CRANE_UP or PUSH_IN from motionWeight.
        if (in_array($lower, ['p1', 'p2', 't1', 't2', 'h1'], true)
            || str_contains($lower, 'crane') || str_contains($lower, 'push') || str_contains($lower, 'pull')
            || str_contains($lower, 'tilt') || str_contains($lower, 'handheld')) {
            return CameraPhilosophy::SLOW_OBSERVATION;
        }

        return $motionLevel === 'low'
            ? CameraPhilosophy::SLOW_OBSERVATION
            : CameraPhilosophy::DYNAMIC_TRACKING;
    }

    /**
     * When the DSL camera move is explicitly dynamic (push, pull, tilt, orbit, dolly, drone),
     * boost motionWeight so SimpleCameraBuilder's high-observation guard does not force STATIC.
     * SimpleCameraBuilder::selectMovement: SLOW_OBSERVATION + motionWeight ≥ 0.50 → CRANE_UP.
     */
    private static function motionWeightFromMove(string $cameraMove, float $baseWeight): float
    {
        static $dynamicCodes = ['p1', 'p2', 't1', 't2', 'o1', 'o2', 'd1', 'd2'];
        $lower = strtolower($cameraMove);
        if (in_array($lower, $dynamicCodes, true)
            || str_contains($lower, 'crane') || str_contains($lower, 'orbit')
            || str_contains($lower, 'push') || str_contains($lower, 'pull')
            || str_contains($lower, 'track') || str_contains($lower, 'drone')
            || str_contains($lower, 'tilt')) {
            return max($baseWeight, 0.55);
        }
        return $baseWeight;
    }

    private static function mapColorPhilosophy(string $lightCode): ColorPhilosophy
    {
        $upper = strtoupper($lightCode);
        if (str_starts_with($upper, 'G')) {
            return ColorPhilosophy::WARM_GOLDEN;
        }
        if (str_starts_with($upper, 'N')) {
            return ColorPhilosophy::HIGH_CONTRAST;
        }
        if (str_starts_with($upper, 'W')) {
            return ColorPhilosophy::WARM_GOLDEN;
        }
        if (str_starts_with($upper, 'D')) {
            return ColorPhilosophy::COOL_BLUE;
        }
        return ColorPhilosophy::WARM_GOLDEN;
    }

    // ── NARRATIVE / TEMPO / LIGHTING ─────────────────────────────────────────

    private static function mapNarrative(string $goal): Narrative
    {
        $lower = strtolower($goal);
        if (str_contains($lower, 'beauty') || str_contains($lower, 'reveal')) {
            return Narrative::REVEAL_BEAUTY;
        }
        if (str_contains($lower, 'power') || str_contains($lower, 'strength')) {
            return Narrative::DEMONSTRATE_POWER;
        }
        if (str_contains($lower, 'craft') || str_contains($lower, 'detail')) {
            return Narrative::DOCUMENT_CRAFT;
        }
        if (str_contains($lower, 'aspir') || str_contains($lower, 'luxury')) {
            return Narrative::EVOKE_ASPIRATION;
        }
        if (str_contains($lower, 'scale') || str_contains($lower, 'grand')) {
            return Narrative::SHOW_SCALE;
        }
        return Narrative::CAPTURE_MOMENT;
    }

    private static function mapTempo(Pacing $pacing): Tempo
    {
        return match ($pacing) {
            Pacing::SLOW    => Tempo::MEDITATIVE,
            Pacing::MEDIUM  => Tempo::MEASURED,
            Pacing::UPBEAT  => Tempo::BUILDING,
            Pacing::DYNAMIC => Tempo::URGENT,
            Pacing::FAST, Pacing::MONTAGE => Tempo::EXPLOSIVE,
        };
    }

    private static function lightingStyle(string $lightCode): string
    {
        $upper = strtoupper($lightCode);
        return match (true) {
            str_starts_with($upper, 'G') => 'GOLDEN_HOUR_SOFT',
            str_starts_with($upper, 'W') => 'WARM_AMBIENT',
            str_starts_with($upper, 'N') => 'HDR_DRAMATIC',
            str_starts_with($upper, 'D') => 'DAYLIGHT_NEUTRAL',
            str_starts_with($upper, 'S') => 'STUDIO_CONTROLLED',
            str_starts_with($upper, 'C') => 'CINEMATIC_MOTIVATED',
            default                       => 'GOLDEN_HOUR_SOFT',
        };
    }

    // ── UTILITY ───────────────────────────────────────────────────────────────

    private static function toEntityRef(string $subject): string
    {
        // Normalize free-form subject string → snake_case EntityRef
        return strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($subject)));
    }
}
