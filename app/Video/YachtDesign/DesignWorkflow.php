<?php

declare(strict_types=1);

namespace App\Video\YachtDesign;

interface DesignWorkflow
{
    public function execution(): string;

    /** @return ?string the version of the technical check a new run is pinned to; null when the workflow pins none */
    public function checkVersion(): ?string;

    /** @return list<string> the markers the pinned phase-3 prompt must declare before a run is sent */
    public function markers(): array;

    /** @return array<string, mixed> the model-sheet config the design writes its model sheet for */
    public function modelSheetConfig(): array;

    /**
     * @param  string  $content  an output schema as the pinned bundle stores it
     * @return string the schema text the run sends to the model
     */
    public function schemaText(string $content): string;

    /** @return array<string, mixed> the design run settings this workflow overrides, pinned in the run's input */
    public function runSettings(): array;

    /**
     * @param  array<string, mixed>  $dna  the DNA that passed its check
     * @param  array<string, mixed>  $source  the Phase 1 block of the source, as PhaseOneEvidence::inspiration() reads it
     * @param  array<string, mixed>  $dnaPanel  the DNA panel the run is prepared from
     * @param  array<string, mixed>  $modelSheet  the pinned model-sheet presentation
     * @return array<string, mixed> the user message of the run
     */
    public function payload(string $projectId, string $promptVersion, array $dna, array $source, array $dnaPanel, array $modelSheet): array;

    /**
     * @param  array{project_id: string, prompt_version: string, dna: array<mixed>, source_identity: array<mixed>, model_sheet: array<string, mixed>, forbidden: list<string>}  $run
     * @param  ?array<string, mixed>  $manifest  the bundle the stage was sent with
     * @return array{0: ?object, 1: array<string, mixed>} the answer as stored and its technical check
     */
    public function evaluate(string $raw, array $run, ?array $manifest): array;
}
