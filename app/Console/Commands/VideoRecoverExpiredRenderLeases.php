<?php

namespace App\Console\Commands;

use App\Video\Render\Claims\ExpiredRenderLeaseRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VideoRecoverExpiredRenderLeases extends Command
{
    protected $signature = 'video:recover-expired-render-leases {--limit=100}';

    protected $description = 'Recover renders whose worker lease expired: requeue pre-submit ones, mark post-submit ones provider_unknown';

    public function __construct(private ExpiredRenderLeaseRecovery $recovery)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('--limit must be at least 1.');

            return self::FAILURE;
        }

        $recovered = $this->recovery->recover($limit);

        if ($recovered > 0) {
            Log::info('video:recover-expired-render-leases: recovered renders with an expired lease', [
                'recovered' => $recovered,
            ]);
        }

        $this->info("Recovered {$recovered} render(s).");

        return self::SUCCESS;
    }
}
