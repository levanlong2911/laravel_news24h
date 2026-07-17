<?php

namespace App\Services\AI\Provider;

use App\Services\AI\Provider\Dto\RenderArtifact;
use App\Services\AI\Provider\Dto\RenderStatusResult;
use App\Services\AI\Provider\Dto\RenderSubmitResult;
use App\Services\AI\Provider\Dto\RenderVideoRequest;

/**
 * Contract for AI video generation providers (Kling, Veo, Runway, Pika, ...).
 *
 * The pipeline layer depends only on this interface.
 * Adding a new provider = implement this interface + register in ProviderRegistry.
 * Jobs never import any concrete provider class.
 */
interface RenderVideoProvider
{
    /** Unique identifier for this provider, e.g. 'kling', 'veo', 'runway'. */
    public function providerId(): string;

    /** Submit a new video generation task and return the task handle. */
    public function submit(RenderVideoRequest $request): RenderSubmitResult;

    /** Poll the current status of an existing task. */
    public function status(string $taskId): RenderStatusResult;

    /** Retrieve the final artifact for a completed task. Throws if not yet complete. */
    public function artifact(string $taskId): RenderArtifact;

    /** Cancel a running task (best-effort; providers may not support this). */
    public function cancel(string $taskId): void;

    /**
     * Seconds to wait before the next poll attempt.
     * Each provider implements its own optimal schedule based on typical render times and API quotas.
     * There is no global backoff table; the caller delegates entirely to this method.
     */
    public function nextPollDelay(int $attempt, RenderStatusResult $status): int;
}
