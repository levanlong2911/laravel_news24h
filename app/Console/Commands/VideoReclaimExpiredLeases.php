<?php

namespace App\Console\Commands;

use App\Services\VideoSessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Claim/lease shot (§18.30 nhóm 1, migration 2026_08_12_100000) tự hết hạn
 * nếu worker Python chết giữa chừng, nhưng KHÔNG tự giải phóng — phải có ai
 * gọi `reclaimExpiredLeases()`. Trước lệnh này, đường DUY NHẤT là
 * `POST /api/video-shots/reclaim-expired` — không ai gọi định kỳ, nên shot
 * kẹt ở `claimed`/`rendering` vĩnh viễn cho tới khi có người phát hiện bằng
 * tay. Lệnh này cho phép lên lịch qua `Kernel::schedule()`.
 */
class VideoReclaimExpiredLeases extends Command
{
    protected $signature = 'video:reclaim-expired-leases';

    protected $description = 'Đưa shot có lease hết hạn (worker chết giữa chừng) về lại queued';

    public function __construct(private VideoSessionService $videoSessionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $requeued = $this->videoSessionService->reclaimExpiredShotLeases();

        if ($requeued > 0) {
            Log::info('video:reclaim-expired-leases: da requeue shot co lease het han', [
                'requeued' => $requeued,
            ]);
        }

        $this->info("Da requeue {$requeued} shot.");

        return self::SUCCESS;
    }
}
