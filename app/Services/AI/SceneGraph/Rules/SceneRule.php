<?php

namespace App\Services\AI\SceneGraph\Rules;

use App\Services\AI\SceneGraph\ShotSceneGraph;

/**
 * A single validation rule applied to a ShotSceneGraph.
 *
 * Returns an array of error records. Empty array = rule passed.
 * Each error: {field: string, expected: string, actual: mixed}
 *
 * Open/Closed: add new rules by implementing this interface — SceneGraphValidator
 * iterates over injected rules and never needs to change.
 */
interface SceneRule
{
    /**
     * @return array<int, array{field: string, expected: string, actual: mixed}>
     */
    public function validate(ShotSceneGraph $graph): array;

    public function name(): string;
}
