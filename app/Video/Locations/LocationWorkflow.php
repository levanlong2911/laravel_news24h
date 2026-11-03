<?php

declare(strict_types=1);

namespace App\Video\Locations;

use App\Models\VideoProject;
use App\Video\Script\CheckedWorkflow;

interface LocationWorkflow extends CheckedWorkflow
{
    public function format(): string;

    public function checkVersion(): string;

    public function plannerVersion(): string;

    public function inputsVersion(): string;

    /** @return array{execution: string, model: string, effort: string, efforts: list<string>, max_tokens: int, timeout_seconds: int, schema_heading: string, semantic_review: string, reference: array<string, mixed>} */
    public function settings(): array;

    /**
     * @param  string  $prompt  the phase prompt the run is sent with
     * @return array{payload: array<string, mixed>, source: array<string, mixed>, context: array<string, mixed>}
     *
     * @throws \RuntimeException when no approved script or its source is broken; no model is called
     */
    public function inputs(VideoProject $project, string $prompt): array;

    /**
     * @param  list<string>  $parts  the phase prompt parts of the bundle
     * @param  list<array<string, mixed>>  $schemas  the output schemas of the bundle
     * @return string the system text of a run: the prompt parts, then each output schema once
     */
    public function systemText(array $parts, array $schemas): string;

    /**
     * @param  array<string, mixed>  $context  the check context pinned with a run
     * @param  array<string, mixed>  $source  the sources pinned with that run
     * @return array<string, mixed> the context with every design text the current check and prompts read; a run pinned before a text was kept gets it from the same pinned design, never from a newer one
     *
     * @throws \RuntimeException when the pinned script or design is gone or no longer matches its hash
     */
    public function completeContext(string $projectId, array $context, array $source): array;

    /**
     * @param  array<string, mixed>  $source
     * @return ?string why the pinned script or design no longer serves, null while it still does
     */
    public function sourceProblem(string $projectId, array $source): ?string;
}
