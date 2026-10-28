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

    /** @return array{execution: string, model: string, effort: string, max_tokens: int, timeout_seconds: int, reference: array<string, mixed>} */
    public function settings(): array;

    /**
     * @param  string  $prompt  the phase prompt the run is sent with
     * @return array{payload: array<string, mixed>, source: array<string, mixed>, context: array<string, mixed>}
     *
     * @throws \RuntimeException when no approved script or its source is broken; no model is called
     */
    public function inputs(VideoProject $project, string $prompt): array;

    /**
     * @param  array<string, mixed>  $source
     * @return ?string why the pinned script or design no longer serves, null while it still does
     */
    public function sourceProblem(string $projectId, array $source): ?string;
}
