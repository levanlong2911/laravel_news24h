<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;

final class NullCanonicalConceptLifecycle implements CanonicalConceptLifecycle
{
    public function generationStarted(): void {}

    public function generationCompleted(
        AnthropicStructuredOutputResponse $response
    ): void {}

    public function validationStarted(): void {}

    /**
     * @param  list<\App\Video\Concept\Processing\ValidationStageReport>  $reports
     */
    public function validationCompleted(
        array $reports
    ): void {}

    public function validationFailed(
        string $rawJson,
        array $errors
    ): void {}

    public function repairStarted(): void {}

    public function repairCompleted(
        AnthropicStructuredOutputResponse $response
    ): void {}

    public function normalizationStarted(): void {}

    public function normalizationCompleted(
        string $canonicalJson
    ): void {}

    public function freezingStarted(): void {}
}
