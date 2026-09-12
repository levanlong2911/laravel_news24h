<?php

declare(strict_types=1);

namespace App\Video\Scene\Jobs;

use App\Models\VideoShot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

final class DispatchVideoShotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $shotId)
    {
    }

    public function handle(): void
    {
        $shot = VideoShot::query()->findOrFail($this->shotId);

        if ($shot->scene_status !== 'keyframe_ready') {
            throw new RuntimeException('Scene keyframe must be ready before video dispatch.');
        }
    }
}
