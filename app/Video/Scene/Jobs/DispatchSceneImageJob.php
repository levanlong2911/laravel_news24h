<?php

declare(strict_types=1);

namespace App\Video\Scene\Jobs;

use App\Models\VideoRenderPlan;
use App\Models\VideoShot;
use App\Video\Identity\Models\IdentityLock;
use App\Video\Scene\Services\SceneExecutionPacketBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchSceneImageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $renderPlanId,
        public readonly string $shotId,
        public readonly string $identityLockId,
    ) {
    }

    public function handle(SceneExecutionPacketBuilder $packets): void
    {
        $packets->build(
            VideoRenderPlan::query()->findOrFail($this->renderPlanId),
            VideoShot::query()->findOrFail($this->shotId),
            IdentityLock::query()->findOrFail($this->identityLockId),
        );
    }
}
