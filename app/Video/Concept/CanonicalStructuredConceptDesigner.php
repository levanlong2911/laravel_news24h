<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Claude\ClaudeSchemaAdapter;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use JsonException;

final class CanonicalStructuredConceptDesigner
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
     * @throws JsonException
     */
    public function generate(
        ConceptInput $input
    ): CanonicalJsonPayload {
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

        /*
        * TEMP DEBUG:
        * Dump effective schema and final provider schema.
        */
        $effectiveJson = json_encode(
            $effective->schema,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        $providerJson = json_encode(
            $providerSchema,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        file_put_contents(
            storage_path(
                'logs/effective_concept_schema.json'
            ),
            $effectiveJson
        );

        file_put_contents(
            storage_path(
                'logs/provider_claude_schema.json'
            ),
            $providerJson
        );

        logger()->info(
            'Canonical structured schema debug',
            [
                'effective_bytes' =>
                    strlen($effectiveJson),

                'provider_bytes' =>
                    strlen($providerJson),

                'effective_schema_id' =>
                    $effective->identifier(),
            ]
        );

        $this->lifecycle->generationStarted();

        $response =
            $this->client
                ->create(
                    model: $this->model,

                    system: $this->systemPrompt,

                    messages: [
                        [
                            'role' => 'user',

                            'content' => $this->encodeInput(
                                $input,
                                $effective->identifier(),
                                $effective->schema,
                            ),
                        ],
                    ],

                    outputSchema: $providerSchema,

                    maxTokens: $this->maxTokens,
                );

        $this->lifecycle->generationCompleted($response);

        return $this->payloadFactory
            ->create(
                $response->rawText
            );
    }

    private function encodeInput(
        ConceptInput $input,
        string $effectiveSchemaId,
        array $effectiveSchema
    ): string {
        return json_encode(
            [
                'task' => 'Create one original canonical physical design.',

                'output_contract' => [
                    'Return an object with exactly one key: canonical_json.',
                    'canonical_json must be a JSON-encoded string containing the complete canonical design specification.',
                    'The JSON inside canonical_json must validate against the effective schema and must not contain markdown.',
                ],

                'effective_schema_id' => $effectiveSchemaId,

                'effective_schema' => $effectiveSchema,

                'concept_input' => $input->toArray(),
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        );
    }
}
