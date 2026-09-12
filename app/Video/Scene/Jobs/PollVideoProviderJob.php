<?php

declare(strict_types=1);

namespace App\Video\Scene\Jobs;

use App\Models\VideoRender;
use App\Video\Render\VideoProviderCheckpointService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class PollVideoProviderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $renderId,
        public readonly string $workerId = 'video-provider-poller',
    ) {
    }

    public function handle(VideoProviderCheckpointService $checkpoint): void
    {
        $checkpoint->claimPoll(
            VideoRender::query()->findOrFail($this->renderId),
            (string) Str::uuid(),
            $this->workerId,
        );
    }
}
