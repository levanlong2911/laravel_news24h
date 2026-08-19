<?php

namespace App\Jobs;

use App\Services\VideoSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class FinalizePlanStageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $sessionCode)
    {
        $this->onConnection((string) config('video.planning_queue.connection'));
        $this->onQueue((string) config('video.planning_queue.name'));
    }

    public function handle(VideoSessionService $service): void
    {
        [$ok, $reason] = $service->runFinalizeStage($this->sessionCode);

        Log::info('finalize-stage job: ket thuc', [
            'code' => $this->sessionCode,
            'ok' => $ok,
            'reason' => $reason,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('finalize-stage job: worker that bai ngoai stage', [
            'code' => $this->sessionCode,
            'exception' => $exception,
        ]);
    }
}
