<?php

namespace App\Services\AI\AFOS\Planning;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Passes\Config\CameraPassConfig;
use App\Services\AI\AFOS\Types\CameraHeight;
use App\Services\AI\AFOS\Types\CameraMovementType;
use App\Services\AI\AFOS\Types\CameraPhilosophy;
use App\Services\AI\AFOS\Types\DOFLevel;
use App\Services\AI\AFOS\Types\FramingType;

/**
 * SimpleCameraBuilder — Phase A geometric lookup.
 *
 * Derives CameraIR from CompositionIR + DirectorProfile + CinematographyProfile.
 * Reads CompositionIR to inform camera setup — does NOT make compositional decisions.
 *
 * NOT the full CameraPlanner (Phase B geometric derivation).
 * Phase A uses simplified rules without trigonometric derivation.
 * Replace with CameraPlanner in Phase B; interface is identical.
 *
 * Key formulas:
 *   distance = (focalLengthMm / 1000) × subjectRefHeight / (scale × sensorHeightMm / 1000)
 *   Uses 35mm full-frame sensor model: 24mm sensor height, reference subject 2.0m
 */
final class SimpleCameraBuilder
{
    private const SENSOR_HEIGHT_MM   = 24.0;
    private const SUBJECT_REF_HEIGHT = 2.0;

    public function __construct(
        private readonly CameraPassConfig $config = new CameraPassConfig(),
    ) {}

    public function build(
        CompositionIR         $composition,
        DirectorProfile       $director,
        CinematographyProfile $dp,
    ): CameraIR {
        $lens      = $this->selectLens($composition, $dp);
        $dof       = $this->selectDof($composition, $lens);
        $framing   = $this->scaleToFraming($composition->primarySubjectScale);
        $height    = $this->inferHeight($composition->primarySubjectEntity, $director);
        $movement  = $this->selectMovement($director);
        $curve     = $this->buildVelocityCurve($director, $movement);
        $endHeight = $this->inferEndHeight($height, $movement);
        $distance  = $this->estimateDistance($lens, $composition->primarySubjectScale);
        $aperture  = $this->selectAperture($dof);

        $motivation = sprintf(
            'Phase A: philosophy=%s entity=%s scale=%.2f negSpace=%.2f → %s %dmm %s',
            $director->cameraPhilosophy->value,
            $composition->primarySubjectEntity,
            $composition->primarySubjectScale,
            $composition->negativeSpaceAmount,
            $movement->value,
            $lens,
            $dof->value,
        );

        return CameraIR::fromArray([
            'shotId'             => $composition->shotId,
            'focalLengthMm'      => $lens,
            'aperture'           => $aperture,
            'dof'                => $dof->value,
            'startHeight'        => $height->value,
            'endHeight'          => $endHeight->value,
            'movementType'       => $movement->value,
            'velocityCurve'      => $curve,
            'framing'            => $framing->value,
            'movementMotivation' => $motivation,
            'estimatedDistanceM' => $distance,
        ]);
    }

    // ── LENS ─────────────────────────────────────────────────────────────────

    private function selectLens(CompositionIR $composition, CinematographyProfile $dp): int
    {
        // Config override: Experience Engine may lock to a domain-optimal lens.
        if ($this->config->lensMmOverride > 0) {
            return $this->config->lensMmOverride;
        }

        $lenses = $dp->lensVocabularyMm;
        if (empty($lenses)) {
            return $this->defaultLens($composition->primarySubjectScale);
        }

        sort($lenses);

        $idx = match (true) {
            $composition->primarySubjectScale >= 0.50 => count($lenses) - 1,
            $composition->primarySubjectScale >= 0.35 => max(0, count($lenses) - 2),
            $composition->primarySubjectScale >= 0.20 => min(1, count($lenses) - 1),
            default                                    => 0,
        };

        // High negative space → prefer longer lens (telephoto compresses background into negative space)
        if ($composition->negativeSpaceAmount >= 0.40 && $idx < count($lenses) - 1) {
            $idx++;
        }

        return $lenses[$idx];
    }

    private function defaultLens(float $scale): int
    {
        return match (true) {
            $scale >= 0.50 => 135,
            $scale >= 0.35 => 85,
            $scale >= 0.20 => 50,
            default        => 35,
        };
    }

    // ── DOF ──────────────────────────────────────────────────────────────────

    private function selectDof(CompositionIR $composition, int $lens): DOFLevel
    {
        if ($lens >= 85 && $composition->negativeSpaceAmount >= 0.30) {
            return DOFLevel::SHALLOW;
        }
        if ($composition->foregroundEntity && $composition->backgroundEntity && $lens <= 35) {
            return DOFLevel::DEEP;
        }
        return DOFLevel::MEDIUM;
    }

    // ── FRAMING ──────────────────────────────────────────────────────────────

    private function scaleToFraming(float $scale): FramingType
    {
        return match (true) {
            $scale >= 0.55 => FramingType::EXTREME_CLOSE,
            $scale >= 0.40 => FramingType::CLOSE,
            $scale >= 0.25 => FramingType::MEDIUM,
            $scale >= 0.12 => FramingType::WIDE,
            default        => FramingType::EXTREME_WIDE,
        };
    }

    // ── HEIGHT ───────────────────────────────────────────────────────────────

    private function inferHeight(string $entity, DirectorProfile $director): CameraHeight
    {
        $lower = strtolower($entity);

        // Entity-name hints (domain-agnostic substring matching)
        if (str_contains($lower, 'reflection') || str_contains($lower, 'pool') || str_contains($lower, 'water')) {
            return CameraHeight::ANKLE;
        }
        if (str_contains($lower, 'aerial') || str_contains($lower, 'sky') || str_contains($lower, 'view')) {
            return CameraHeight::DRONE_LOW;
        }
        if (str_contains($lower, 'detail') || str_contains($lower, 'texture') || str_contains($lower, 'material')) {
            return CameraHeight::WAIST;
        }
        if (str_contains($lower, 'facade') || str_contains($lower, 'architecture') || str_contains($lower, 'wall')) {
            return CameraHeight::EYE;
        }

        return match ($director->cameraPhilosophy) {
            CameraPhilosophy::ARCHITECTURAL_STATIC => CameraHeight::EYE,
            CameraPhilosophy::MACRO_INTIMACY       => CameraHeight::KNEE,
            CameraPhilosophy::CINEMATIC_ORBIT      => CameraHeight::WAIST,
            CameraPhilosophy::FPV_EXPLORATION      => CameraHeight::WAIST,
            CameraPhilosophy::SLOW_OBSERVATION     => CameraHeight::HIP,
            CameraPhilosophy::DYNAMIC_TRACKING     => CameraHeight::CHEST,
        };
    }

    private function inferEndHeight(CameraHeight $start, CameraMovementType $movement): CameraHeight
    {
        return match ($movement) {
            CameraMovementType::CRANE_UP      => CameraHeight::ABOVE_HEAD,
            CameraMovementType::CRANE_DOWN    => CameraHeight::KNEE,
            CameraMovementType::DRONE_ASCEND  => CameraHeight::DRONE_HIGH,
            CameraMovementType::DRONE_DESCEND => CameraHeight::EYE,
            default                            => $start,
        };
    }

    // ── MOVEMENT ─────────────────────────────────────────────────────────────

    private function selectMovement(DirectorProfile $director): CameraMovementType
    {
        // Config motionBias offsets director.motionWeight — Experience Engine can nudge
        // high-observation shots away from STATIC without modifying the DirectorProfile.
        $effectiveMotion = min(1.0, $director->motionWeight + $this->config->motionBias);

        if ($director->observationWeight >= 0.75 && $effectiveMotion <= 0.30) {
            return CameraMovementType::STATIC;
        }

        return match ($director->cameraPhilosophy) {
            CameraPhilosophy::ARCHITECTURAL_STATIC => CameraMovementType::STATIC,
            CameraPhilosophy::SLOW_OBSERVATION     => $effectiveMotion >= 0.50
                ? CameraMovementType::CRANE_UP
                : CameraMovementType::PUSH_IN,
            CameraPhilosophy::CINEMATIC_ORBIT      => CameraMovementType::ORBIT,
            CameraPhilosophy::FPV_EXPLORATION      => CameraMovementType::FPV,
            CameraPhilosophy::MACRO_INTIMACY       => CameraMovementType::PUSH_IN,
            CameraPhilosophy::DYNAMIC_TRACKING     => CameraMovementType::TRACKING,
        };
    }

    // ── VELOCITY CURVE ───────────────────────────────────────────────────────

    private function buildVelocityCurve(DirectorProfile $director, CameraMovementType $movement): array
    {
        if ($movement === CameraMovementType::STATIC) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        // [t=0, t=⅓, t=⅔, t=1] normalized velocity
        return match (true) {
            $director->motionWeight >= 0.70 => [0.00, 0.25, 0.75, 1.00],  // dynamic: fast rise, sustain
            $director->motionWeight >= 0.40 => [0.00, 0.12, 0.55, 1.00],  // moderate: ease-in, peak
            default                          => [0.00, 0.05, 0.30, 1.00],  // slow: starts near-static
        };
    }

    // ── APERTURE ─────────────────────────────────────────────────────────────

    private function selectAperture(DOFLevel $dof): float
    {
        return match ($dof) {
            DOFLevel::SHALLOW => 1.8,
            DOFLevel::MEDIUM  => 2.8,
            DOFLevel::DEEP    => 5.6,
        };
    }

    // ── DISTANCE ─────────────────────────────────────────────────────────────

    private function estimateDistance(int $lensMs, float $scale): float
    {
        if ($scale <= 0.0) {
            return 10.0;
        }
        // lens formula: d = (f × h_subject) / (scale × sensor_h)
        $d = ($lensMs / 1000.0 * self::SUBJECT_REF_HEIGHT) / ($scale * self::SENSOR_HEIGHT_MM / 1000.0);
        return round(min(50.0, max(0.5, $d)), 1);
    }
}
