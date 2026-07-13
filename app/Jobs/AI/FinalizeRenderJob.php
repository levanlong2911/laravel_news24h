<?php

namespace App\Jobs\AI;

use App\Events\AI\RenderCompleted;
use App\Events\AI\RenderFailed;
use App\Models\PipelineRun;
use App\Services\AI\Provider\RenderVideoStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Terminal step of the render pipeline.
 *
 * Marks the PipelineRun as completed and sets finished_at.
 * This is the ONLY job that sets pipeline_run.status = 'completed'.
 *
 * Keeping completion in one place means inserting new post-render steps
 * (VirusScanJob, TranscodeJob, ThumbnailJob, ...) only requires:
 *   - adding the job to the chain before FinalizeRenderJob
 *   - no other job needs to change
 *
 * Idempotency: if the run is already completed, returns early.
 */
final class FinalizeRenderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(
        private readonly string $pipelineRunId,
    ) {}

    public function middleware(): array
    {
        return [new WithoutOverlapping("finalize:{$this->pipelineRunId}", releaseAfter: 10)];
    }

    public function handle(): void
    {
        $run    = PipelineRun::findOrFail($this->pipelineRunId);
        $output = (array) ($run->output_json ?? []);

        // Idempotency: both status AND event emission are tracked independently.
        // Checking only status=completed is insufficient — the event may not have fired
        // if the worker was killed between update() and event() on a previous attempt.
        // We only short-circuit when the event has been confirmed emitted.
        if (isset($output['event_render_completed_at'])) {
            return;
        }

        // Guard: artifact must be fully stored before we can finalize.
        // Throws so the job retries — not a permanent failure.
        if (empty($output['artifact']['storage_path']) || empty($output['artifact']['checksum_sha256'])) {
            throw new \RuntimeException(
                "Cannot finalize pipeline run '{$this->pipelineRunId}': artifact not fully stored. " .
                "storage_path=" . ($output['artifact']['storage_path'] ?? 'missing') . ', ' .
                "checksum=" . ($output['artifact']['checksum_sha256'] ?? 'missing')
            );
        }

        $finalizedAt = $output['finalized_at'] ?? now()->toIso8601String();

        // Step 1: mark DB completed (idempotent — safe to repeat if previous attempt
        // wrote status=completed but was killed before the event fired).
        if ($run->status !== 'completed') {
            $run->update([
                'status'      => 'completed',
                'output_json' => array_merge($output, [
                    'schema_name'    => 'render_output',
                    'schema_version' => 1,
                    'render_status'  => RenderVideoStatus::COMPLETED->value,
                    'finalized_at'   => $finalizedAt,
                ]),
                'finished_at' => now(),
            ]);
            $output = (array) ($run->refresh()->output_json ?? []);
        }

        // Step 2: fire event. If killed here, next retry re-enters because
        // event_render_completed_at is absent — fires event again.
        // RenderCompleted listeners must be idempotent.
        event(new RenderCompleted(
            pipelineRunId:  $this->pipelineRunId,
            finalizedAt:    $finalizedAt,
            providerTaskId: $output['provider_task_id'] ?? null,
            provider:       $output['provider'] ?? null,
        ));

        // Step 3: mark event as emitted — the true idempotency key for this job.
        $run->update([
            'output_json' => array_merge(
                (array) ($run->refresh()->output_json ?? []),
                ['event_render_completed_at' => now()->toIso8601String()],
            ),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $run    = PipelineRun::find($this->pipelineRunId);
        $output = (array) ($run?->output_json ?? []);

        $run?->update([
            'status'      => 'failed',
            'output_json' => array_merge($output, [
                'schema_name'    => 'render_output',
                'schema_version' => 1,
                'render_status'  => RenderVideoStatus::FAILED->value,
                'error'          => "Finalization failed: {$e->getMessage()}",
                'completed_at'   => now()->toIso8601String(),
            ]),
            'finished_at' => now(),
        ]);

        if (Cache::add("render-failed:{$this->pipelineRunId}", 1, (int) config('ai.events.render_failed_dedup_ttl', 3600))) {
            event(new RenderFailed(
                pipelineRunId:  $this->pipelineRunId,
                reason:         "Finalization failed: {$e->getMessage()}",
                failedAt:       now()->toIso8601String(),
                jobClass:       self::class,
                providerTaskId: $output['provider_task_id'] ?? null,
                provider:       $output['provider'] ?? null,
            ));
        }
    }
}
