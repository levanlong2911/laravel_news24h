<?php

declare(strict_types=1);

namespace App\Video\Scenes;

use App\Models\VideoProject;
use App\Video\Script\CheckedWorkflow;

interface SceneWorkflow extends CheckedWorkflow
{
    public function format(): string;

    public function checkVersion(): string;

    public function plannerVersion(): string;

    public function inputsVersion(): string;

    /** @return array{execution: string, model: string, effort: string, max_tokens: int, timeout_seconds: int, schema_heading: string} */
    public function settings(): array;

    /**
     * @param  string  $prompt  the phase prompt the run is sent with
     * @return array{payload: array<string, mixed>, source: array<string, mixed>, context: array<string, mixed>}
     *
     * @throws \RuntimeException when the approved script, the approved background text or the pinned design is missing or broken; no model is called
     */
    public function inputs(VideoProject $project, string $prompt): array;

    /**
     * @param  list<string>  $parts  the phase prompt parts of the bundle
     * @param  list<array<string, mixed>>  $schemas  the output schemas of the bundle
     * @return string the system text of a run: the prompt parts, then each output schema once
     */
    public function systemText(array $parts, array $schemas): string;

    /**
     * @param  array<string, mixed>  $source
     * @return ?string why the pinned script, background text or design no longer serves, null while they still do
     */
    public function sourceProblem(string $projectId, array $source): ?string;
}
