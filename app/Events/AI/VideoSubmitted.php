<?php

namespace App\Events\AI;

/**
 * Fired after RenderShotVideoJob successfully submits a task to the render provider
 * and the provider_task_id is persisted to the pipeline run.
 */
final class VideoSubmitted implements LoggableRenderEvent
{
    public const VERSION = 1;

    public function __construct(
        public readonly string $pipelineRunId,
        public readonly string $provider,
        public readonly string $taskId,
        public readonly string $submittedAt,   // ISO-8601
    ) {}

    public function toLog(): array
    {
        return [
            'event_version'    => self::VERSION,
            'pipeline_run_id'  => $this->pipelineRunId,
            'provider'         => $this->provider,
            'provider_task_id' => $this->taskId,
            'submitted_at'     => $this->submittedAt,
        ];
    }
}
