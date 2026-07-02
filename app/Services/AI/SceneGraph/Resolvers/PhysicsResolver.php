<?php

namespace App\Services\AI\SceneGraph\Resolvers;

use App\Services\AI\ScenePlanner\Plans\PhysicsPlan;
use App\Services\AI\SceneGraph\Nodes\PhysicsNode;

/**
 * Resolves physics plan into a PhysicsNode.
 *
 * PhysicsPlanner already merges trigger additions, so this is a direct
 * pass-through. The resolver exists so the Builder has a uniform calling
 * convention for all nodes — future Physics Engine (Sprint 7) may add
 * material resolution logic here.
 */
final class PhysicsResolver
{
    public static function resolve(PhysicsPlan $plan): PhysicsNode
    {
        return new PhysicsNode(
            atmosphere:  $plan->atmosphere,
            interaction: $plan->interaction,
            background:  $plan->background,
            microMotion: $plan->microMotion,
            material:    $plan->material,
        );
    }
}
