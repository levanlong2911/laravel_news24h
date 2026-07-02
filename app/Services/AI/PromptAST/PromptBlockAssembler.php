<?php

namespace App\Services\AI\PromptAST;

use App\Services\AI\PromptAST\Blocks\CameraBlock;
use App\Services\AI\PromptAST\Blocks\CinematicBlock;
use App\Services\AI\PromptAST\Blocks\ContinuityBlock;
use App\Services\AI\PromptAST\Blocks\EnvironmentBlock;
use App\Services\AI\PromptAST\Blocks\SceneBlock;
use App\Services\AI\PromptAST\Blocks\StyleBlock;
use App\Services\AI\PromptAST\Blocks\TimelineBlock;
use App\Services\AI\SceneGraph\ShotSceneGraph;

/**
 * Assembles PromptAST from ShotSceneGraph.
 *
 * Responsibilities:
 *   - Copy typed values from ShotSceneGraph nodes into typed Block objects
 *   - NO lookup tables, NO model-specific wording, NO language translation
 *   - Cross-node merging only (e.g., physics + environment → EnvironmentBlock)
 *
 * Lookup tables belong exclusively in Serializers:
 *   CameraMove::P2 × MotionLevel::HIGH stays as-is here.
 *   KlingSerializer translates it to "aggressively pushing in...".
 *   VeoSerializer translates it to its own phrasing.
 *
 * qualityTier defaults to 'photoreal' — Sprint 7 will derive from VideoProject.quality.
 */
final class PromptBlockAssembler
{
    public static function assemble(ShotSceneGraph $graph): PromptAST
    {
        return new PromptAST(
            scene:       self::buildScene($graph),
            camera:      self::buildCamera($graph),
            timeline:    self::buildTimeline($graph),
            environment: self::buildEnvironment($graph),
            style:       self::buildStyle($graph),
            cinematic:   self::buildCinematic($graph),
            continuity:  self::buildContinuity($graph),
        );
    }

    // ── Private builders — each reads from one or two nodes only ────────────

    private static function buildScene(ShotSceneGraph $g): SceneBlock
    {
        return new SceneBlock(
            sceneTitle: $g->sceneTitle,
            lightCode:  $g->lightCode,
            emotion:    $g->semantic->emotion,
            storyPhase: $g->semantic->storyPhase,
        );
    }

    private static function buildCamera(ShotSceneGraph $g): CameraBlock
    {
        return new CameraBlock(
            move:            $g->camera->move,
            camType:         $g->camera->camType,
            lensCode:        $g->camera->lensCode,
            motionLevel:     $g->camera->motionLevel,
            height:          $g->camera->height,
            stabilization:   $g->camera->stabilization,
            subjectPosition: $g->composition->subjectPosition,
            leadingLines:    $g->composition->leadingLines,
            ruleOfThirds:    $g->composition->ruleOfThirds,
            eyeAnchor:       $g->composition->eyeAnchor,
        );
    }

    private static function buildTimeline(ShotSceneGraph $g): TimelineBlock
    {
        return new TimelineBlock(
            phases:       $g->timeline->phases,
            emotionCurve: $g->timeline->emotionCurve,
            shotDuration: $g->dur,
        );
    }

    /**
     * Merge EnvironmentNode (scene conditions) + PhysicsNode (motion layers)
     * into one block. PromptNormalizer will dedupe physics phrases next.
     */
    private static function buildEnvironment(ShotSceneGraph $g): EnvironmentBlock
    {
        return new EnvironmentBlock(
            weather:        $g->environment->weather,
            weatherDesc:    $g->environment->weatherDesc,
            time:           $g->environment->time,
            palette:        $g->environment->palette,
            fieldCondition: $g->environment->fieldCondition,
            crowdDensity:   $g->environment->crowdDensity,
            atmosphere:     $g->physics->atmosphere,
            interaction:    $g->physics->interaction,
            background:     $g->physics->background,
            microMotion:    $g->physics->microMotion,
            material:       $g->physics->material,
        );
    }

    private static function buildStyle(ShotSceneGraph $g): StyleBlock
    {
        return new StyleBlock(
            qualityTier:  'photoreal',
            motionBlur:   $g->director->motionBlur,
            rackFocus:    $g->director->rackFocus,
            acceleration: $g->director->acceleration,
        );
    }

    private static function buildCinematic(ShotSceneGraph $g): CinematicBlock
    {
        return new CinematicBlock(
            emotion:         $g->semantic->emotion,
            pace:            $g->semantic->pace,
            goal:            $g->semantic->goal,
            viewerAttention: $g->semantic->viewerAttention,
            viewerTakeaway:  $g->semantic->viewerTakeaway,
        );
    }

    /**
     * Return null for first shot without prior identity — no CONTINUITY section needed.
     * Return ContinuityBlock for shots 2+ (previousState set) or whenever
     * identity is locked (role is non-empty from shot 1).
     */
    private static function buildContinuity(ShotSceneGraph $g): ?ContinuityBlock
    {
        if ($g->continuity->isFirstShot() && $g->continuity->identity->isEmpty()) {
            return null;
        }

        return new ContinuityBlock(
            identity:      $g->continuity->identity,
            previousState: $g->continuity->previousState,
            environment:   $g->continuity->environment,
            camera:        $g->continuity->camera,
            constraints:   $g->continuity->constraints,
        );
    }
}
