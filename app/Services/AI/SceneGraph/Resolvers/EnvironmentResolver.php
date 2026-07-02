<?php

namespace App\Services\AI\SceneGraph\Resolvers;

use App\Services\AI\ScenePlanner\Plans\ContinuityPlan;
use App\Services\AI\SceneGraph\Nodes\EnvironmentNode;

/**
 * Resolves environment data from ContinuityPlan into a typed EnvironmentNode.
 *
 * Environment lives in ContinuityPlan because ContinuityPlanner owns
 * it — it's the entity responsible for locking scene conditions across shots.
 */
final class EnvironmentResolver
{
    public static function resolve(ContinuityPlan $continuity): EnvironmentNode
    {
        $env = $continuity->environment;

        return new EnvironmentNode(
            weather:        $env['weather']         ?? 'clear',
            weatherDesc:    $env['weather_desc']    ?? '',
            time:           $env['time']            ?? '',
            palette:        $env['palette']         ?? '',
            fieldCondition: $env['field_condition'] ?? 'normal',
            crowdDensity:   $env['crowd_density']   ?? 'seated',
        );
    }
}
