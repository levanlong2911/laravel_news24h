<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\DTO\AttemptUsage;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptType;
use App\Video\Concept\Persistence\Models\CanonicalConceptAttempt;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Support\Clock;

final class CanonicalAttemptWriter
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    public function start(
        CanonicalConceptRevision $revision,
        CanonicalAttemptType $type,
        int $number,
        string $provider,
        string $model,
        string $promptVersion,
        string $inputHash,
        string $schemaHash,
    ): CanonicalConceptAttempt {
        return CanonicalConceptAttempt::query()->firstOrCreate(
            [
                'canonical_concept_revision_id' => $revision->id,
                'attempt_type' => $type->value,
                'attempt_number' => $number,
            ],
            [
                'status' => CanonicalAttemptStatus::STARTED->value,
                'provider' => $provider,
                'model' => $model,
                'prompt_version' => $promptVersion,
                'input_hash' => $inputHash,
                'schema_hash' => $schemaHash,
                'started_at' => $this->clock->now(),
            ]
        );
    }

    public function succeed(
        CanonicalConceptAttempt $attempt,
        string $rawOutput,
        AttemptUsage $usage = new AttemptUsage,
    ): void {
        if ($attempt->status === CanonicalAttemptStatus::SUCCEEDED) {
            return;
        }

        $attempt->forceFill([
            'status' => CanonicalAttemptStatus::SUCCEEDED,
            'raw_output' => $rawOutput,
            'raw_output_hash' => hash('sha256', $rawOutput),
            'provider_request_id' => $usage->providerRequestId,
            'stop_reason' => $usage->stopReason,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'thinking_tokens' => $usage->thinkingTokens,
            'cost_usd' => $usage->costUsd,
            'latency_ms' => $usage->latencyMs,
            'completed_at' => $this->clock->now(),
        ])->save();
    }

    public function fail(
        CanonicalConceptAttempt $attempt,
        string $errorCode,
        string $message,
        ?AttemptUsage $usage = null,
    ): void {
        if ($attempt->status !== CanonicalAttemptStatus::STARTED) {
            return;
        }

        $usage ??= AttemptUsage::empty();

        $attempt->forceFill([
            'status' => CanonicalAttemptStatus::FAILED,
            'provider_request_id' => $usage->providerRequestId,
            'stop_reason' => $usage->stopReason,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'thinking_tokens' => $usage->thinkingTokens,
            'cost_usd' => $usage->costUsd,
            'latency_ms' => $usage->latencyMs,
            'error_code' => $errorCode,
            'error_message' => $message,
            'completed_at' => $this->clock->now(),
        ])->save();
    }
}
