<?php

namespace App\Services\AI\PromptAST\Blocks;

use App\Services\AI\SceneGraph\Nodes\CameraContinuityNode;
use App\Services\AI\SceneGraph\Nodes\ContinuityConstraints;
use App\Services\AI\SceneGraph\Nodes\DynamicStateNode;
use App\Services\AI\SceneGraph\Nodes\EnvironmentNode;
use App\Services\AI\SceneGraph\Nodes\IdentityNode;

/**
 * Cross-shot visual continuity block.
 *
 * Null in PromptAST when shot order == 1 and no prior identity exists.
 * When present, tells the Serializer to emit a CONTINUITY section so the AI
 * model has no reason to invent a new face, jersey, or environment.
 *
 * All sub-nodes are typed — Serializer composes model-specific wording from them.
 */
final class ContinuityBlock
{
    public function __construct(
        /** Identity fields locked from shot 1 — must not change across scene */
        public readonly IdentityNode          $identity,
        /** Dynamic state at the end of the previous shot — null for shot 2 when shot 1 had no state */
        public readonly ?DynamicStateNode     $previousState,
        /** Scene environment locked across shots */
        public readonly EnvironmentNode       $environment,
        /** Camera setup hints for this shot (from previous shot's end) */
        public readonly CameraContinuityNode  $camera,
        /** Which aspects the renderer MUST preserve */
        public readonly ContinuityConstraints $constraints,
    ) {}
}
