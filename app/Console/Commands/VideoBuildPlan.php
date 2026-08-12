<?php

namespace App\Console\Commands;

use App\Services\VideoSessionService;
use Illuminate\Console\Command;

/**
 * §18.30 — chạy pipeline Claude (25-90s, tốn tiền thật) cho MỘT session đang
 * `planning`. Bắn nền qua `PythonRunner::spawnArtisan()`, KHÔNG qua Laravel
 * Queue — xem lý do trong ARCHITECTURE.md §18.30 (QUEUE_CONNECTION=sync trên
 * máy này, không có queue:work nào đang chạy).
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

        return $this->videoSessionService->runVideoPlanningPipeline($code)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
