<?php

declare(strict_types=1);

namespace App\Providers;

use App\Video\Concept\BuildCanonicalConcept;
use App\Video\Concept\CanonicalConceptProcessor;
use App\Video\Concept\CanonicalJsonPayloadFactory;
use App\Video\Concept\CanonicalStructuredConceptDesigner;
use App\Video\Concept\Claude\AnthropicStructuredOutputClient;
use App\Video\Concept\Claude\CanonicalSchemaProvider;
use App\Video\Concept\Claude\ClaudeSchemaAdapter;
use App\Video\Concept\ClaudeConceptRepairer;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Concept\Freeze\CanonicalConceptFreezer;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Hashing\EffectiveConceptSchemaHasher;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\OpenAi\TextClientStructuredAdapter;
use App\Video\Concept\Orchestration\CanonicalConceptOrchestrator;
use App\Video\Concept\Orchestration\CanonicalConceptInputBuilder;
use App\Video\Concept\Persistence\CanonicalAttemptWriter;
use App\Video\Concept\Persistence\CanonicalCheckpointService;
use App\Video\Concept\Persistence\CanonicalConceptExecutionCursor;
use App\Video\Concept\Persistence\CanonicalConceptExecutionService;
use App\Video\Concept\Persistence\CanonicalConceptInputLoader;
use App\Video\Concept\Persistence\CanonicalConceptInputSnapshotter;
use App\Video\Concept\Persistence\CanonicalConceptPersistenceService;
use App\Video\Concept\Persistence\CanonicalConceptRevisionService;
use App\Video\Concept\Persistence\CanonicalProcessingObserver;
use App\Video\Concept\Persistence\CanonicalEventWriter;
use App\Video\Concept\Persistence\CanonicalFailureService;
use App\Video\Concept\Persistence\CanonicalFreezePersistenceService;
use App\Video\Concept\Persistence\CanonicalRepairClaimService;
use App\Video\Concept\Persistence\CanonicalSessionAttachmentService;
use App\Video\Concept\Persistence\CanonicalStateTransitionService;
use App\Video\Concept\Persistence\CanonicalValidationRecorder;
use App\Video\Concept\Persistence\EloquentCanonicalProcessingObserver;
use App\Video\Concept\Persistence\FrozenCanonicalConceptHydrator;
use App\Video\Concept\Persistence\Ledger\CanonicalDecisionExtractor;
use App\Video\Concept\Persistence\Ledger\DecisionLedgerWriter;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use App\Video\Concept\Persistence\Repository\EloquentCanonicalConceptRepository;
use App\Video\Concept\Persistence\StateMachine\CanonicalConceptStateMachine;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Concept\Serialization\CanonicalJsonSerializer;
use App\Video\Concept\Serialization\JsonSchemaTypeResolver;
use App\Video\Concept\Serialization\SchemaAwareCanonicalSerializer;
use App\Video\Concept\Support\Clock;
use App\Video\Concept\Support\PromptFileLoader;
use App\Video\Concept\Support\SystemClock;
use App\Video\Concept\Validation\CanonicalCrossFieldValidator;
use App\Video\Concept\Validation\CanonicalDesignSpecValidator;
use App\Video\Concept\Validation\CanonicalPathValidator;
use App\Video\Concept\Validation\CanonicalProvenanceValidator;
use App\Video\Concept\Validation\CanonicalSchemaValidator;
use App\Video\Concept\Validation\CanonicalSemanticValidator;
use App\Video\Concept\Validation\EffectiveSchemaValidator;
use App\Video\Extraction\ClaudeExtractor;
use App\Video\Extraction\Extractor;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\InspirationBuilder;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Prompt\OpenAiTextClient;
use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryCreativeProfileRegistry;
use App\Video\Profiles\CategoryCreativeProfileResolver;
use App\Video\Profiles\CategoryProfileSchemaProvider;
use App\Video\Profiles\ProfileSystemIntegrityChecker;
use App\Video\Profiles\Validation\AircraftSemanticValidator;
use App\Video\Profiles\Validation\ArchitectureSemanticValidator;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use App\Video\Profiles\Validation\GenericPhysicalObjectSemanticValidator;
use App\Video\Profiles\Validation\IndustrialMachineSemanticValidator;
use App\Video\Profiles\Validation\MarineVesselSemanticValidator;
use App\Video\Profiles\Validation\ProfileCompatibilityValidator;
use App\Video\Profiles\Validation\RoadVehicleSemanticValidator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class CanonicalConceptServiceProvider extends ServiceProvider
{
    /*
     * KHONG mergeConfigFrom(): config/canonical_concept.php nam thang trong
     * app nen Laravel tu nap. mergeConfigFrom chi can khi subsystem duoc dong
     * thanh package.
     */
    public function register(): void
    {
        /*
         * -----------------------------------------
         * SUPPORT
         * -----------------------------------------
         */
        $this->app->singleton(
            Clock::class,
            SystemClock::class
        );

        $this->app->singleton(
            PromptFileLoader::class
        );

        $this->app->singleton(
            Extractor::class,
            function (
                Application $app
            ): Extractor {
                return new ClaudeExtractor(
                    $app->make(
                        ClaudeWriterAdapter::class
                    )
                );
            }
        );

        $this->app->singleton(
            ClaudeInspirationAnalyst::class,
            function (
                Application $app
            ): ClaudeInspirationAnalyst {
                return new ClaudeInspirationAnalyst(
                    $app->make(
                        ClaudeWriterAdapter::class
                    )
                );
            }
        );

        $this->app->singleton(
            InspirationBuilder::class
        );

        $this->app->singleton(
            CanonicalConceptInputBuilder::class
        );

        /*
         * -----------------------------------------
         * SCHEMA
         * -----------------------------------------
         */
        $this->app->singleton(
            CanonicalSchemaProvider::class,
            function (
                Application $app
            ): CanonicalSchemaProvider {
                $path =
                    config(
                        'canonical_concept.schema.path'
                    );

                if (
                    ! is_string($path)
                    || trim($path) === ''
                ) {
                    throw new RuntimeException(
                        'canonical_concept.schema.path '
                        .'is not configured.'
                    );
                }

                return new CanonicalSchemaProvider(
                    schemaPath: $path
                );
            }
        );

        $this->app->singleton(
            ClaudeSchemaAdapter::class
        );

        /*
         * -----------------------------------------
         * CATEGORY PROFILES
         * -----------------------------------------
         */
        $this->app->singleton(
            CategoryCreativeProfileRegistry::class,
            function (): CategoryCreativeProfileRegistry {
                $config =
                    config(
                        'category_creative_profiles.profiles',
                        []
                    );

                if (! is_array($config)) {
                    throw new RuntimeException(
                        'category_creative_profiles.profiles must be an array.'
                    );
                }

                $profiles = [];

                foreach (
                    $config as $key => $item
                ) {
                    if (
                        ! is_string($key)
                        || ! is_array($item)
                    ) {
                        throw new RuntimeException(
                            'Invalid category creative profile configuration.'
                        );
                    }

                    $version =
                        $item['version']
                        ?? null;

                    $schemaPath =
                        $item['schema_path']
                        ?? null;

                    $inspectionAspects =
                        $item['inspection_aspects']
                        ?? null;

                    if (
                        ! is_string($version)
                        || ! is_string($schemaPath)
                        || ! is_array($inspectionAspects)
                    ) {
                        throw new RuntimeException(
                            sprintf(
                                'Invalid category creative profile "%s".',
                                $key
                            )
                        );
                    }

                    $profiles[] =
                        new CategoryCreativeProfile(
                            key: $key,

                            version: $version,

                            schemaPath: $schemaPath,

                            inspectionAspects: array_values(
                                $inspectionAspects
                            ),
                        );
                }

                return new CategoryCreativeProfileRegistry(
                    $profiles
                );
            }
        );

        $this->app->singleton(
            CategoryProfileSchemaProvider::class
        );

        $this->app->singleton(
            CategoryCreativeProfileResolver::class,
            function (
                Application $app
            ): CategoryCreativeProfileResolver {
                $mapping =
                    config(
                        'category_creative_profiles.object_type_map',
                        []
                    );

                if (! is_array($mapping)) {
                    throw new RuntimeException(
                        'category_creative_profiles.object_type_map must be an array.'
                    );
                }

                $fallback =
                    config(
                        'category_creative_profiles.fallback_profile',
                        'generic_physical_object'
                    );

                if (
                    ! is_string($fallback)
                    || trim($fallback) === ''
                ) {
                    throw new RuntimeException(
                        'Invalid fallback category profile.'
                    );
                }

                return new CategoryCreativeProfileResolver(
                    registry: $app->make(
                        CategoryCreativeProfileRegistry::class
                    ),
                    objectTypeMap: $mapping,
                    fallbackProfileKey: $fallback,
                );
            }
        );

        $this->app->singleton(
            EffectiveConceptSchemaBuilder::class
        );

        $this->app->singleton(
            EffectiveSchemaValidator::class
        );

        /*
         * -----------------------------------------
         * CATEGORY SEMANTIC VALIDATION
         * -----------------------------------------
         */
        $this->app->singleton(
            GenericPhysicalObjectSemanticValidator::class
        );

        $this->app->singleton(
            MarineVesselSemanticValidator::class
        );

        $this->app->singleton(
            AircraftSemanticValidator::class
        );

        $this->app->singleton(
            ArchitectureSemanticValidator::class
        );

        $this->app->singleton(
            RoadVehicleSemanticValidator::class
        );

        $this->app->singleton(
            IndustrialMachineSemanticValidator::class
        );

        $this->app->singleton(
            ProfileCompatibilityValidator::class
        );

        $this->app->singleton(
            CategorySemanticValidatorRegistry::class,
            function (
                Application $app
            ): CategorySemanticValidatorRegistry {
                return new CategorySemanticValidatorRegistry([
                    $app->make(
                        GenericPhysicalObjectSemanticValidator::class
                    ),

                    $app->make(
                        MarineVesselSemanticValidator::class
                    ),

                    $app->make(
                        AircraftSemanticValidator::class
                    ),

                    $app->make(
                        ArchitectureSemanticValidator::class
                    ),

                    $app->make(
                        RoadVehicleSemanticValidator::class
                    ),

                    $app->make(
                        IndustrialMachineSemanticValidator::class
                    ),
                ]);
            }
        );

        $this->app->singleton(
            ProfileSystemIntegrityChecker::class
        );

        /*
         * -----------------------------------------
         * JSON PAYLOAD
         * -----------------------------------------
         */
        $this->app->singleton(
            CanonicalJsonPayloadFactory::class
        );

        /*
         * -----------------------------------------
         * VALIDATION
         * -----------------------------------------
         */
        $this->app->singleton(
            CanonicalPathValidator::class
        );

        $this->app->singleton(
            CanonicalCrossFieldValidator::class
        );

        $this->app->singleton(
            CanonicalProvenanceValidator::class
        );

        $this->app->singleton(
            CanonicalSchemaValidator::class,
            function (
                Application $app
            ): CanonicalSchemaValidator {
                return new CanonicalSchemaValidator(
                    $app->make(
                        CanonicalSchemaProvider::class
                    ),
                );
            }
        );

        $this->app->singleton(
            CanonicalDesignSpecValidator::class,
            function (
                Application $app
            ): CanonicalDesignSpecValidator {
                return new CanonicalDesignSpecValidator(
                    crossFieldValidator: $app->make(
                        CanonicalCrossFieldValidator::class
                    ),

                    provenanceValidator: $app->make(
                        CanonicalProvenanceValidator::class
                    ),

                    profileCompatibilityValidator: $app->make(
                        ProfileCompatibilityValidator::class
                    ),

                    categoryValidatorRegistry: $app->make(
                        CategorySemanticValidatorRegistry::class
                    ),
                );
            }
        );

        $this->app->alias(
            CanonicalDesignSpecValidator::class,
            CanonicalSemanticValidator::class
        );

        /*
         * -----------------------------------------
         * NORMALIZATION
         * -----------------------------------------
         */
        $this->app->singleton(
            CanonicalDesignSpecNormalizer::class
        );

        /*
         * -----------------------------------------
         * HASH
         * -----------------------------------------
         */
        $this->app->singleton(
            CanonicalDesignSpecHasher::class
        );

        $this->app->singleton(
            EffectiveConceptSchemaHasher::class
        );

        $this->app->singleton(
            JsonSchemaTypeResolver::class
        );

        $this->app->singleton(
            SchemaAwareCanonicalSerializer::class
        );

        /*
         * Serializer nhan biet schema: no la noi DUY NHAT quyet dinh byte
         * canonical. Validator duyet dung byte do, hasher bam dung byte do.
         */
        $this->app->singleton(
            CanonicalJsonSerializer::class,
            SchemaAwareCanonicalSerializer::class
        );

        /*
         * -----------------------------------------
         * PROCESSOR
         * -----------------------------------------
         */
        $this->app->singleton(
            CanonicalConceptProcessor::class,
            function (
                Application $app
            ): CanonicalConceptProcessor {
                return new CanonicalConceptProcessor(
                    coreSchemaValidator: $app->make(
                        CanonicalSchemaValidator::class
                    ),

                    schemaBuilder: $app->make(
                        EffectiveConceptSchemaBuilder::class
                    ),

                    effectiveSchemaValidator: $app->make(
                        EffectiveSchemaValidator::class
                    ),

                    semanticValidator: $app->make(
                        CanonicalSemanticValidator::class
                    ),

                    normalizer: $app->make(
                        CanonicalDesignSpecNormalizer::class
                    ),

                    serializer: $app->make(
                        CanonicalJsonSerializer::class
                    ),
                );
            }
        );

        /*
         * -----------------------------------------
         * FREEZER
         * -----------------------------------------
         */
        $this->app->singleton(
            CanonicalConceptFreezer::class,
            function (
                Application $app
            ): CanonicalConceptFreezer {
                return new CanonicalConceptFreezer(
                    designHasher: $app->make(
                        CanonicalDesignSpecHasher::class
                    ),

                    schemaHasher: $app->make(
                        EffectiveConceptSchemaHasher::class
                    ),

                    semanticValidators: $app->make(
                        CategorySemanticValidatorRegistry::class
                    ),

                    clock: $app->make(
                        Clock::class
                    ),

                    conceptModel: $this->model(),

                    conceptPromptVersion: $this->promptVersion(),
                );
            }
        );

        /*
         * -----------------------------------------
         * PERSISTENCE
         * -----------------------------------------
         */
        $this->app->singleton(CanonicalConceptInputSnapshotter::class);
        $this->app->singleton(CanonicalConceptInputLoader::class);
        $this->app->singleton(CanonicalConceptStateMachine::class);
        $this->app->singleton(CanonicalEventWriter::class);
        $this->app->singleton(CanonicalAttemptWriter::class);
        $this->app->singleton(CanonicalValidationRecorder::class);
        $this->app->singleton(CanonicalDecisionExtractor::class);
        $this->app->singleton(DecisionLedgerWriter::class);
        $this->app->singleton(CanonicalConceptExecutionCursor::class);
        $this->app->singleton(FrozenCanonicalConceptHydrator::class);
        $this->app->singleton(CanonicalSessionAttachmentService::class);

        $this->app->singleton(
            CanonicalConceptRepository::class,
            EloquentCanonicalConceptRepository::class
        );

        $this->app->singleton(CanonicalStateTransitionService::class);
        $this->app->singleton(EloquentCanonicalProcessingObserver::class);
        $this->app->singleton(
            CanonicalProcessingObserver::class,
            EloquentCanonicalProcessingObserver::class
        );
        $this->app->singleton(CanonicalCheckpointService::class);
        $this->app->singleton(CanonicalRepairClaimService::class);
        $this->app->singleton(CanonicalFailureService::class);
        $this->app->singleton(CanonicalFreezePersistenceService::class);
        $this->app->singleton(CanonicalConceptPersistenceService::class);
        $this->app->singleton(CanonicalConceptExecutionService::class);

        $this->app->singleton(
            CanonicalConceptRevisionService::class,
            function (): CanonicalConceptRevisionService {
                return new CanonicalConceptRevisionService(
                    repository: $this->app->make(CanonicalConceptRepository::class),
                    events: $this->app->make(CanonicalEventWriter::class),
                    canonicalSchemaVersion: '1.0',
                    conceptModel: $this->model(),
                    conceptPromptVersion: $this->promptVersion(),
                );
            }
        );

        /*
         * -----------------------------------------
         * ANTHROPIC CLIENT
         * -----------------------------------------
         */
        $this->app->singleton(
            AnthropicStructuredOutputClient::class,
            function (
                Application $app
            ): AnthropicStructuredOutputClient {
                $apiKey =
                    config(
                        'canonical_concept.'
                        .'anthropic.api_key'
                    );

                $baseUrl =
                    config(
                        'canonical_concept.'
                        .'anthropic.base_url'
                    );

                $apiVersion =
                    config(
                        'canonical_concept.'
                        .'anthropic.api_version'
                    );

                if (
                    ! is_string($apiKey)
                    || trim($apiKey) === ''
                ) {
                    throw new RuntimeException(
                        'CLAUDE_API_KEY '
                        .'is not configured.'
                    );
                }

                if (
                    ! is_string($baseUrl)
                    || trim($baseUrl) === ''
                ) {
                    throw new RuntimeException(
                        'Anthropic base URL '
                        .'is not configured.'
                    );
                }

                if (
                    ! is_string($apiVersion)
                    || trim($apiVersion) === ''
                ) {
                    throw new RuntimeException(
                        'Anthropic API version '
                        .'is not configured.'
                    );
                }

                return new AnthropicStructuredOutputClient(
                    http: $app->make(
                        HttpFactory::class
                    ),

                    apiKey: $apiKey,

                    baseUrl: $baseUrl,

                    apiVersion: $apiVersion,

                    timeoutSeconds: (int) config(
                        'canonical_concept.'
                        .'anthropic.timeout',
                        120
                    ),

                    retryTimes: (int) config(
                        'canonical_concept.'
                        .'anthropic.http_retry_times',
                        2
                    ),

                    retrySleepMs: (int) config(
                        'canonical_concept.'
                        .'anthropic.http_retry_sleep_ms',
                        500
                    ),
                );
            }
        );

        /*
         * Designer/Repairer phu thuoc vao INTERFACE, khong vao HTTP client.
         * Sau nay noi vao ha tang LlmClient san co chi la doi binding nay.
         */
        $this->app->singleton(
            StructuredOutputLlmClient::class,
            function (
                Application $app
            ): StructuredOutputLlmClient {
                if ($this->provider() !== 'openai') {
                    return $app->make(
                        AnthropicStructuredOutputClient::class
                    );
                }

                $apiKey =
                    config(
                        'canonical_concept.'
                        .'openai.api_key'
                    );

                if (
                    ! is_string($apiKey)
                    || trim($apiKey) === ''
                ) {
                    throw new RuntimeException(
                        'OPENAI_API_KEY '
                        .'is not configured.'
                    );
                }

                return new TextClientStructuredAdapter(
                    new OpenAiTextClient(
                        http: $app->make(
                            HttpFactory::class
                        ),

                        apiKey: $apiKey,

                        baseUrl: (string) config(
                            'canonical_concept.'
                            .'openai.base_url'
                        ),

                        reasoningEffort: (string) config(
                            'canonical_concept.'
                            .'openai.reasoning_effort'
                        ),

                        timeoutSeconds: (int) config(
                            'canonical_concept.'
                            .'openai.timeout',
                            600
                        ),

                        retryTimes: (int) config(
                            'canonical_concept.'
                            .'openai.http_retry_times',
                            2
                        ),

                        retrySleepMs: (int) config(
                            'canonical_concept.'
                            .'openai.http_retry_sleep_ms',
                            500
                        ),
                    ),
                );
            }
        );

        /*
         * -----------------------------------------
         * DESIGNER
         * -----------------------------------------
         */
        $this->app->singleton(
            CanonicalStructuredConceptDesigner::class,
            function (
                Application $app
            ): CanonicalStructuredConceptDesigner {
                $loader =
                    $app->make(
                        PromptFileLoader::class
                    );

                $promptPath =
                    config(
                        'canonical_concept.'
                        .'prompts.designer'
                    );

                if (
                    ! is_string($promptPath)
                    || trim($promptPath) === ''
                ) {
                    throw new RuntimeException(
                        'Concept designer prompt '
                        .'path is not configured.'
                    );
                }

                return new CanonicalStructuredConceptDesigner(
                    client: $app->make(
                        StructuredOutputLlmClient::class
                    ),

                    schemaBuilder: $app->make(
                        EffectiveConceptSchemaBuilder::class
                    ),

                    schemaAdapter: $app->make(
                        ClaudeSchemaAdapter::class
                    ),

                    payloadFactory: $app->make(
                        CanonicalJsonPayloadFactory::class
                    ),

                    model: $this->model(),

                    systemPrompt: $loader->load(
                        $promptPath
                    ),

                    maxTokens: $this->maxTokens(),
                );
            }
        );

        /*
         * -----------------------------------------
         * REPAIRER
         * -----------------------------------------
         */
        $this->app->singleton(
            ClaudeConceptRepairer::class,
            function (
                Application $app
            ): ClaudeConceptRepairer {
                $loader =
                    $app->make(
                        PromptFileLoader::class
                    );

                $promptPath =
                    config(
                        'canonical_concept.'
                        .'prompts.repairer'
                    );

                if (
                    ! is_string($promptPath)
                    || trim($promptPath) === ''
                ) {
                    throw new RuntimeException(
                        'Concept repairer prompt '
                        .'path is not configured.'
                    );
                }

                return new ClaudeConceptRepairer(
                    client: $app->make(
                        StructuredOutputLlmClient::class
                    ),

                    schemaBuilder: $app->make(
                        EffectiveConceptSchemaBuilder::class
                    ),

                    schemaAdapter: $app->make(
                        ClaudeSchemaAdapter::class
                    ),

                    payloadFactory: $app->make(
                        CanonicalJsonPayloadFactory::class
                    ),

                    model: $this->model(),

                    systemPrompt: $loader->load(
                        $promptPath
                    ),

                    maxTokens: $this->maxTokens(),
                );
            }
        );

        /*
         * -----------------------------------------
         * BUILD WORKFLOW
         * -----------------------------------------
         */
        $this->app->singleton(
            BuildCanonicalConcept::class,
            function (
                Application $app
            ): BuildCanonicalConcept {
                return new BuildCanonicalConcept(
                    designer: $app->make(
                        CanonicalStructuredConceptDesigner::class
                    ),

                    repairer: $app->make(
                        ClaudeConceptRepairer::class
                    ),

                    processor: $app->make(
                        CanonicalConceptProcessor::class
                    ),

                    freezer: $app->make(
                        CanonicalConceptFreezer::class
                    ),
                );
            }
        );

        /*
         * -----------------------------------------
         * APPLICATION ORCHESTRATOR
         * -----------------------------------------
         */
        $this->app->singleton(
            CanonicalConceptOrchestrator::class,
            function (
                Application $app
            ): CanonicalConceptOrchestrator {
                return new CanonicalConceptOrchestrator(
                    builder: $app->make(
                        BuildCanonicalConcept::class
                    ),
                );
            }
        );
    }

    public function boot(): void
    {
        /*
         * Khong co runtime business logic o day.
         *
         * Neu package hoa subsystem sau nay,
         * co the publish config/schema/prompts
         * tai day.
         */
    }

    private function provider(): string
    {
        $provider =
            config(
                'canonical_concept.'
                .'provider',
                'anthropic'
            );

        if (
            ! is_string($provider)
            || ! in_array(
                $provider,
                ['anthropic', 'openai'],
                true
            )
        ) {
            throw new RuntimeException(
                'CANONICAL_CONCEPT_PROVIDER '
                .'must be anthropic or openai.'
            );
        }

        return $provider;
    }

    private function model(): string
    {
        $model =
            config(
                'canonical_concept.'
                .$this->provider()
                .'.model'
            );

        if (
            ! is_string($model)
            || trim($model) === ''
        ) {
            throw new RuntimeException(
                'canonical_concept.'
                .$this->provider()
                .'.model is not configured.'
            );
        }

        return $model;
    }

    private function promptVersion(): string
    {
        $value =
            config(
                'canonical_concept.'
                .'prompt_version',
                'concept-v1'
            );

        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            throw new RuntimeException(
                'canonical_concept.prompt_version '
                .'is not configured.'
            );
        }

        return $value;
    }

    private function maxTokens(): int
    {
        $value =
            (int) config(
                'canonical_concept.'
                .$this->provider()
                .'.max_tokens',
                8192
            );

        if ($value < 1) {
            throw new RuntimeException(
                'canonical_concept.'
                .$this->provider()
                .'.max_tokens must be >= 1.'
            );
        }

        return $value;
    }
}
