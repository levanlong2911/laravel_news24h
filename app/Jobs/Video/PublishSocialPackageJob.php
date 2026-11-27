<?php

namespace App\Jobs\Video;

use App\Models\VideoSocialPublication;
use App\Services\Video\Social\SocialPublicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one posting of Phase 12, or looks one up that the platform was still processing or holds a time for. It runs
 * once: a posting is never sent a second time because a job was tried again. A look-up that finds the posting still
 * open queues the next one itself, up to the end of the window it was given.
 */
class PublishSocialPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  string  $mode  work: send a queued posting; follow: look up one that was sent
     * @param  ?int  $until  follow only: the Unix time after which the posting is no longer looked up on its own and a person is asked to look
     */
    public function __construct(public readonly string $publicationId, public readonly string $mode = 'work', public readonly ?int $until = null)
    {
        $this->timeout = (int) config('video.social.upload_timeout_seconds') + 3 * (int) config('video.social.http_timeout_seconds') + 300;
    }

    public function handle(SocialPublicationService $publications): void
    {
        if ($this->mode === 'work') {
            $publications->work($this->publicationId);

            return;
        }

        $publications->followUp($this->publicationId, $this->until);
    }

    public function failed(?Throwable $e): void
    {
        // Redacted: the message of an error may hold the URL of a request, and a URL may hold a token.
        Log::error('social publication job: the worker stopped', ['publication_id' => $this->publicationId, 'mode' => $this->mode, 'error' => $e === null ? null : get_class($e).': '.\App\Video\Social\SocialRedaction::text($e->getMessage())]);

        // A worker that died while sending leaves a posting that may be out: it is unknown, and is looked up, never sent again.
        VideoSocialPublication::query()->whereKey($this->publicationId)->where('status', VideoSocialPublication::UPLOADING)->update([
            'status' => VideoSocialPublication::UNKNOWN,
            'error_code' => 'worker_stopped',
            'error_message' => 'Tiến trình gửi dừng giữa chừng — chưa rõ nền tảng đã nhận gì. Bấm Đối chiếu.',
            'claim_token' => null,
        ]);
    }
}
