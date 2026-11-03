<?php

declare(strict_types=1);

namespace App\Video\Shots;

use App\Models\VideoProject;
use App\Video\Script\CheckedWorkflow;

interface ShotWorkflow extends CheckedWorkflow
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
     * @throws \RuntimeException when the approved scene plan or one of its sources is missing or broken; no model is called
     */
    public function inputs(VideoProject $project, string $prompt): array;

    /**
     * @param  list<string>  $parts  the phase prompt parts of the bundle
     * @param  list<array<string, mixed>>  $schemas  the output schemas of the bundle
     * @return string the system text of a run: the prompt parts, then each output schema once
     */
    public function systemText(array $parts, array $schemas): string;

    /**
     * @param  array<string, mixed>  $context  the check context a run is built or pinned with
     * @param  string  $contract  the output contract the run is built or pinned with, ScriptEvaluation::contract()
     * @return ?string why the context does not fit the contract, null when it does
     */
    public function contextProblem(array $context, string $contract): ?string;

    /**
     * @param  array<string, mixed>  $source
     * @return ?string why the pinned scene plan no longer serves, null while it still does
     */
    public function sourceProblem(string $projectId, array $source): ?string;

    /**
     * @param  array<string, mixed>  $plan  the derived plan of a shot list
     * @return array<string, array<string, mixed>> for every shot, the images that answer its reference needs as they stand now and its readiness; an image added later changes this, never the approved content
     */
    public function resources(string $projectId, array $plan): array;
}
