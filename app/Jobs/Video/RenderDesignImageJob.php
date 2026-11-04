<?php

namespace App\Jobs\Video;

use App\Services\Video\ImageRenderQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RenderDesignImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /** @param string $token the run this job may send; a cell queued again carries a new token, so an older job sends nothing */
    public function __construct(public readonly string $step, public readonly string $imageId, public readonly string $token)
    {
        $this->timeout = ImageRenderQueue::jobSeconds();
    }

    public function handle(ImageRenderQueue $queue): void
    {
        [, $reason] = $queue->run($this->step, $this->imageId, $this->token);

        Log::info('design image job: the queued render ended', ['step' => $this->step, 'image_id' => $this->imageId, 'reason' => Str::limit($reason, 300)]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('design image job: the worker stopped the render', ['step' => $this->step, 'image_id' => $this->imageId, 'exception' => $e]);

        app(ImageRenderQueue::class)->abandon($this->imageId, $this->token, Str::limit($e?->getMessage() ?? 'không rõ lý do', 300));
    }
}
