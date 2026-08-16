<?php

namespace App\Jobs;

use App\Enums\VideoSessionStatus;
use App\Models\VideoSession;
use App\Services\VideoSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class BuildVideoPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $sessionCode)
    {
        $this->onConnection((string) config('video.planning_queue.connection'));
        $this->onQueue((string) config('video.planning_queue.name'));
    }

    public function handle(VideoSessionService $service): void
    {
        $this->debugStep('job_received');
        Log::info('video:build-plan job: bat dau', ['code' => $this->sessionCode]);
        $startedAt = microtime(true);
        $ok = $service->runVideoPlanningPipeline($this->sessionCode);

        $this->debugStep('job_finished', [
            'success' => $ok,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        Log::info($ok ? 'video:build-plan job: hoan tat' : 'video:build-plan job: that bai', [
            'code' => $this->sessionCode,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        VideoSession::query()
            ->where('code', $this->sessionCode)
            ->where('status', VideoSessionStatus::PLANNING->value)
            ->update([
                'status' => VideoSessionStatus::FAILED->value,
                'error_message' => 'Queue worker failed before video planning completed.',
                'planning_claimed_at' => null,
                'planning_claim_token' => null,
            ]);

        Log::error('video:build-plan job: worker that bai ngoai pipeline', [
            'code' => $this->sessionCode,
            'exception' => $exception,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function debugStep(string $step, array $context = []): void
    {
        if (! app()->environment('local') || ! (bool) config('app.debug')) {
            return;
        }

        dump(['queue_step' => $step, 'session_code' => $this->sessionCode] + $context);
    }
}
