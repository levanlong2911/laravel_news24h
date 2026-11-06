<?php

namespace App\Jobs\Video;

use App\Services\Video\DesignDnaService;
use App\Services\Video\LocationWriterService;
use App\Services\Video\ModelSheetService;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\QueuedStageService;
use App\Services\Video\ReferenceWriterService;
use App\Services\Video\SceneWriterService;
use App\Services\Video\ScriptWriterService;
use App\Services\Video\ShotWriterService;
use App\Services\Video\YachtDesignService;
use App\Services\VideoProjectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class RunPlanningStageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var array<string, class-string<QueuedStageService>> the planning steps a worker may run, each with the service that runs it */
    public const STEPS = [
        'inspiration' => VideoProjectService::class,
        'dna' => DesignDnaService::class,
        'design' => YachtDesignService::class,
        'qa' => ModelSheetService::class,
        'locations' => LocationWriterService::class,
        'script' => ScriptWriterService::class,
        'scenes' => SceneWriterService::class,
        'shots' => ShotWriterService::class,
        'references' => ReferenceWriterService::class,
    ];

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $step, public readonly string $stageId, public readonly string $claimToken, int $timeout)
    {
        $this->timeout = $timeout;
    }

    public function handle(): void
    {
        $service = self::STEPS[$this->step] ?? throw new InvalidArgumentException("Bước {$this->step} không chạy được bằng hàng đợi.");
        [, $reason] = app($service)->execute($this->stageId, $this->claimToken);

        Log::info('planning stage job: the queued run ended', ['step' => $this->step, 'stage_id' => $this->stageId, 'reason' => Str::limit($reason, 300)]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('planning stage job: the worker stopped the run', ['step' => $this->step, 'stage_id' => $this->stageId, 'exception' => $e]);

        app(PlanningStageStore::class)->finishFailed(
            $this->stageId,
            $this->claimToken,
            'Tiến trình chạy nền dừng: '.Str::limit($e?->getMessage() ?? 'không rõ lý do', 300).' — không tự gửi lại yêu cầu tính phí; bấm tạo lại nếu cần.',
        );
    }
}
