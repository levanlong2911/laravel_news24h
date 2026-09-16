<?php

declare(strict_types=1);

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\BuildCanonicalConcept;
use App\Video\Concept\CanonicalConceptLifecycle;
use App\Video\Concept\CanonicalConceptProcessor;
use App\Video\Concept\CanonicalJsonPayloadFactory;
use App\Video\Concept\CanonicalStructuredConceptDesigner;
use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Claude\CanonicalSchemaProvider;
use App\Video\Concept\Claude\ClaudeSchemaAdapter;
use App\Video\Concept\ClaudeConceptRepairer;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Freeze\CanonicalConceptFreezer;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Hashing\EffectiveConceptSchemaHasher;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Concept\Serialization\JsonSchemaTypeResolver;
use App\Video\Concept\Serialization\SchemaAwareCanonicalSerializer;
use App\Video\Concept\Support\Clock;
use App\Video\Concept\Validation\CanonicalCrossFieldValidator;
use App\Video\Concept\Validation\CanonicalDesignSpecValidator;
use App\Video\Concept\Validation\CanonicalPathValidator;
use App\Video\Concept\Validation\CanonicalProvenanceValidator;
use App\Video\Concept\Validation\CanonicalSchemaValidator;
use App\Video\Concept\Validation\EffectiveSchemaValidator;
use App\Video\Concept\Validation\ValidationError;
use App\Video\Concept\Validation\ValidationResult;
use App\Video\Inspiration\ExcludedContext;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Inspiration\SourceInsight;
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryCreativeProfileRegistry;
use App\Video\Profiles\CategoryCreativeProfileResolver;
use App\Video\Profiles\CategoryProfileSchemaProvider;
use App\Video\Profiles\Validation\CategorySemanticValidator;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use App\Video\Profiles\Validation\MarineVesselSemanticValidator;
use App\Video\Profiles\Validation\ProfileCompatibilityValidator;
use DateTimeImmutable;
use Tests\TestCase;

final class CanonicalConceptLifecycleIntegrationTest extends TestCase
{
    /**
     * Lay dung duong ma production lay, khong hard-code lai.
     *
     * Hard-code `resources/...` chi la doi mot ban sao lay mot ban sao khac: khi
     * `config('canonical_concept.schema.path')` doi, test se lai doc mot file khac
     * voi production ma khong ai biet.
     */
    private function schemaProvider(): CanonicalSchemaProvider
    {
        return new CanonicalSchemaProvider(
            (string) config('canonical_concept.schema.path'),
        );
    }

    public function test_10_80_generation_lifecycle_wraps_the_designer_provider_call(): void
    {
        $lifecycle = new RecordingLifecycle;

        $this->designer($this->client([$this->specJson()]))
            ->withLifecycle($lifecycle)
            ->generate($this->input());

        $this->assertSame([
            'generation_started',
            'generation_completed',
        ], $lifecycle->events);
    }

    public function test_10_81_repair_lifecycle_wraps_the_repair_provider_call(): void
    {
        $lifecycle = new RecordingLifecycle;

        $this->repairer($this->client([$this->specJson()]))
            ->withLifecycle($lifecycle)
            ->repair(
                $this->input(),
                $this->specJson(['object_type' => 'aircraft']),
                [new ValidationError('semantic', '$', 'broken')],
            );

        $this->assertSame([
            'repair_started',
            'repair_completed',
        ], $lifecycle->events);
    }

    public function test_10_82_processor_lifecycle_marks_validation_and_normalization_success(): void
    {
        $lifecycle = new RecordingLifecycle;

        $this->processor()
            ->withLifecycle($lifecycle)
            ->process($this->specJson(), $this->input());

        $this->assertSame([
            'validation_started',
            'normalization_started',
            'normalization_completed',
            'validation_completed',
        ], $lifecycle->events);
    }

    public function test_10_83_core_schema_failure_is_reported_to_lifecycle(): void
    {
        $lifecycle = new RecordingLifecycle;

        try {
            $this->processor()
                ->withLifecycle($lifecycle)
                ->process('not json', $this->input());

            $this->fail('Expected core schema rejection.');
        } catch (CanonicalValidationException) {
            $this->assertSame([
                'validation_started',
                // `validationCompleted` GHI BAO CAO validation, ke ca bao cao that bai; no
                // khong phai "da dau". `validationFailed` moi la cho doi state. Day la
                // callback cua fixture test, khong phai CanonicalEventType duoc luu.
                'validation_completed',
                'validation_failed',
            ], $lifecycle->events);
            $this->assertSame('not json', $lifecycle->failedRawJson);
            $this->assertSame('invalid_json', $lifecycle->failedErrors[0]['code']);
        }
    }

    public function test_10_84_effective_schema_failure_is_reported_to_lifecycle(): void
    {
        $lifecycle = new RecordingLifecycle;

        try {
            $this->processor()
                ->withLifecycle($lifecycle)
                ->process($this->specJson(['schema_version' => '2.0']), $this->input());

            $this->fail('Expected effective schema rejection.');
        } catch (CanonicalValidationException) {
            $this->assertSame([
                'validation_started',
                // `validationCompleted` GHI BAO CAO validation, ke ca bao cao that bai; no
                // khong phai "da dau". `validationFailed` moi la cho doi state. Day la
                // callback cua fixture test, khong phai CanonicalEventType duoc luu.
                'validation_completed',
                'validation_failed',
            ], $lifecycle->events);
        }
    }

    public function test_10_85_semantic_failure_is_reported_to_lifecycle(): void
    {
        $lifecycle = new RecordingLifecycle;

        try {
            $this->processor()
                ->withLifecycle($lifecycle)
                ->process($this->specJson([
                    'relationships' => [['value' => 9]],
                ]), $this->input());

            $this->fail('Expected semantic rejection.');
        } catch (CanonicalValidationException) {
            $this->assertSame([
                'validation_started',
                // `validationCompleted` GHI BAO CAO validation, ke ca bao cao that bai; no
                // khong phai "da dau". `validationFailed` moi la cho doi state. Day la
                // callback cua fixture test, khong phai CanonicalEventType duoc luu.
                'validation_completed',
                'validation_failed',
            ], $lifecycle->events);
            $this->assertContains(
                'count_relationship_mismatch',
                array_column($lifecycle->failedErrors, 'code'),
            );
        }
    }

    public function test_10_86_normalization_completion_happens_after_the_revalidated_bytes_exist(): void
    {
        $lifecycle = new RecordingLifecycle;

        $processed = $this->processor()
            ->withLifecycle($lifecycle)
            ->process($this->specJson(), $this->input());

        $this->assertSame($processed->canonicalJson, $lifecycle->canonicalJson);
        $this->assertJson($lifecycle->canonicalJson);
    }

    public function test_10_87_builder_lifecycle_covers_generation_processing_and_freeze(): void
    {
        $lifecycle = new RecordingLifecycle;

        $this->builder($this->client([$this->specJson()]))
            ->withLifecycle($lifecycle)
            ->build($this->input(), 1);

        $this->assertSame([
            'generation_started',
            'generation_completed',
            'validation_started',
            'normalization_started',
            'normalization_completed',
            'validation_completed',
            'freezing_started',
        ], $lifecycle->events);
    }

    public function test_10_88_builder_runs_one_repair_after_the_first_semantic_failure(): void
    {
        $lifecycle = new RecordingLifecycle;

        $this->builder($this->client([
            $this->specJson(['relationships' => [['value' => 9]]]),
            $this->specJson(),
        ]))
            ->withLifecycle($lifecycle)
            ->build($this->input(), 1);

        $this->assertSame([
            'generation_started',
            'generation_completed',
            'validation_started',
            'validation_completed',
            'validation_failed',
            'repair_started',
            'repair_completed',
            'validation_started',
            'normalization_started',
            'normalization_completed',
            'validation_completed',
            'freezing_started',
        ], $lifecycle->events);
    }

    public function test_10_89_second_semantic_failure_is_terminal_and_does_not_freeze(): void
    {
        $lifecycle = new RecordingLifecycle;

        try {
            $this->builder($this->client([
                $this->specJson(['relationships' => [['value' => 9]]]),
                $this->specJson(['relationships' => [['value' => 8]]]),
            ]))
                ->withLifecycle($lifecycle)
                ->build($this->input(), 1);

            $this->fail('Expected the second semantic failure to bubble out.');
        } catch (CanonicalValidationException) {
            $this->assertSame(1, substr_count(implode(',', $lifecycle->events), 'repair_started'));
            $this->assertNotContains('freezing_started', $lifecycle->events);
        }
    }

    public function test_10_90_repair_is_not_started_when_the_failed_raw_json_is_missing(): void
    {
        $lifecycle = new RecordingLifecycle;

        try {
            $this->processor()
                ->withLifecycle($lifecycle)
                ->process('', $this->input());

            $this->fail('Expected validation failure.');
        } catch (CanonicalValidationException $e) {
            $this->assertSame('', $e->failedRawJson());
            $this->assertNotContains('repair_started', $lifecycle->events);
        }
    }

    public function test_10_91_provider_retry_still_counts_as_one_generation_attempt_to_lifecycle(): void
    {
        $lifecycle = new RecordingLifecycle;

        $this->designer($this->client([$this->specJson()]))
            ->withLifecycle($lifecycle)
            ->generate($this->input());

        $this->assertSame(1, substr_count(implode(',', $lifecycle->events), 'generation_started'));
        $this->assertSame(1, substr_count(implode(',', $lifecycle->events), 'generation_completed'));
    }

    public function test_10_92_processor_lifecycle_can_be_swapped_without_rebuilding_the_container_singleton(): void
    {
        $first = new RecordingLifecycle;
        $second = new RecordingLifecycle;
        $processor = $this->processor();

        $processor->withLifecycle($first)->process($this->specJson(), $this->input());
        $processor->withLifecycle($second)->process($this->specJson(), $this->input());

        $this->assertSame([
            'validation_started',
            'normalization_started',
            'normalization_completed',
            'validation_completed',
        ], $first->events);
        $this->assertSame($first->events, $second->events);
    }

    private function builder(StructuredOutputLlmClient $client): BuildCanonicalConcept
    {
        return new BuildCanonicalConcept(
            $this->designer($client),
            $this->repairer($client),
            $this->processor(),
            new CanonicalConceptFreezer(
                new CanonicalDesignSpecHasher,
                new EffectiveConceptSchemaHasher,
                new CategorySemanticValidatorRegistry([
                    new class implements CategorySemanticValidator
                    {
                        public function profileKey(): string
                        {
                            return 'marine_vessel';
                        }

                        public function version(): string
                        {
                            return 'semantic-test-v1';
                        }

                        public function validate(
                            \App\Video\Concept\Canonical\CanonicalDesignSpec $spec,
                            ConceptInput $input
                        ): ValidationResult {
                            return ValidationResult::valid();
                        }
                    },
                ]),
                new class implements Clock
                {
                    public function now(): DateTimeImmutable
                    {
                        return new DateTimeImmutable('2026-08-30T08:00:00+07:00');
                    }
                },
                'claude-sonnet-5',
                'concept-v1',
            ),
        );
    }

    private function designer(StructuredOutputLlmClient $client): CanonicalStructuredConceptDesigner
    {
        return new CanonicalStructuredConceptDesigner(
            $client,
            $this->schemaBuilder(),
            new ClaudeSchemaAdapter,
            new CanonicalJsonPayloadFactory,
            'claude-sonnet-5',
            'system',
        );
    }

    private function repairer(StructuredOutputLlmClient $client): ClaudeConceptRepairer
    {
        return new ClaudeConceptRepairer(
            $client,
            $this->schemaBuilder(),
            new ClaudeSchemaAdapter,
            new CanonicalJsonPayloadFactory,
            'claude-sonnet-5',
            'system',
        );
    }

    private function processor(): CanonicalConceptProcessor
    {
        return new CanonicalConceptProcessor(
            new CanonicalSchemaValidator($this->schemaProvider()),
            new EffectiveSchemaValidator,
            $this->schemaBuilder(),
            new CanonicalDesignSpecValidator(
                new CanonicalCrossFieldValidator(new CanonicalPathValidator),
                new CanonicalProvenanceValidator(new CanonicalPathValidator),
                new ProfileCompatibilityValidator(
                    new CategoryCreativeProfileResolver(
                        new CategoryCreativeProfileRegistry([$this->profile()]),
                        ['yacht' => 'marine_vessel'],
                        'marine_vessel',
                    )
                ),
                new CategorySemanticValidatorRegistry([
                    new MarineVesselSemanticValidator,
                ]),
            ),
            new CanonicalDesignSpecNormalizer,
            new SchemaAwareCanonicalSerializer(new JsonSchemaTypeResolver),
        );
    }

    private function schemaBuilder(): EffectiveConceptSchemaBuilder
    {
        return new EffectiveConceptSchemaBuilder(
            $this->schemaProvider(),
            new CategoryProfileSchemaProvider,
        );
    }

    private function profile(): CategoryCreativeProfile
    {
        return new CategoryCreativeProfile(
            'marine_vessel',
            '1.0',
            resource_path('ai/schemas/profiles/marine_vessel_v1.json'),
            ['size_and_dimensions', 'materials'],
        );
    }

    private function input(): ConceptInput
    {
        return new ConceptInput(
            'yacht',
            new InspirationBrief(
                ['design_profile'],
                'A design profile.',
                [new SourceInsight('size_and_dimensions', 'It is long.', ['120 metres'])],
                [new ExcludedContext('owner', 'Jane Doe')],
            ),
            $this->profile(),
        );
    }

    /**
     * @param  list<string>  $responses
     */
    private function client(array $responses): StructuredOutputLlmClient
    {
        return new class($responses) implements StructuredOutputLlmClient
        {
            /** @param list<string> $responses */
            public function __construct(private array $responses) {}

            public function create(
                string $model,
                string $system,
                array $messages,
                array $outputSchema,
                int $maxTokens,
                string $effort = 'high',
            ): AnthropicStructuredOutputResponse {
                $raw = array_shift($this->responses);

                if (! is_string($raw)) {
                    throw new \RuntimeException('No fake LLM response queued.');
                }

                return new AnthropicStructuredOutputResponse(
                    rawText: $raw,
                    model: $model,
                    stopReason: 'end_turn',
                    inputTokens: 100,
                    outputTokens: 200,
                    requestId: 'req_test',
                );
            }
        };
    }

    /** @param array<string, mixed> $overrides */
    private function specJson(array $overrides = []): string
    {
        return json_encode(array_replace_recursive([
            'schema_version' => '1.0',
            'object_type' => 'yacht',
            'design_thesis' => ['text' => 'One shell tapers aft.', 'role' => 'soft_design_guidance'],
            'identity' => ['subject_class' => 'marine_vessel', 'identity_basis' => ['opening_layout'], 'finish_identity_basis' => []],
            'dimensions' => ['length_m' => 120.0, 'beam_m' => 17.5],
            'permanent_geometry' => [
                'hull' => ['type' => 'displacement', 'sheer' => 'continuous'],
                'bow' => ['stem' => 'near_plumb', 'waterline_entry' => 'fine'],
                'stern' => ['type' => 'plumb_transom'],
                'superstructure' => ['primary_tier_count' => 4, 'massing' => 'central_aft'],
            ],
            'relationships' => [
                [
                    'id' => 'R001',
                    'type' => 'count',
                    'subject_path' => 'permanent_geometry.superstructure.primary_tier_count',
                    'value' => 4,
                ],
            ],
            'form_relationships' => ['hull_to_superstructure' => 'One sheer runs bow to stern.'],
            'finished_materials' => ['hull' => 'aluminium'],
            'exclusions' => [['id' => 'E001', 'target_path' => 'permanent_geometry', 'forbid' => 'cantilever']],
            'invariants' => [[
                'id' => 'I001',
                'name' => 'enclosed_deck_level_count',
                'source_path' => 'permanent_geometry.superstructure.primary_tier_count',
                'constraint_type' => 'count',
                'severity' => 'hard',
                'visual_verification' => true,
            ]],
            'provenance' => [[
                'target_path' => 'dimensions',
                'origin' => 'inspired',
                'source_aspects' => ['size_and_dimensions'],
            ]],
        ], $overrides), JSON_THROW_ON_ERROR);
    }
}

final class RecordingLifecycle implements CanonicalConceptLifecycle
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<array<string,mixed>> */
    public array $failedErrors = [];

    public string $failedRawJson = '';

    public string $canonicalJson = '';

    public function generationStarted(): void
    {
        $this->events[] = 'generation_started';
    }

    public function generationCompleted(
        AnthropicStructuredOutputResponse $response
    ): void {
        $this->events[] = 'generation_completed';
    }

    public function validationStarted(): void
    {
        $this->events[] = 'validation_started';
    }

    /** @var list<\App\Video\Concept\Processing\ValidationStageReport> */
    public array $reports = [];

    /**
     * @param  list<\App\Video\Concept\Processing\ValidationStageReport>  $reports
     */
    public function validationCompleted(
        array $reports
    ): void {
        $this->events[] = 'validation_completed';
        $this->reports = $reports;
    }

    public function validationFailed(
        string $rawJson,
        array $errors
    ): void {
        $this->events[] = 'validation_failed';
        $this->failedRawJson = $rawJson;
        $this->failedErrors = $errors;
    }

    public function repairStarted(): void
    {
        $this->events[] = 'repair_started';
    }

    public function repairCompleted(
        AnthropicStructuredOutputResponse $response
    ): void {
        $this->events[] = 'repair_completed';
    }

    public function normalizationStarted(): void
    {
        $this->events[] = 'normalization_started';
    }

    public function normalizationCompleted(
        string $canonicalJson
    ): void {
        $this->events[] = 'normalization_completed';
        $this->canonicalJson = $canonicalJson;
    }

    public function freezingStarted(): void
    {
        $this->events[] = 'freezing_started';
    }
}
