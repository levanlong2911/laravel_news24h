<?php

declare(strict_types=1);

namespace App\Jobs\Video;

use App\Services\Video\MotoDynamicCropService;
use App\Video\Queue\VideoQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes one queued dynamic crop on the ffmpeg lane. It runs once: a run that stops closes its row, which sent nothing anywhere, and the shot takes a new click.
 */
class RenderDynamicCropJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $renderId)
    {
        $this->timeout = self::seconds();
    }

    /** @return int how long the job may run: the FFmpeg run of the processor, the checks of its file, and the margin of the video queue */
    public static function seconds(): int
    {
        return VideoQueues::jobTimeout(MotoDynamicCropService::PROCESS_SECONDS + 60);
    }

    public function handle(MotoDynamicCropService $crops): void
    {
        [$ok, $reason] = $crops->run($this->renderId);

        Log::info('dynamic crop job: the queued crop ended', ['render_id' => $this->renderId, 'ok' => $ok, 'reason' => $reason]);
    }

    public function failed(?Throwable $e): void
    {
        app(MotoDynamicCropService::class)->abandon($this->renderId, 'Worker dừng giữa chừng: '.($e?->getMessage() ?? 'không rõ'));
    }
}
