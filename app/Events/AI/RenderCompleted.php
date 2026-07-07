<?php

namespace App\Events\AI;

/**
 * Fired by FinalizeRenderJob after setting pipeline_run.status = 'completed'.
 * This is the canonical signal that the entire render pipeline has succeeded.
 * Listeners can trigger downstream workflows (notifications, analytics, CDN push, ...).
 */
final class RenderCompleted implements LoggableRenderEvent
{
    public const VERSION = 1;

    public function __construct(
        public readonly string  $pipelineRunId,
        public readonly string  $finalizedAt,      // ISO-8601
        public readonly ?string $providerTaskId = null,
        public readonly ?string $provider       = null,
    ) {}

    public function toLog(): array
    {
        return [
            'event_version'    => self::VERSION,
            'pipeline_run_id'  => $this->pipelineRunId,
            'provider_task_id' => $this->providerTaskId,
            'provider'         => $this->provider,
            'finalized_at'     => $this->finalizedAt,
        ];
    }
}
