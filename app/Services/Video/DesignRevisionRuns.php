<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoPlanningStage;

/**
 * What a hand revision of a design needs from the design runs: the run the project would make now,
 * the check a run of the model gets, the output it stores and whether a stored design was written
 * from a given input. YachtDesignService gives it; nothing here calls a model or writes a row.
 */
interface DesignRevisionRuns
{
    /**
     * @return array<string, mixed> the prepared run of the project now
     *
     * @throws \RuntimeException when the project or its sources cannot give a run
     */
    public function runFor(string $projectId): array;

    /**
     * @param  array<string, mixed>  $run
     * @param  ?array<string, mixed>  $manifest
     * @return array{0: ?object, 1: array<string, mixed>}
     */
    public function evaluateAnswer(string $raw, array $run, ?array $manifest): array;

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    public function outputFor(array $run, string $raw): array;

    /** @param  array<string, mixed>  $input */
    public function sameInput(VideoPlanningStage $design, array $input): bool;
}
