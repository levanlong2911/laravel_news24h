<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Freeze\CanonicalConceptFreezer;
use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Processing\ProcessedCanonicalConcept;

final class BuildCanonicalConcept
{
    public function __construct(
        private readonly CanonicalStructuredConceptDesigner $designer,
        private readonly ClaudeConceptRepairer $repairer,
        private readonly CanonicalConceptProcessor $processor,
        private readonly CanonicalConceptFreezer $freezer,
        private readonly CanonicalConceptLifecycle $lifecycle = new NullCanonicalConceptLifecycle,
    ) {}

    public function withLifecycle(
        CanonicalConceptLifecycle $lifecycle
    ): self {
        return new self(
            designer: $this->designer->withLifecycle($lifecycle),
            repairer: $this->repairer->withLifecycle($lifecycle),
            processor: $this->processor->withLifecycle($lifecycle),
            freezer: $this->freezer,
            lifecycle: $lifecycle,
        );
    }

    /**
     * @param  string|null  $resumeRawJson  raw output da checkpoint tu lan
     *                      chay truoc. Co gia tri thi KHONG goi Sonnet nua
     *                      (§10.66): mot worker chet sau khi da luu ket qua
     *                      khong duoc phep tra tien lan thu hai cho dung
     *                      cai da co.
     * @param  bool  $repairAllowed  false khi diem tiep tuc CHINH LA ket
     *                      qua cua lan sua. Suat sua da tieu roi; cho phep
     *                      sua tiep la pha hop dong "dung mot lan" cua
     *                      Phan 5.
     */
    public function build(
        ConceptInput $input,
        int $revision,
        ?string $resumeRawJson = null,
        bool $repairAllowed = true,
    ): FrozenCanonicalConcept {
        try {
            /*
             * INITIAL GENERATION
             */
            $rawJson = $resumeRawJson ?? $this->designer
                ->generate(
                    $input
                )
                ->rawJson;

            /*
             * INITIAL VALIDATION
             */
            $processed =
                $this->processor
                    ->process(
                        rawJson: $rawJson,

                        input: $input,
                    );

            return $this->freeze(
                processed: $processed,
                revision: $revision,
            );
        } catch (
            CanonicalValidationException $firstFailure
        ) {
            /*
             * =====================================
             * EXACTLY ONE SEMANTIC REPAIR
             * =====================================
             */

            if (! $repairAllowed) {
                throw $firstFailure;
            }

            $failedRawJson =
                $firstFailure
                    ->failedRawJson();

            $errors =
                $firstFailure
                    ->errors();

            if (
                $failedRawJson === null
                || trim($failedRawJson) === ''
                || $errors === []
            ) {
                throw $firstFailure;
            }

            /*
             * REPAIR CALL #1 AND ONLY #1
             */
            $repaired =
                $this->repairer
                    ->repair(
                        input: $input,

                        failedRawJson: $failedRawJson,

                        errors: $errors,
                    );

            /*
             * Full validation again.
             *
             * IMPORTANT:
             * Khong catch validation exception nay.
             */
            $processed =
                $this->processor
                    ->process(
                        rawJson: $repaired->rawJson,

                        input: $input,
                    );

            return $this->freeze(
                processed: $processed,
                revision: $revision,
            );
        }
    }

    /*
     * Tu Phan 9, metadata do Freezer tu dung: no la noi duy nhat biet
     * canonicalJson nao vua duoc validate, va hash phai bam dung byte do.
     */
    private function freeze(
        ProcessedCanonicalConcept $processed,
        int $revision,
    ): FrozenCanonicalConcept {
        $this->lifecycle->freezingStarted();

        return $this->freezer
            ->freeze(
                processed: $processed,
                revision: $revision,
            );
    }
}
