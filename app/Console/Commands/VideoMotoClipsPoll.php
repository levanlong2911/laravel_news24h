<?php

namespace App\Console\Commands;

use App\Models\VideoRender;
use App\Services\Video\MotoClipRenderService;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class VideoMotoClipsPoll extends Command
{
    /** @var string how long an attempt that could not move waits before it is asked again, so it never holds the places of the others */
    private const BACKOFF = '+5 minutes';

    protected $signature = 'video:moto-clips-poll {--limit=20}';

    protected $description = 'Follow the clip attempts of the shot flow in the background, so one finishes after the page closes. It reads and downloads what was sent and never sends again.';

    public function handle(MotoClipRenderService $renders, Clock $clock): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('--limit must be at least 1.');

            return self::FAILURE;
        }

        $now = $clock->now();
        $free = static fn ($lease) => $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', $now);
        // An attempt whose outcome is unknown and whose job nobody knows cannot move here: it waits for a person, so it is left out and takes no place.
        $ids = VideoRender::query()
            ->where('render_kind', 'video')
            ->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->whereHas('shot', static fn ($shot) => $shot->where('shot_type', MotoClipRenderService::SHOT_TYPE))
            ->where(static fn ($due) => $due->whereNull('provider_next_poll_at')->orWhere('provider_next_poll_at', '<=', $now))
            ->where(static fn ($movable) => $movable
                ->where('execution_status', RenderStatus::PROVIDER_RUNNING->value)
                ->orWhere(static fn ($stalled) => $stalled
                    ->whereIn('execution_status', [RenderStatus::POLLING->value, RenderStatus::ARTIFACT_WRITING->value])
                    ->where('lease_expires_at', '<', $now))
                ->orWhere(static fn ($known) => $known
                    ->whereIn('execution_status', [RenderStatus::SUBMITTING->value, RenderStatus::SUBMITTED->value, RenderStatus::PROVIDER_UNKNOWN->value])
                    ->where($free)
                    ->where(static fn ($job) => $job
                        ->where(static fn ($column) => $column->whereNotNull('provider_job_id')->where('provider_job_id', '<>', ''))
                        ->orWhereExists(static fn ($receipt) => $receipt->select(DB::raw(1))->from('video_provider_submission_receipts')
                            ->whereColumn('video_provider_submission_receipts.render_id', 'video_renders.id')))))
            ->orderBy('provider_next_poll_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                [$ok, $reason] = $renders->track((string) $id);
                $this->line($id.' '.($ok ? 'ok' : 'wait').' '.$reason);

                if (! $ok && in_array($reason, ['not_running', 'poll_busy'], true)) {
                    VideoRender::query()->whereKey($id)->update(['provider_next_poll_at' => $now->modify(self::BACKOFF)]);
                }
            } catch (Throwable $e) {
                Log::warning('video:moto-clips-poll: không theo được một lượt clip', ['render_id' => $id, 'error' => $e->getMessage()]);
                $this->warn($id.' '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
