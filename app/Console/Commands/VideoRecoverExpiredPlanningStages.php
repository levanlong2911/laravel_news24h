<?php

namespace App\Console\Commands;

use App\Services\Video\ImageRenderQueue;
use App\Services\Video\PlanningStageStore;
use App\Video\Queue\VideoQueues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoRecoverExpiredPlanningStages extends Command
{
    protected $signature = 'video:recover-expired-planning-stages {--limit= : Số lượt tối đa mỗi loại trong một lần}';

    protected $description = 'Đánh dấu lỗi các lượt planning hết lease, và các lượt chờ không còn job trong hàng đợi; không gửi lại yêu cầu tính phí';

    public function __construct(private readonly PlanningStageStore $stageStore, private readonly ImageRenderQueue $images)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?? config('video.async.supervisor.recovery_batch'));

        if ($limit < 1) {
            $this->error('--limit phải từ 1 trở lên.');

            return self::FAILURE;
        }

        $video = (array) config('queue.connections.'.VideoQueues::CONNECTION);
        $runId = (string) Str::uuid();

        try {
            $jobs = DB::connection($video['connection'] ?? null)->table((string) $video['table']);
            $hasJob = static fn (string $id): bool => (clone $jobs)->where('queue', 'like', VideoQueues::likePattern())->where('payload', 'like', '%'.$id.'%')->exists();
            $grace = (int) config('video.async.supervisor.pending_grace_seconds');
            $recovered = $this->stageStore->recoverExpired($limit, $grace, $hasJob) + ['images' => $this->images->recoverOrphans($limit, $grace, $hasJob)];
        } catch (\Throwable $e) {
            Log::error('video:recover-expired-planning-stages: the pass failed', ['exception' => $e]);
            $this->status(['last' => ['result' => 'failed', 'run_id' => $runId, 'at' => time(), 'error' => mb_substr($e->getMessage(), 0, 300)]]);
            $this->error('Thu hồi lỗi: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($recovered['running'] + $recovered['pending'] + $recovered['images'] > 0) {
            Log::warning('video:recover-expired-planning-stages: marked stale runs failed', $recovered);
        }

        $this->status(['last' => ['result' => 'success', 'run_id' => $runId, 'at' => time()] + $recovered, 'ok_at' => time()]);
        $this->info("Đã thu hồi {$recovered['running']} lượt hết lease, {$recovered['pending']} lượt chờ không còn job và {$recovered['images']} ô ảnh chờ không còn job.");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $state the outcome of this pass as `last`, merged over the file so a failure keeps the time of the last success */
    private function status(array $state): void
    {
        File::ensureDirectoryExists(VideoQueues::stateDirectory());
        $path = VideoQueues::recoveryStatusPath();
        $previous = json_decode((string) @file_get_contents($path), true);
        $temporary = $path.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, (string) json_encode($state + (is_array($previous) ? $previous : []), JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($temporary, $path);
        }
    }
}
