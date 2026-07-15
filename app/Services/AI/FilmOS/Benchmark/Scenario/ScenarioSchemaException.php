<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario;

/**
 * Thrown by ScenarioLoader when a scenario file violates the schema contract
 * (SCHEMA.md). Message always names the scenario and the exact rule broken,
 * so a failing catalog file is diagnosable at a glance.
 */
final class ScenarioSchemaException extends \RuntimeException
{
    public static function for(string $scenario, string $problem): self
    {
        return new self("Scenario '{$scenario}': {$problem}");
    }
}
