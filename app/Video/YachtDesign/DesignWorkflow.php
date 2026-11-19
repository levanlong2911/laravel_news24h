<?php

declare(strict_types=1);

namespace App\Video\YachtDesign;

interface DesignWorkflow
{
    public function execution(): string;

    /** @return ?string the version of the technical check a new run is pinned to; null when the workflow pins none */
    public function checkVersion(): ?string;

    /**
     * @param  ?array<string, mixed>  $manifest  the bundle the run is sent with
     * @return list<string> the markers the pinned phase-3 prompt must declare before a run is sent
     */
    public function markers(?array $manifest = null): array;

    /**
     * @param  ?array<string, mixed>  $manifest  the bundle the run is sent with
     * @return array<string, mixed> the model-sheet config the design writes its model sheet for
     */
    public function modelSheetConfig(?array $manifest = null): array;

    /**
     * @param  string  $content  an output schema as the pinned bundle stores it
     * @return string the schema text the run sends to the model
     */
    public function schemaText(string $content): string;

    /**
     * @param  string  $prompt  the pinned phase-3 prompt
     * @param  array{project_id: string, prompt_version: string, dna: array<mixed>, model_sheet: array<string, mixed>, schemas: list<array<string, string>>}  $context  the pinned sources of the run
     * @return ?array{protocol: string, sources: array<string, mixed>, sources_sha256: string, dna_sha256: string, input: array<string, mixed>, schema: string, schema_id: string, schema_sha256: string, final_schema_sha256: string, label: string} what a run pins when its prompt declares an assembly protocol: the model then writes only its part and the code joins the record; null when the model writes the whole record. `dna_sha256` is the hash of the whole DNA the model was sent, in canonical JSON, so any later change of that DNA — not only of the values the code fills — is seen when the design is checked again
     *
     * @throws \RuntimeException when the prompt declares a protocol the workflow does not run, or a value the code must fill is missing from the pinned sources
     */
    public function assembly(string $prompt, array $context): ?array;

    /**
     * @param  string  $raw  the answer of the model
     * @param  array<string, mixed>  $assembly  assembly() of the run
     * @return array{0: string, 1: array<string, mixed>} the design record as one JSON text and what the assembly found
     *
     * @throws \App\Services\Video\StageAnswerRejected when no record can be assembled from the answer
     */
    public function assemble(string $raw, array $assembly): array;

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
