<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;

interface CanonicalConceptLifecycle
{
    public function generationStarted(): void;

    public function generationCompleted(
        AnthropicStructuredOutputResponse $response
    ): void;

    public function validationStarted(): void;

    /**
     * Ket qua tung chang §10.4, ke ca chang PASS.
     *
     * validationFailed() chi ke rang co loi, khong ke chang nao sinh ra
     * chung — nen mot mình no khong dung duoc canonical_validation_runs.
     * Moc nay mang ra ban ghi day du: chang, bytes da kiem, phien ban
     * validator, thoi gian. Duoc goi dung mot lan cho moi lan process, ca
     * khi thanh cong lan khi truot.
     *
     * @param  list<\App\Video\Concept\Processing\ValidationStageReport>  $reports
     */
    public function validationCompleted(
        array $reports
    ): void;

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    public function validationFailed(
        string $rawJson,
        array $errors
    ): void;

    public function repairStarted(): void;

    public function repairCompleted(
        AnthropicStructuredOutputResponse $response
    ): void;

    public function normalizationStarted(): void;

    public function normalizationCompleted(
        string $canonicalJson
    ): void;

    public function freezingStarted(): void;
}
