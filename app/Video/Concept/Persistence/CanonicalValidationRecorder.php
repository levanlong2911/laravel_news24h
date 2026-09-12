<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\ValidationRunStatus;
use App\Video\Concept\Persistence\Enums\ValidationStage;
use App\Video\Concept\Persistence\Models\CanonicalConceptAttempt;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Models\CanonicalValidationRun;
use App\Video\Concept\Processing\ValidationStageReport;
use App\Video\Concept\Support\Clock;
use App\Video\Concept\Validation\ValidationResult;
use Illuminate\Support\Facades\DB;

final class CanonicalValidationRecorder
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    public function record(
        CanonicalConceptRevision $revision,
        ?CanonicalConceptAttempt $attempt,
        ValidationStage $stage,
        ValidationResult $result,
        string $documentHash,
        string $validatorVersion,
        ?int $durationMs = null,
    ): CanonicalValidationRun {
        $errors = array_map(
            static fn ($error): array => $error->toArray(),
            $result->errors
        );

        return $this->write(
            revision: $revision,
            attempt: $attempt,
            stage: $stage,
            passed: $result->passes(),
            documentHash: $documentHash,
            validatorVersion: $validatorVersion,
            errors: $errors,
            durationMs: $durationMs,
        );
    }

    /**
     * Ghi tron so bao cao cua mot lan process.
     *
     * Processor la cho duy nhat biet DU ca muoi chang §10.4 da chay tren
     * bytes nao; no lai khong duoc biet DB. Bao cao di qua ranh gioi do,
     * va day la cho chung ha canh.
     *
     * Ca cum ghi trong MOT transaction: mot lan process la mot su kien,
     * luu duoc bay chang roi chet giua chang thu tam thi ledger noi doi ve
     * nhung gi da chay.
     *
     * @param  list<ValidationStageReport>  $reports
     * @return list<CanonicalValidationRun>
     */
    public function recordReports(
        CanonicalConceptRevision $revision,
        ?CanonicalConceptAttempt $attempt,
        array $reports,
    ): array {
        if ($reports === []) {
            return [];
        }

        return DB::transaction(
            function () use (
                $revision,
                $attempt,
                $reports
            ): array {
                $runs = [];

                foreach ($reports as $report) {
                    $runs[] = $this->write(
                        revision: $revision,
                        attempt: $attempt,
                        stage: $report->stage,
                        passed: $report->passed,
                        documentHash: $report->documentHash,
                        validatorVersion: $report->validatorVersion,
                        errors: $report->errorPayload(),
                        durationMs: $report->durationMs,
                    );
                }

                return $runs;
            }
        );
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function write(
        CanonicalConceptRevision $revision,
        ?CanonicalConceptAttempt $attempt,
        ValidationStage $stage,
        bool $passed,
        string $documentHash,
        string $validatorVersion,
        array $errors,
        ?int $durationMs,
    ): CanonicalValidationRun {
        return CanonicalValidationRun::query()->create([
            'canonical_concept_revision_id' => $revision->id,
            'canonical_concept_attempt_id' => $attempt?->id,
            'stage' => $stage,
            'status' => $passed
                ? ValidationRunStatus::PASSED
                : ValidationRunStatus::FAILED,
            'errors' => $errors === [] ? null : $errors,
            'error_count' => count($errors),
            'document_hash' => $documentHash,
            'validator_version' => $validatorVersion,
            'duration_ms' => $durationMs,
            'validated_at' => $this->clock->now(),
        ]);
    }
}
