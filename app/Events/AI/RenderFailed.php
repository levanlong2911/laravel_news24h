<?php

namespace App\Events\AI;

/**
 * Fired from any render pipeline job's failed() hook when all retries are exhausted.
 * jobClass identifies which step permanently failed, aiding triage and alerting.
 *
 * Dedup note: each job's failed() guards with Cache::add("render-failed:{id}") so this
 * event fires at most once per pipeline run, even under queue duplication.
 *
 * providerTaskId and provider are nullable: they may be absent if the job failed
 * before a task was submitted to the provider (e.g. network error in RenderShotVideoJob).
 */
final class RenderFailed implements LoggableRenderEvent
{
    public const VERSION = 1;

    public function __construct(
        public readonly string  $pipelineRunId,
        public readonly string  $reason,
        public readonly string  $failedAt,          // ISO-8601
        public readonly string  $jobClass,           // FQCN of the job that failed
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
            'reason'           => $this->reason,
            'failed_at'        => $this->failedAt,
            'job_class'        => $this->jobClass,
        ];
    }
}
