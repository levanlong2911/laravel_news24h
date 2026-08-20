<?php

namespace App\Console\Commands;

use App\Services\Video\DesignImageQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Song song voi `video:reclaim-expired-leases` cua shot. Lease cua o thiet ke
 * anh tu het han nhung KHONG tu giai phong — worker Python chet giua chung thi
 * o nam mai o `claimed`/`rendering`, va man hinh khong bao gio hien lai nut
 * render. Lenh nay cho phep len lich qua `Kernel::schedule()`.
 */
class VideoReclaimExpiredDesignImageLeases extends Command
{
    protected $signature = 'video:reclaim-expired-design-image-leases';

    protected $description = 'Return design image cells whose lease expired (worker died mid-render) to the queue';

    public function __construct(private DesignImageQueue $queue)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $requeued = $this->queue->reclaimExpiredLeases();

        if ($requeued > 0) {
            Log::info('video:reclaim-expired-design-image-leases: requeued cells with an expired lease', [
                'requeued' => $requeued,
            ]);
        }

        $this->info("Requeued {$requeued} design image cell(s).");

        return self::SUCCESS;
    }
}
