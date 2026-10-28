<?php

declare(strict_types=1);

namespace App\Video\Script;

use App\Models\VideoProject;

interface ScriptWorkflow
{
    public function format(): string;

    public function checkVersion(): string;

    public function projectionVersion(): string;

    /** @return array{execution: string, model: string, effort: string, max_tokens: int, timeout_seconds: int, brief: array{video_length: int, aspect_ratio: string, platform: string, tone_notes: string}, brief_options: array{video_length: array{0: int, 1: int}, aspect_ratios: list<string>, platform_max: int, tone_notes_max: int}, history_enabled: bool} */
    public function settings(): array;

    /**
     * @param  array<string, mixed>  $brief
     * @param  list<array<string, mixed>>  $history
     * @param  string  $prompt  the phase prompt the run is sent with
     * @return array{payload: array<string, mixed>, source: array<string, mixed>, context: array<string, mixed>}
     *
     * @throws \RuntimeException when the approved source is missing or broken; no model is called
     */
    public function inputs(VideoProject $project, array $brief, array $history, string $prompt): array;

    /** @param array<string, mixed> $source */
    public function sourceProblem(string $projectId, array $source): ?string;

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public function check(?array $response, array $context, array $schema): array;

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function derive(array $document): array;
}
