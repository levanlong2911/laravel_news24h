<?php

namespace App\Services\AI\PromptAST;

use App\Services\AI\PromptAST\Blocks\CameraBlock;
use App\Services\AI\PromptAST\Blocks\CinematicBlock;
use App\Services\AI\PromptAST\Blocks\ContinuityBlock;
use App\Services\AI\PromptAST\Blocks\EnvironmentBlock;
use App\Services\AI\PromptAST\Blocks\SceneBlock;
use App\Services\AI\PromptAST\Blocks\StyleBlock;
use App\Services\AI\PromptAST\Blocks\TimelineBlock;

/**
 * Semantic Intermediate Representation (IR) for a single shot's prompt.
 *
 * Pipeline position:
 *   ShotSceneGraph → PromptBlockAssembler → PromptAST → PromptNormalizer → Serializer → string
 *
 * Design rules:
 *   - Fully semantic: no lookup-table text, no model-specific wording
 *   - Fully typed: no strings where enums exist, no arrays where typed objects exist
 *   - Immutable: all blocks are readonly — PromptNormalizer returns a new PromptAST
 *   - Model-agnostic: KlingSerializer and VeoSerializer both consume this identically
 *
 * continuity is null for the first shot in a scene (no prior identity to lock).
 */
final class PromptAST
{
    public function __construct(
        public readonly SceneBlock       $scene,
        public readonly CameraBlock      $camera,
        public readonly TimelineBlock    $timeline,
        public readonly EnvironmentBlock $environment,
        public readonly StyleBlock       $style,
        public readonly CinematicBlock   $cinematic,
        /** Null for shot 1 — no previous state to anchor */
        public readonly ?ContinuityBlock $continuity,
    ) {}

    /**
     * Return a copy with a normalized environment block.
     * Used by PromptNormalizer without mutating the original.
     */
    public function withEnvironment(EnvironmentBlock $environment): self
    {
        return new self(
            scene:       $this->scene,
            camera:      $this->camera,
            timeline:    $this->timeline,
            environment: $environment,
            style:       $this->style,
            cinematic:   $this->cinematic,
            continuity:  $this->continuity,
        );
    }

    public function withContinuity(?ContinuityBlock $continuity): self
    {
        return new self(
            scene:       $this->scene,
            camera:      $this->camera,
            timeline:    $this->timeline,
            environment: $this->environment,
            style:       $this->style,
            cinematic:   $this->cinematic,
            continuity:  $continuity,
        );
    }
}
