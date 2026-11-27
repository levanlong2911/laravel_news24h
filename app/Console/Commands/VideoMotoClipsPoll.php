<?php

namespace App\Console\Commands;

use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Services\Video\MotoClipRenderService;
use App\Video\Concept\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class VideoMotoClipsPoll extends Command
{
    /** @var string how long an attempt that could not move waits before it is asked again, so it never holds the places of the others */
    private const BACKOFF = '+5 minutes';

    protected $signature = 'video:moto-clips-poll {--limit=20}';

    protected $description = 'Follow the clip attempts of the shot flow in the background, so one finishes after the page closes. It reads and downloads what was sent and never sends again.';

    public function handle(MotoClipRenderService $renders, Clock $clock, VideoRenderRepositoryInterface $renderRows): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('--limit must be at least 1.');

            return self::FAILURE;
        }

        $now = $clock->now();
        // An attempt whose outcome is unknown and whose job nobody knows cannot move here: it waits for a person, so it is left out and takes no place.
        $ids = $renderRows->findDueMotoClipRenderIds($now, $limit);

        foreach ($ids as $id) {
            try {
                [$ok, $reason] = $renders->track((string) $id);
                $this->line($id.' '.($ok ? 'ok' : 'wait').' '.$reason);

                if (! $ok && in_array($reason, ['not_running', 'poll_busy'], true)) {
                    $renderRows->postponeNextPoll($id, $now->modify(self::BACKOFF));
                }
            } catch (Throwable $e) {
                Log::warning('video:moto-clips-poll: không theo được một lượt clip', ['render_id' => $id, 'error' => $e->getMessage()]);
                $this->warn($id.' '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
