<?php

namespace App\Jobs\AI;

use App\Events\AI\ArtifactStored;
use App\Events\AI\RenderFailed;
use App\Models\PipelineRun;
use App\Services\AI\Artifact\ArtifactStorageInterface;
use App\Services\AI\Provider\RenderVideoStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Downloads the rendered video artifact from the provider CDN and persists it
 * to the configured internal storage disk.
 *
 * Pipeline position:
 *   PollRenderJob (COMPLETED) → StoreArtifactJob → FinalizeRenderJob
 *
 * StoreArtifactJob does NOT set pipeline_run.status = 'completed'.
 * That responsibility belongs to FinalizeRenderJob, so future steps
 * (VirusScanJob, TranscodeJob, ThumbnailJob) can be inserted before it
 * without touching this job.
 *
 * Idempotency: if output_json.artifact.storage_path already exists, skips storage
 * and dispatches FinalizeRenderJob in case it was lost previously.
 *
 * Stored artifact fields (no video_url — derive at runtime via Storage::disk()->url(path)):
 *   storage_disk, storage_path, cdn_url, cdn_thumbnail_url,
 *   duration_seconds, checksum_sha256, file_size_bytes, stored_at
 */
final class StoreArtifactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 600; // 10 min — large video files need time

    public function __construct(
        private readonly string  $pipelineRunId,
        private readonly string  $taskId,
        private readonly string  $cdnVideoUrl,
        private readonly ?string $cdnThumbnailUrl,
        private readonly float   $durationSeconds,
    ) {}

    public function handle(ArtifactStorageInterface $storage): void
    {
        $run    = PipelineRun::findOrFail($this->pipelineRunId);
        $output = (array) ($run->output_json ?? []);

        // Idempotency: artifact already stored — ensure FinalizeRenderJob is dispatched.
        if (isset($output['artifact']['storage_path'])) {
            $this->dispatchFinalizer();
            return;
        }

        $stored = $storage->store($this->taskId, $this->cdnVideoUrl, $this->pipelineRunId);

        $run->update([
            'output_json' => array_merge($output, [
                'schema_name'    => 'render_output',
                'schema_version' => 1,
                // render_status stays STORING — FinalizeRenderJob will set COMPLETED.
                'artifact'       => array_merge($output['artifact'] ?? [], [
                    'storage_disk'     => $stored->storageDisk,
                    'storage_path'     => $stored->storagePath,
                    'cdn_url'          => $this->cdnVideoUrl,
                    'cdn_thumbnail_url' => $this->cdnThumbnailUrl,
                    'duration_seconds' => $this->durationSeconds,
                    'checksum_sha256'  => $stored->checksum,
                    'file_size_bytes'  => $stored->fileSizeBytes,
                    'stored_at'        => now()->toIso8601String(),
                ]),
            ]),
        ]);

        event(new ArtifactStored(
            pipelineRunId: $this->pipelineRunId,
            taskId:        $this->taskId,
            storageDisk:   $stored->storageDisk,
            storagePath:   $stored->storagePath,
            checksum:      $stored->checksum,
            fileSizeBytes: $stored->fileSizeBytes,
            storedAt:      now()->toIso8601String(),
        ));

        $this->dispatchFinalizer();
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
                'error'          => "Artifact storage failed: {$e->getMessage()}",
                'completed_at'   => now()->toIso8601String(),
            ]),
            'finished_at' => now(),
        ]);

        if (Cache::add("render-failed:{$this->pipelineRunId}", 1, (int) config('ai.events.render_failed_dedup_ttl', 3600))) {
            event(new RenderFailed(
                pipelineRunId: $this->pipelineRunId,
                reason:        "Artifact storage failed: {$e->getMessage()}",
                failedAt:      now()->toIso8601String(),
                jobClass:      self::class,
                providerTaskId: $this->taskId,
                provider:       $output['provider'] ?? null,
            ));
        }
    }

    private function dispatchFinalizer(): void
    {
        FinalizeRenderJob::dispatch($this->pipelineRunId)
            ->onQueue((string) config('ai.render_queue', 'rendering'));
    }
}
