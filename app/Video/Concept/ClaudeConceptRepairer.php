<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Claude\ClaudeSchemaAdapter;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Concept\Validation\ValidationError;
use InvalidArgumentException;
use JsonException;

final class ClaudeConceptRepairer
{
    public function __construct(
        private readonly StructuredOutputLlmClient $client,
        private readonly EffectiveConceptSchemaBuilder $schemaBuilder,
        private readonly ClaudeSchemaAdapter $schemaAdapter,
        private readonly CanonicalJsonPayloadFactory $payloadFactory,
        private readonly string $model,
        private readonly string $systemPrompt,
        private readonly int $maxTokens = 8192,
        private readonly CanonicalConceptLifecycle $lifecycle = new NullCanonicalConceptLifecycle,
    ) {}

    public function withLifecycle(
        CanonicalConceptLifecycle $lifecycle
    ): self {
        return new self(
            client: $this->client,
            schemaBuilder: $this->schemaBuilder,
            schemaAdapter: $this->schemaAdapter,
            payloadFactory: $this->payloadFactory,
            model: $this->model,
            systemPrompt: $this->systemPrompt,
            maxTokens: $this->maxTokens,
            lifecycle: $lifecycle,
        );
    }

    /**
     * @param  list<ValidationError>  $errors
     *
     * @throws JsonException
     */
    public function repair(
        ConceptInput $input,
        string $failedRawJson,
        array $errors,
    ): CanonicalJsonPayload {
        if ($errors === []) {
            throw new InvalidArgumentException(
                'Repair requires at least '
                .'one validation error.'
            );
        }

        /*
         * Repair PHAI dung dung schema family cua lan sinh dau: cung
         * canonical@x+profile@y, khong duoc roi ve Core tho.
         */
        $effective =
            $this->schemaBuilder
                ->build(
                    $input->profile
                );

        $providerSchema =
            $this->schemaAdapter
                ->adapt(
                    $effective->schema
                );

        $this->lifecycle->repairStarted();

        $response =
            $this->client
                ->create(
                    model: $this->model,

                    system: $this->systemPrompt,

                    messages: [
                        [
                            'role' => 'user',

                    'content' => $this->buildRepairContent(
                        input: $input,

                        effectiveSchema: $effective->schema,

                        failedRawJson: $failedRawJson,

                        errors: $errors,
                            ),
                        ],
                    ],

                    outputSchema: $providerSchema,

                    maxTokens: $this->maxTokens,
                );

        $this->lifecycle->repairCompleted($response);

        return $this->payloadFactory
            ->create(
                $response->rawText
            );
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function buildRepairContent(
        ConceptInput $input,
        array $effectiveSchema,
        string $failedRawJson,
        array $errors
    ): string {
        $errorPayload =
            array_map(
                static fn (
                    ValidationError $error
                ): array => $error->toArray(),

                $errors
            );

        /*
         * failedRawJson co the technically
         * la invalid canonical JSON shape
         * nhung van phai la valid JSON text
         * de toi duoc semantic repair flow.
         *
         * Ta truyen no duoi dang string field,
         * khong splice truc tiep vao JSON.
         */
        return json_encode(
            [
                'task' => 'Repair the failed canonical '
                    .'design specification.',

                'output_contract' => [
                    'Return an object with exactly one key: canonical_json.',
                    'canonical_json must be a JSON-encoded string containing the complete corrected canonical design specification.',
                    'The JSON inside canonical_json must validate against the same effective schema and must not contain markdown.',
                ],

                'effective_schema' => $effectiveSchema,

                'rules' => [
                    'Repair only the reported validation errors and any directly dependent inconsistencies.',
                    'Preserve all already-valid design decisions whenever possible.',
                    'Do not redesign the subject.',
                    'Do not add unrelated features.',
                    'Do not remove valid identity-defining constraints.',
                    'Return the complete corrected canonical specification.',
                ],

                'original_input' => $input->toArray(),

                'failed_spec_json' => $failedRawJson,

                'validation_errors' => $errorPayload,
            ],
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        );
    }
}
