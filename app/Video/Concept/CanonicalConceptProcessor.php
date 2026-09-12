<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\Persistence\Enums\ValidationStage;
use App\Video\Concept\Processing\ProcessedCanonicalConcept;
use App\Video\Concept\Processing\ValidationStageReport;
use App\Video\Concept\Schema\EffectiveConceptSchema;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Concept\Serialization\CanonicalJsonSerializer;
use App\Video\Concept\Validation\CanonicalSchemaValidator;
use App\Video\Concept\Validation\CanonicalSemanticValidator;
use App\Video\Concept\Validation\EffectiveSchemaValidator;
use App\Video\Concept\Validation\ValidationError;
use App\Video\Concept\Validation\ValidationResult;
use RuntimeException;
use Throwable;

final class CanonicalConceptProcessor
{
    /**
     * So bao cao cua LAN process nay.
     *
     * Dat o thuoc tinh chu khong truyen tay qua muoi cho: duong that bai
     * nem exception tu giua chung, ma exception van phai mang duoc nhung
     * chang DA chay. Truyen tay thi som muon quen mot nhanh throw.
     *
     * @var list<ValidationStageReport>
     */
    private array $reports = [];

    public function __construct(
        private readonly CanonicalSchemaValidator $coreSchemaValidator,
        private readonly EffectiveSchemaValidator $effectiveSchemaValidator,
        private readonly EffectiveConceptSchemaBuilder $schemaBuilder,
        private readonly CanonicalSemanticValidator $semanticValidator,
        private readonly CanonicalDesignSpecNormalizer $normalizer,
        private readonly CanonicalJsonSerializer $serializer,
        private readonly CanonicalConceptLifecycle $lifecycle = new NullCanonicalConceptLifecycle,
    ) {}

    public function withLifecycle(
        CanonicalConceptLifecycle $lifecycle
    ): self {
        return new self(
            coreSchemaValidator: $this->coreSchemaValidator,
            effectiveSchemaValidator: $this->effectiveSchemaValidator,
            schemaBuilder: $this->schemaBuilder,
            semanticValidator: $this->semanticValidator,
            normalizer: $this->normalizer,
            serializer: $this->serializer,
            lifecycle: $lifecycle,
        );
    }

    public function process(
        string $rawJson,
        ConceptInput $input,
    ): ProcessedCanonicalConcept {
        $this->reports = [];

        /*
         * -----------------------------------------
         * 1. Build Effective Schema exactly once.
         * -----------------------------------------
         */
        $effective =
            $this->schemaBuilder
                ->build(
                    $input->profile
                );

        $this->lifecycle->validationStarted();

        /*
         * -----------------------------------------
         * 2. Core schema validation.
         * -----------------------------------------
         */
        $this->guard(
            stage: ValidationStage::CORE_SCHEMA,

            document: $rawJson,

            validatorVersion: $effective->coreVersion,

            run: fn (): ValidationResult => $this->coreSchemaValidator
                ->validateJson(
                    $rawJson
                ),

            message: 'Canonical JSON Schema '
                .'validation failed.',
        );

        /*
         * -----------------------------------------
         * 3. Effective profile validation.
         * -----------------------------------------
         */
        $this->guard(
            stage: ValidationStage::EFFECTIVE_SCHEMA,

            document: $rawJson,

            validatorVersion: $this->profileVersion($effective),

            run: fn (): ValidationResult => $this->effectiveSchemaValidator
                ->validate(
                    $rawJson,
                    $effective
                ),

            message: 'Effective profile schema '
                .'validation failed.',
        );

        /*
         * -----------------------------------------
         * 4. DTO hydration.
         * -----------------------------------------
         */
        $startedAt = hrtime(true);

        try {
            $data =
                json_decode(
                    $rawJson,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

            if (
                ! is_array($data)
                || array_is_list($data)
            ) {
                throw new RuntimeException(
                    'Canonical JSON root '
                    .'must be an object.'
                );
            }

            $spec =
                CanonicalDesignSpec::fromArray(
                    $data
                );
        } catch (Throwable $e) {
            $errors = [
                new ValidationError(
                    code: 'dto_hydration_failed',

                    path: '$',

                    message: $e->getMessage(),
                ),
            ];

            /*
             * Hydration khong chay qua validator nao nen khong co
             * ValidationResult; no van la mot chang §10.4 va van phai de
             * lai mot dong ledger.
             */
            $this->report(
                stage: ValidationStage::DTO_HYDRATION,
                passed: false,
                document: $rawJson,
                validatorVersion: $effective->coreVersion,
                errors: $errors,
                startedAt: $startedAt,
            );

            $this->fail(
                errors: $errors,
                document: $rawJson,
                message: 'Canonical DTO hydration failed.',
            );
        }

        $this->report(
            stage: ValidationStage::DTO_HYDRATION,
            passed: true,
            document: $rawJson,
            validatorVersion: $effective->coreVersion,
            errors: [],
            startedAt: $startedAt,
        );

        /*
         * -----------------------------------------
         * 5. Semantic validation.
         * -----------------------------------------
         */
        $this->guard(
            stage: ValidationStage::CATEGORY_SEMANTIC,

            document: $rawJson,

            validatorVersion: $this->profileVersion($effective),

            run: fn (): ValidationResult => $this->semanticValidator
                ->validate(
                    $spec,
                    $input
                ),

            message: 'Canonical semantic validation failed.',
        );

        /*
         * -----------------------------------------
         * 6. Normalize.
         * -----------------------------------------
         */
        $this->lifecycle->normalizationStarted();

        $normalized =
            $this->normalizer
                ->normalize(
                    $spec
                );

        /*
         * -----------------------------------------
         * 7. Schema-aware canonical serialization.
         * -----------------------------------------
         */
        $canonicalJson =
            $this->serializer
                ->serialize(
                    spec: $normalized,

                    schema: $effective,
                );

        /*
         * IMPORTANT:
         *
         * Tu day canonicalJson chinh la
         * candidate frozen bytes.
         */

        /*
         * -----------------------------------------
         * 8. Core re-validation.
         * -----------------------------------------
         */
        $this->guard(
            stage: ValidationStage::POST_NORMALIZATION_CORE,

            document: $canonicalJson,

            validatorVersion: $effective->coreVersion,

            run: fn (): ValidationResult => $this->coreSchemaValidator
                ->validateJson(
                    $canonicalJson
                ),

            message: 'Canonical JSON Schema '
                .'validation failed.',
        );

        /*
         * -----------------------------------------
         * 9. Effective schema re-validation.
         * -----------------------------------------
         */
        $this->guard(
            stage: ValidationStage::POST_NORMALIZATION_EFFECTIVE,

            document: $canonicalJson,

            validatorVersion: $this->profileVersion($effective),

            run: fn (): ValidationResult => $this->effectiveSchemaValidator
                ->validate(
                    $canonicalJson,
                    $effective
                ),

            message: 'Canonical serialized document '
                .'failed effective schema validation.',
        );

        $this->lifecycle->normalizationCompleted($canonicalJson);

        /*
         * -----------------------------------------
         * 10. Semantic re-validation.
         * -----------------------------------------
         */
        $this->guard(
            stage: ValidationStage::POST_NORMALIZATION_SEMANTIC,

            document: $canonicalJson,

            validatorVersion: $this->profileVersion($effective),

            run: fn (): ValidationResult => $this->semanticValidator
                ->validate(
                    $normalized,
                    $input
                ),

            message: 'Canonical serialized document '
                .'failed semantic validation.',
        );

        $this->lifecycle->validationCompleted($this->reports);

        /*
         * -----------------------------------------
         * 11. Return exactly what was validated.
         * -----------------------------------------
         */
        return new ProcessedCanonicalConcept(
            spec: $normalized,

            canonicalJson: $canonicalJson,

            effectiveSchema: $effective,

            validationReports: $this->reports,
        );
    }

    /**
     * Chay mot chang, ghi bao cao, roi nem neu truot.
     *
     * @param  callable():ValidationResult  $run
     */
    private function guard(
        ValidationStage $stage,
        string $document,
        string $validatorVersion,
        callable $run,
        string $message,
    ): void {
        $startedAt = hrtime(true);

        $result = $run();

        $this->report(
            stage: $stage,
            passed: $result->passes(),
            document: $document,
            validatorVersion: $validatorVersion,
            errors: $result->errors,
            startedAt: $startedAt,
        );

        if ($result->fails()) {
            $this->fail(
                errors: $result->errors,
                document: $document,
                message: $message,
            );
        }
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function report(
        ValidationStage $stage,
        bool $passed,
        string $document,
        string $validatorVersion,
        array $errors,
        int|float $startedAt,
    ): void {
        $this->reports[] = new ValidationStageReport(
            stage: $stage,

            passed: $passed,

            /*
             * Hash CUA CHINH bytes vua kiem. Chang 2-5 kiem raw cua model,
             * chang 8-10 kiem bytes da serialize — hai gia tri phai khac
             * nhau, va cho nao chung bang nhau moi la dieu dang ngo.
             */
            documentHash: hash('sha256', $document),

            validatorVersion: $validatorVersion,

            errors: $errors,

            durationMs: (int) round(
                (hrtime(true) - $startedAt) / 1000000
            ),
        );
    }

    /**
     * @param  list<ValidationError>  $errors
     * @return never
     */
    private function fail(
        array $errors,
        string $document,
        string $message,
    ): void {
        /*
         * Bao cao truoc, loi sau. Neu doi lai, mot lan ghi ledger hong se
         * nuot mat ca chuoi chang da chay — dung luc chung dang la thu duy
         * nhat giai thich duoc vi sao lan nay truot.
         */
        $this->lifecycle->validationCompleted($this->reports);

        $this->lifecycle->validationFailed(
            $document,
            $this->errors($errors),
        );

        throw new CanonicalValidationException(
            errors: $errors,

            failedRawJson: $document,

            message: $message,

            validationReports: $this->reports,
        );
    }

    /**
     * Effective schema khong tu khai version; danh tinh cua no la cap
     * profile key + version, va do chinh la thu xac dinh validator nao da
     * chay tren tai lieu.
     */
    private function profileVersion(
        EffectiveConceptSchema $effective
    ): string {
        return $effective->profileKey
            .'@'
            .$effective->profileVersion;
    }

    /**
     * @param  list<ValidationError>  $errors
     * @return list<array<string,mixed>>
     */
    private function errors(array $errors): array
    {
        return array_map(
            static fn (ValidationError $error): array => $error->toArray(),
            $errors,
        );
    }
}
