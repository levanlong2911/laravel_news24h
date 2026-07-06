<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Ir\Temporal\CameraKeyframe;
use App\Services\AI\AFOS\Ir\Temporal\CameraTrack;
use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;
use App\Services\AI\AFOS\Types\CameraMovementType;
use App\Services\AI\AFOS\Types\FramingType;
use App\Services\AI\AFOS\Types\GoalType;

/**
 * CameraArcStage — CameraIR + ShotGoalIR → CameraTrack in TemporalGraph.
 *
 * Translates the single-state CameraIR into a time-coded arc of CameraKeyframes,
 * giving the serializer the structured CAMERA section ([0s] wide … [2s] medium …).
 *
 * Each keyframe is a point event: the camera state AT that timestamp. IDs are
 * deterministic (goalType + index) so future dependsOn chains can reference them.
 *
 * $cameraAction replaces the old 'movement' field to better cover the full vocabulary:
 *   'static' | 'push' | 'pull' | 'orbit' | 'track' | 'crane_up' | 'crane_down' |
 *   'hold' | 'pan' | 'tilt' | 'roll'
 */
final class CameraArcStage implements CompilerStage
{
    public function run(PipelineState $state): PipelineState
    {
        $keyframes = $this->planKeyframes($state->camera, $state->shot);
        $track     = new CameraTrack($keyframes);
        $graph     = ($state->graph ?? TemporalGraph::empty($state->shot->durationSec))
            ->withTrack(CameraTrack::ID, $track);

        return $state->withGraph($graph);
    }

    // ── Keyframe planning ─────────────────────────────────────────────────────

    private function planKeyframes(CameraIR $camera, ShotGoalIR $shot): array
    {
        $dur      = $shot->durationSec;
        $lens     = $camera->focalLengthMm;
        $framing  = $camera->framing;
        $movement = $camera->movementType;
        $energy   = $shot->energy;

        $startFrame = $this->framingToSize($framing);
        $target     = $this->primaryFocusTarget($shot);

        return match ($shot->goalType) {
            GoalType::REVEAL     => $this->revealArc($dur, $lens, $startFrame, $target, $movement),
            GoalType::ESTABLISH  => $this->establishArc($dur, $lens, $startFrame, $target, $movement),
            GoalType::FOLLOW     => $this->followArc($dur, $lens, $energy, $target, $movement),
            GoalType::DISCOVER   => $this->discoverArc($dur, $lens, $startFrame, $target, $movement),
            GoalType::TRANSITION => $this->transitionArc($dur, $lens, $startFrame, $target, $movement),
            GoalType::RESOLVE    => $this->resolveArc($dur, $lens, $startFrame, $target),
        };
    }

    private function revealArc(float $dur, int $lens, string $startFrame, ?string $target, CameraMovementType $mov): array
    {
        return [
            new CameraKeyframe('reveal_0', 0.0,         'wide',   'static',              0.0, $lens, 'environment'),
            new CameraKeyframe('reveal_1', $dur * 0.45, 'medium', $this->movName($mov),  0.3, $lens, $target),
            new CameraKeyframe('reveal_2', $dur * 0.85, 'close',  'push',                0.2, null,  $target),
        ];
    }

    private function establishArc(float $dur, int $lens, string $startFrame, ?string $target, CameraMovementType $mov): array
    {
        return [
            new CameraKeyframe('establish_0', 0.0,         'wide',       'static',              0.0,  $lens, 'environment'),
            new CameraKeyframe('establish_1', $dur * 0.5,  $startFrame,  $this->movName($mov),  0.25, $lens, $target),
            new CameraKeyframe('establish_2', $dur * 0.85, $startFrame,  'hold',                0.0,  null,  $target),
        ];
    }

    private function followArc(float $dur, int $lens, float $energy, ?string $target, CameraMovementType $mov): array
    {
        $speed = min(1.0, $energy);
        return [
            new CameraKeyframe('follow_0', 0.0,         'wide',   'track',              $speed * 0.6, $lens, $target),
            new CameraKeyframe('follow_1', $dur * 0.4,  'medium', $this->movName($mov), $speed,       null,  $target),
            new CameraKeyframe('follow_2', $dur * 0.75, 'close',  'track',              $speed * 0.8, null,  $target),
            new CameraKeyframe('follow_3', $dur,        'close',  'hold',               0.0,          null,  $target),
        ];
    }

    private function discoverArc(float $dur, int $lens, string $startFrame, ?string $target, CameraMovementType $mov): array
    {
        return [
            new CameraKeyframe('discover_0', 0.0,         $startFrame,     'static',              0.0, $lens, 'environment'),
            new CameraKeyframe('discover_1', $dur * 0.5,  'medium',        $this->movName($mov),  0.3, null,  $target),
            new CameraKeyframe('discover_2', $dur * 0.8,  'extreme_close', 'push',                0.2, null,  $target),
        ];
    }

    private function transitionArc(float $dur, int $lens, string $startFrame, ?string $target, CameraMovementType $mov): array
    {
        return [
            new CameraKeyframe('transition_0', 0.0,         $startFrame, 'static',              0.0,  $lens, $target),
            new CameraKeyframe('transition_1', $dur * 0.35, $startFrame, $this->movName($mov),  0.5,  null,  $target),
            new CameraKeyframe('transition_2', $dur * 0.75, 'wide',      'pull',                0.4,  null,  'environment'),
            new CameraKeyframe('transition_3', $dur,        'wide',      'hold',                0.0,  null,  'environment'),
        ];
    }

    private function resolveArc(float $dur, int $lens, string $startFrame, ?string $target): array
    {
        return [
            new CameraKeyframe('resolve_0', 0.0,        $startFrame, 'push', 0.2, $lens, $target),
            new CameraKeyframe('resolve_1', $dur * 0.6, 'close',     'push', 0.1, null,  $target),
            new CameraKeyframe('resolve_2', $dur,       'close',     'hold', 0.0, null,  $target),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function framingToSize(FramingType $framing): string
    {
        return match ($framing) {
            FramingType::EXTREME_WIDE  => 'extreme_wide',
            FramingType::WIDE          => 'wide',
            FramingType::MEDIUM        => 'medium',
            FramingType::CLOSE         => 'close',
            FramingType::EXTREME_CLOSE => 'extreme_close',
        };
    }

    private function movName(CameraMovementType $type): string
    {
        return match ($type) {
            CameraMovementType::STATIC        => 'static',
            CameraMovementType::PUSH_IN       => 'push',
            CameraMovementType::PULL_OUT      => 'pull',
            CameraMovementType::CRANE_UP      => 'crane_up',
            CameraMovementType::CRANE_DOWN    => 'crane_down',
            CameraMovementType::ORBIT         => 'orbit',
            CameraMovementType::TRACKING      => 'track',
            CameraMovementType::DRONE_ASCEND  => 'crane_up',
            CameraMovementType::DRONE_DESCEND => 'crane_down',
            CameraMovementType::FPV           => 'track',
            CameraMovementType::HANDHELD      => 'push',
        };
    }

    private function primaryFocusTarget(ShotGoalIR $shot): ?string
    {
        if ($shot->viewerShouldNotice !== []) {
            $target = $shot->viewerShouldNotice[0];
            return is_string($target) ? $target : null;
        }
        return $shot->goalTarget !== '' ? $shot->goalTarget : null;
    }

    // ── Stage contract ────────────────────────────────────────────────────────

    public function name(): string { return 'CameraArcStage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'CameraArcStage',
            reads:          [CameraIR::class, ShotGoalIR::class],
            writes:         [TemporalGraph::class],
            cost:           StageCost::cpu(1.0),
            description:    'CameraIR + ShotGoalIR → CameraTrack: generates time-coded camera arc keyframes from movement type and goal.',
            deterministic:  true,
            cacheable:      true,
            parallelizable: true,
            category:       'transform',
            capabilities:   [StageCapability::PURE, StageCapability::CACHEABLE, StageCapability::DETERMINISTIC, StageCapability::WRITE_IR],
            phase:          CompilerPhase::BUILD,
        );
    }
}
