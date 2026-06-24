<?php

namespace App\Observers;

use App\Models\VideoJob;
use App\Services\Admin\ThumbnailLabService;

class VideoJobObserver
{
    public function __construct(private ThumbnailLabService $thumbnailLab) {}

    public function updated(VideoJob $job): void
    {
        // When Python marks a job 'uploaded', auto-queue it for human approval
        // and run Thumbnail Lab to pick the best thumbnail variant.
        if ($job->wasChanged('status') && $job->status === 'uploaded') {
            $job->updateQuietly(['approval_status' => 'pending_review']);

            // Thumbnail Lab runs in background (dispatched after response)
            app()->terminating(function () use ($job) {
                try {
                    $this->thumbnailLab->generate($job);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        '[ThumbnailLab] Failed for job ' . $job->id . ': ' . $e->getMessage()
                    );
                }
            });
        }
    }
}
