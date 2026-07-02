<?php

namespace App\Services\AI\Pipeline;

use App\Services\AI\Validators\TransformationValidator;
use App\Services\AI\Validators\StoryValidator;
use App\Services\AI\Validators\SceneValidator;
use App\Services\AI\Validators\SceneGraphValidator;

/**
 * Lightweight registry mapping stage names to their planner + validator + schema.
 * Swap a planner class here (e.g. Claude → Gemini) without touching the orchestrator.
 */
final class PipelineRegistry
{
    /** @var PipelineStageDefinition[] */
    private static array $definitions = [];

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        // Stage order matters — orchestrator runs them in registration order
        self::define('transformation',
            plannerClass:   \App\Services\AI\TransformationPlanner\Planner::class,
            validatorClass: TransformationValidator::class,
            schemaFile:     'Transformation.schema.json',
        );

        self::define('story',
            plannerClass:   \App\Services\AI\StoryPlanner\Planner::class,
            validatorClass: StoryValidator::class,
            schemaFile:     'Story.schema.json',
        );

        self::define('scene_shot',
            plannerClass:   \App\Services\AI\SceneShotPlanner\Planner::class,
            validatorClass: SceneValidator::class,
            schemaFile:     'SceneShot.schema.json',
        );

        self::define('scene_graph',
            plannerClass:   \App\Services\AI\SceneGraph\Builder::class,
            validatorClass: SceneGraphValidator::class,
            schemaFile:     'SceneGraph.schema.json',
        );

        self::$booted = true;
    }

    public static function define(
        string $stage,
        string $plannerClass,
        string $validatorClass,
        string $schemaFile,
    ): void {
        self::$definitions[$stage] = new PipelineStageDefinition($stage, $plannerClass, $validatorClass, $schemaFile);
    }

    public static function get(string $stage): PipelineStageDefinition
    {
        if (!isset(self::$definitions[$stage])) {
            throw new \RuntimeException("Pipeline stage '{$stage}' not registered. Call PipelineRegistry::boot() first.");
        }

        return self::$definitions[$stage];
    }

    /** @return string[] */
    public static function stages(): array
    {
        return array_keys(self::$definitions);
    }

    public static function schemaPath(string $stage): string
    {
        $file = self::get($stage)->schemaFile;
        return base_path("contracts/v1/{$file}");
    }
}
