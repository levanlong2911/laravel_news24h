<?php

namespace App\Jobs\AI;

use App\Events\AI\RenderFailed;
use App\Models\PipelineRun;
use App\Services\AI\Provider\Circuit\ProviderUnavailableException;
use App\Services\AI\Provider\ProviderRegistry;
use App\Services\AI\Provider\RenderVideoStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Polls a video render provider until the task reaches a terminal state.
 *
 * Backoff schedule (seconds between polls):
 *   Attempt 0 → 15s, 1 → 30s, 2 → 60s, 3 → 90s, 4 → 120s, 5 → 180s, 6+ → 300s
 *
 * Hard limits (whichever fires first):
 *   - MAX_POLLS: backstop on poll count (config ai.render_max_polls, default 40)
 *   - Wall-clock timeout from submitted_at (config ai.render_timeout_minutes, default 30)
 *
 * Idempotency: if output_json already contains a completed artifact, returns early.
 */
final class PollRenderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Each dispatch is one attempt; retries are explicit re-dispatches with incremented attempt.
    public int $tries   = 1;
    public int $timeout = 30;

    public function __construct(
        private readonly string $pipelineRunId,
        private readonly string $taskId,
        private readonly string $provider = 'kling',
        private readonly int    $pollAttempt = 0,
    ) {}

    public function middleware(): array
    {
        return [new WithoutOverlapping("poll:{$this->pipelineRunId}", releaseAfter: 30)];
    }

    public function handle(ProviderRegistry $registry): void
    {
        $run    = PipelineRun::findOrFail($this->pipelineRunId);
        $output = (array) ($run->output_json ?? []);

        // Idempotency: artifact already dispatched for storage or fully stored — nothing to do.
        if (
            isset($output['artifact']['cdn_url']) ||   // StoreArtifactJob already dispatched
            isset($output['artifact']['storage_path'])  // StoreArtifactJob already completed
        ) {
            return;
        }

        // Hard limit 1: wall-clock timeout from submitted_at.
        if ($this->isExpired($output)) {
            $run->update([
                'status'      => 'failed',
                'output_json' => array_merge($output, [
                    'schema_name'    => 'render_output',
                    'schema_version' => 1,
                    'render_status'  => RenderVideoStatus::TIMEOUT->value,
                    'poll_attempts'  => $this->pollAttempt,
                    'error'          => "Render timed out after {$this->pollAttempt} polls (submitted_at: " . ($output['submitted_at'] ?? 'unknown') . ')',
                    'completed_at'   => now()->toIso8601String(),
                ]),
                'finished_at' => now(),
            ]);
            return;
        }

        // Hard limit 2: poll count backstop.
        $maxPolls = (int) config('ai.render_max_polls', 40);
        if ($this->pollAttempt >= $maxPolls) {
            $run->update([
                'status'      => 'failed',
                'output_json' => array_merge($output, [
                    'schema_name'    => 'render_output',
                    'schema_version' => 1,
                    'render_status'  => RenderVideoStatus::TIMEOUT->value,
                    'poll_attempts'  => $this->pollAttempt,
                    'error'          => "Render timed out after {$maxPolls} polls",
                    'completed_at'   => now()->toIso8601String(),
                ]),
                'finished_at' => now(),
            ]);
            return;
        }

        try {
            $provider = $registry->make($this->provider);
            $status   = $provider->status($this->taskId);
        } catch (ProviderUnavailableException $e) {
            // Circuit is open — dispatch a fresh job so no retry attempt is consumed.
            // release() would decrement tries; self::dispatch() creates an independent job.
            self::dispatch($this->pipelineRunId, $this->taskId, $this->provider, $this->pollAttempt)
                ->delay(now()->addSeconds($e->releaseDelay()))
                ->onQueue((string) config('ai.render_queue', 'rendering'));
            return;
        }

        // Still in progress — re-queue; provider decides the backoff schedule.
        // dispatch() not release() so that tries=1 is never consumed by normal re-polling.
        if (! $status->isTerminal()) {
            $delay = $provider->nextPollDelay($this->pollAttempt, $status);
            self::dispatch($this->pipelineRunId, $this->taskId, $this->provider, $this->pollAttempt + 1)
                ->delay(now()->addSeconds($delay))
                ->onQueue((string) config('ai.render_queue', 'rendering'));
            return;
        }

        // Terminal: COMPLETED or FAILED.
        if ($status->isSuccess()) {
            try {
                $artifact = $provider->artifact($this->taskId);
            } catch (ProviderUnavailableException $e) {
                // Artifact metadata fetch failed while circuit opened — re-queue to retry later.
                self::dispatch($this->pipelineRunId, $this->taskId, $this->provider, $this->pollAttempt)
                    ->delay(now()->addSeconds($e->releaseDelay()))
                    ->onQueue((string) config('ai.render_queue', 'rendering'));
                return;
            }

            // Transition to STORING — atomic: update DB and enqueue StoreArtifactJob together.
            // afterCommit() ensures the job is dispatched only after the transaction commits.
            // If the process is killed between update() and dispatch(), the transaction rolls back
            // and the idempotency guard (cdn_url absent) lets the next retry re-enter this path.
            $pipelineRunId    = $this->pipelineRunId;
            $taskId           = $this->taskId;
            $pollAttempt      = $this->pollAttempt;
            $renderQueue      = (string) config('ai.render_queue', 'rendering');

            DB::transaction(function () use (
                $run, $output, $artifact,
                $pipelineRunId, $taskId, $pollAttempt, $renderQueue,
            ): void {
                $run->update([
                    'output_json' => array_merge($output, [
                        'schema_name'    => 'render_output',
                        'schema_version' => 1,
                        'render_status'  => RenderVideoStatus::STORING->value,
                        'poll_attempts'  => $pollAttempt + 1,
                        'artifact'       => [
                            'cdn_url'           => $artifact->videoUrl,
                            'cdn_thumbnail_url' => $artifact->thumbnailUrl,
                            'duration_seconds'  => $artifact->durationSeconds,
                        ],
                    ]),
                ]);

                StoreArtifactJob::dispatch(
                    $pipelineRunId,
                    $taskId,
                    $artifact->videoUrl,
                    $artifact->thumbnailUrl,
                    $artifact->durationSeconds,
                )->afterCommit()->onQueue($renderQueue);
            });
        } else {
            $run->update([
                'status'      => 'failed',
                'output_json' => array_merge($output, [
                    'schema_name'    => 'render_output',
                    'schema_version' => 1,
                    'render_status'  => RenderVideoStatus::FAILED->value,
                    'poll_attempts'  => $this->pollAttempt + 1,
                    'completed_at'   => now()->toIso8601String(),
                    'error'          => $status->errorMessage,
                ]),
                'finished_at' => now(),
            ]);
        }
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
                'poll_attempts'  => $this->pollAttempt,
                'error'          => $e->getMessage(),
                'completed_at'   => now()->toIso8601String(),
            ]),
            'finished_at' => now(),
        ]);

        if (Cache::add("render-failed:{$this->pipelineRunId}", 1, (int) config('ai.events.render_failed_dedup_ttl', 3600))) {
            event(new RenderFailed(
                pipelineRunId: $this->pipelineRunId,
                reason:        $e->getMessage(),
                failedAt:      now()->toIso8601String(),
                jobClass:      self::class,
                providerTaskId: $this->taskId,
                provider:       $this->provider,
            ));
        }
    }

    private function isExpired(array $output): bool
    {
        if (! isset($output['submitted_at'])) {
            return false;
        }

        $timeoutMinutes = (int) config('ai.render_timeout_minutes', 30);
        $submittedAt    = \Carbon\Carbon::parse($output['submitted_at']);

        return $submittedAt->addMinutes($timeoutMinutes)->isPast();
    }
}
