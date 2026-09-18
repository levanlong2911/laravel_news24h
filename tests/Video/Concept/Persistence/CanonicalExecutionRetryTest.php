<?php

declare(strict_types=1);

namespace Tests\Video\Concept\Persistence;

use App\Models\Article;
use App\Models\VideoProject;
use App\Video\Concept\BuildCanonicalConcept;
use App\Video\Concept\CanonicalConceptProcessor;
use App\Video\Concept\CanonicalJsonPayloadFactory;
use App\Video\Concept\CanonicalStructuredConceptDesigner;
use App\Video\Concept\ClaudeConceptRepairer;
use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Claude\CanonicalSchemaProvider;
use App\Video\Concept\Claude\ClaudeSchemaAdapter;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Concept\Exceptions\AnthropicRequestException;
use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Freeze\CanonicalConceptFreezer;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Hashing\EffectiveConceptSchemaHasher;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\Persistence\CanonicalConceptExecutionService;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptType;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Models\CanonicalConceptAttempt;
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
use App\Video\Inspiration\ExcludedContext;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Inspiration\SourceInsight;
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryCreativeProfileRegistry;
use App\Video\Profiles\CategoryCreativeProfileResolver;
use App\Video\Profiles\CategoryProfileSchemaProvider;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use App\Video\Profiles\Validation\MarineVesselSemanticValidator;
use App\Video\Profiles\Validation\ProfileCompatibilityValidator;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Services\PythonRunner;
use Mockery;
use Tests\TestCase;
use Throwable;

class CanonicalExecutionRetryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['video.python_runner_enabled' => true]);

        $runner = Mockery::mock(PythonRunner::class);
        $runner->shouldReceive('runAndWait')->andReturn([true, '{"ok":true}']);
        $this->instance(PythonRunner::class, $runner);
    }

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

    public function test_provider_retry_does_not_become_a_semantic_repair(): void
    {
        $service = $this->executionService([
            new AnthropicRequestException('503 from provider'),
            $this->specJson(),
        ]);

        $input = $this->input();

        $revision = $service->create(
            $this->project()->id,
            null,
            $input,
        );

        try {
            $service->execute($revision->fresh(), $input);

            self::fail('Lan chay dau phai nem loi van chuyen.');
        } catch (AnthropicRequestException) {
        }

        $afterTransientFailure = $revision->fresh();

        self::assertNotSame(
            CanonicalConceptStatus::FAILED,
            $afterTransientFailure->status,
        );

        self::assertSame(0, (int) $afterTransientFailure->repair_count);

        $service->execute($afterTransientFailure, $input);

        $final = $revision->fresh();

        self::assertSame(CanonicalConceptStatus::FROZEN, $final->status);
        self::assertSame(0, (int) $final->repair_count);

        self::assertSame(
            1,
            $this->attemptCount($revision->id, CanonicalAttemptType::GENERATION),
        );

        self::assertSame(
            0,
            $this->attemptCount($revision->id, CanonicalAttemptType::REPAIR),
        );
    }

    public function test_second_semantic_failure_is_terminal(): void
    {
        $broken = $this->specJson([
            'relationships' => [[
                'id' => 'R001',
                'type' => 'count',
                'subject_path' => 'permanent_geometry.superstructure.does_not_exist',
                'value' => 4,
            ]],
        ]);

        $service = $this->executionService([$broken, $broken]);

        $input = $this->input();

        $revision = $service->create(
            $this->project()->id,
            null,
            $input,
        );

        try {
            $service->execute($revision->fresh(), $input);

            self::fail('Hai lan truot ngu nghia phai ket thuc revision.');
        } catch (CanonicalValidationException) {
        }

        $final = $revision->fresh();

        self::assertSame(CanonicalConceptStatus::FAILED, $final->status);
        self::assertSame(1, (int) $final->repair_count);

        self::assertSame(
            1,
            $this->attemptCount($revision->id, CanonicalAttemptType::REPAIR),
        );
    }

    private function attemptCount(
        string $revisionId,
        CanonicalAttemptType $type,
    ): int {
        return CanonicalConceptAttempt::query()
            ->where('canonical_concept_revision_id', $revisionId)
            ->where('attempt_type', $type)
            ->count();
    }

    /** @param list<string|Throwable> $responses */
    private function executionService(array $responses): CanonicalConceptExecutionService
    {
        $this->app->instance(
            BuildCanonicalConcept::class,
            $this->builder($this->client($responses)),
        );

        $this->app->forgetInstance(CanonicalConceptExecutionService::class);

        return $this->app->make(CanonicalConceptExecutionService::class);
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
                    new MarineVesselSemanticValidator,
                ]),
                new class implements Clock
                {
                    public function now(): DateTimeImmutable
                    {
                        return new DateTimeImmutable('2026-09-01T08:00:00+00:00');
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

    /** @param list<string|Throwable> $responses */
    private function client(array $responses): StructuredOutputLlmClient
    {
        return new class($responses) implements StructuredOutputLlmClient
        {
            /** @param list<string|Throwable> $responses */
            public function __construct(private array $responses) {}

            public function create(
                string $model,
                string $system,
                array $messages,
                array $outputSchema,
                int $maxTokens,
                string $effort = 'high',
            ): AnthropicStructuredOutputResponse {
                $next = array_shift($this->responses);

                if ($next instanceof Throwable) {
                    throw $next;
                }

                if (! is_string($next)) {
                    throw new \RuntimeException('No fake LLM response queued.');
                }

                return new AnthropicStructuredOutputResponse(
                    rawText: $next,
                    model: $model,
                    stopReason: 'end_turn',
                    inputTokens: 100,
                    outputTokens: 200,
                    requestId: 'req_test',
                );
            }
        };
    }

    private function project(): VideoProject
    {
        $article = Article::create([
            'keyword_id' => DB::table('keywords')->value('id'),
            'category_id' => DB::table('categories')->value('id'),
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST retry source',
            'title' => 'TEST retry article '.uniqid(),
            'slug' => 'test-retry-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);

        return VideoProject::create([
            'title' => 'TEST retry '.uniqid(),
            'article_id' => $article->id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function specJson(array $overrides = []): string
    {
        return json_encode(array_replace([
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
