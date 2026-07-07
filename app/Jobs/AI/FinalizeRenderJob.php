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

        // Idempotency: already finalized.
        if ($run->status === 'completed') {
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

        $finalizedAt = now()->toIso8601String();

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

        event(new RenderCompleted(
            pipelineRunId:  $this->pipelineRunId,
            finalizedAt:    $finalizedAt,
            providerTaskId: $output['provider_task_id'] ?? null,
            provider:       $output['provider'] ?? null,
        ));
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
