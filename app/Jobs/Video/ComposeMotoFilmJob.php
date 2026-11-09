<?php

declare(strict_types=1);

namespace App\Jobs\Video;

use App\Services\Video\MotoFilmRenderService;
use App\Video\Queue\VideoQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Cuts one requested film from the copy its request froze. It runs once: a run that stops is closed, or left to video:final-recover
 * when it already has a checked file, and is never cut again on its own.
 */
class ComposeMotoFilmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $finalId)
    {
        $this->timeout = VideoQueues::jobTimeout((int) config('video.veo.compose_budget_seconds'));
    }

    public function handle(MotoFilmRenderService $films): void
    {
        $films->compose($this->finalId);
    }

    public function failed(?Throwable $e): void
    {
        app(MotoFilmRenderService::class)->abandon($this->finalId, 'Worker dừng giữa chừng: '.($e?->getMessage() ?? 'không rõ'));
    }
}
