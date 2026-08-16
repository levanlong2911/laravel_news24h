<?php

namespace App\Console\Commands;

use App\Services\VideoSessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * §18.30 — chạy pipeline Claude (25-90s, tốn tiền thật) cho MỘT session đang
 * `planning`. Production dispatch `BuildVideoPlanJob` vào queue database;
 * command này giữ lại làm đường vận hành/debug tương đương.
 *
 * Logic thật nằm ở `VideoSessionService::runVideoPlanningPipeline()` —
 * command này chỉ đọc `--session=` rồi gọi, giữ đúng quy ước "Command mỏng,
 * Service dày" đã dùng cho Controller.
 */
class VideoBuildPlan extends Command
{
    protected $signature = 'video:build-plan {--session= : Mã session (video_sessions.code) cần chạy pipeline}';

    protected $description = 'Chạy pipeline Claude cho một session đang planning, lưu renderplan_json rồi bắn session_runner.py';

    public function __construct(private VideoSessionService $videoSessionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $code = (string) $this->option('session');

        if ($code === '') {
            $this->error('Thieu --session=');

            return self::FAILURE;
        }

        Log::info('video:build-plan: bat dau', ['code' => $code]);
        $startedAt = microtime(true);

        try {
            $ok = $this->videoSessionService->runVideoPlanningPipeline($code);
        } catch (\Throwable $e) {
            Log::error('video:build-plan: loi ngoai du kien', [
                'code' => $code,
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception' => $e,
            ]);

            return self::FAILURE;
        }

        Log::info($ok ? 'video:build-plan: hoan tat' : 'video:build-plan: that bai', [
            'code' => $code,
            'duration_ms' => $this->elapsedMs($startedAt),
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
