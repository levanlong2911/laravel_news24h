<?php

namespace App\Jobs\AI;

use App\Events\AI\RenderFailed;
use App\Events\AI\VideoSubmitted;
use App\Models\PipelineRun;
use App\Services\AI\Provider\Circuit\ProviderUnavailableException;
use App\Services\AI\Provider\Dto\RenderVideoRequest;
use App\Services\AI\Provider\ProviderRegistry;
use App\Services\AI\Provider\RenderVideoStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Submits a compiled shot prompt to a video render provider and schedules polling.
 *
 * Idempotency: if a task was already submitted (non-failed status in output_json),
 * the job skips the API call and resumes polling from the existing task ID.
 * This makes retries safe after transient failures (e.g. DB unavailable after submit).
 *
 * output_json schema:
 *   provider, provider_task_id, render_status, poll_attempts,
 *   submitted_at, completed_at, artifact{video_url, thumbnail_url, duration_seconds}, error
 */
final class RenderShotVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $timeout = 120;

    public function __construct(
        private readonly string $pipelineRunId,
        private readonly string $compiledPrompt,
        private readonly string $negativePrompt,
        private readonly int    $durationSeconds,
        private readonly string $aspectRatio,
        private readonly string $provider = '',
    ) {}

    public function handle(ProviderRegistry $registry): void
    {
        $run      = PipelineRun::findOrFail($this->pipelineRunId);
        $output   = (array) ($run->output_json ?? []);
        $provider = $this->resolvedProvider();

        // Idempotency: if a non-failed task_id already exists, resume polling instead of re-submitting.
        if (
            isset($output['provider_task_id'])
            && ($output['render_status'] ?? '') !== RenderVideoStatus::FAILED->value
        ) {
            $this->dispatchPoller($output['provider_task_id'], $provider);
            return;
        }

        try {
            $result = $registry->make($provider)->submit(new RenderVideoRequest(
                prompt:          $this->compiledPrompt,
                negativePrompt:  $this->negativePrompt,
                durationSeconds: $this->durationSeconds,
                aspectRatio:     $this->aspectRatio,
            ));
        } catch (ProviderUnavailableException $e) {
            // Circuit is open — dispatch a new job so no retry attempt is consumed.
            self::dispatch(
                $this->pipelineRunId,
                $this->compiledPrompt,
                $this->negativePrompt,
                $this->durationSeconds,
                $this->aspectRatio,
                $this->provider,
            )
                ->delay(now()->addSeconds($e->releaseDelay()))
                ->onQueue((string) config('ai.render_queue', 'rendering'));
            return;
        }

        $run->update([
            'output_json' => [
                'schema_name'      => 'render_output',
                'schema_version'   => 1,
                'provider'         => $provider,
                'provider_task_id' => $result->taskId,
                'render_status'    => $result->status->value,
                'poll_attempts'    => 0,
                'submitted_at'     => now()->toIso8601String(),
                'completed_at'     => null,
                'artifact'         => null,
                'error'            => null,
            ],
        ]);

        event(new VideoSubmitted(
            pipelineRunId: $this->pipelineRunId,
            provider:      $provider,
            taskId:        $result->taskId,
            submittedAt:   now()->toIso8601String(),
        ));

        $this->dispatchPoller($result->taskId, $provider);
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
                'error'          => $e->getMessage(),
            ]),
            'finished_at' => now(),
        ]);

        // Guard: Cache::add() is atomic — ensures RenderFailed fires at most once per run.
        if (Cache::add("render-failed:{$this->pipelineRunId}", 1, (int) config('ai.events.render_failed_dedup_ttl', 3600))) {
            event(new RenderFailed(
                pipelineRunId: $this->pipelineRunId,
                reason:        $e->getMessage(),
                failedAt:      now()->toIso8601String(),
                jobClass:      self::class,
                providerTaskId: $output['provider_task_id'] ?? null,
                provider:       $output['provider'] ?? ($this->provider ?: (string) config('ai.default_render_provider', 'kling')),
            ));
        }
    }

    private function resolvedProvider(): string
    {
        return $this->provider !== ''
            ? $this->provider
            : (string) config('ai.default_render_provider', 'kling');
    }

    private function dispatchPoller(string $taskId, string $provider): void
    {
        PollRenderJob::dispatch($this->pipelineRunId, $taskId, $provider, pollAttempt: 0)
            ->delay(now()->addSeconds(15))
            ->onQueue((string) config('ai.render_queue', 'rendering'));
    }
}
