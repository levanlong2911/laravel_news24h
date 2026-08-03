<?php

namespace App\Repositories\Eloquent;

use App\Enums\VideoShotStatus;
use App\Models\VideoShot;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;

class VideoShotRepository extends BaseRepository implements VideoShotRepositoryInterface
{
    public function getModel(): string
    {
        return VideoShot::class;
    }

    public function approveByIds(string $sessionId, array $shotIds): int
    {
        return VideoShot::where('session_id', $sessionId)
            ->whereIn('id', $shotIds)
            ->update(['status' => VideoShotStatus::APPROVED->value, 'approved_at' => now(), 'review_note' => null]);
    }

    // 🎬 Render — CHỈ shot approved mới vào queue
    public function queueApprovedForSession(string $sessionId): int
    {
        return VideoShot::where('session_id', $sessionId)
            ->where('status', VideoShotStatus::APPROVED->value)
            ->update(['status' => VideoShotStatus::QUEUED->value]);
    }

    public function findQueuedWithSession(): iterable
    {
        return VideoShot::where('status', VideoShotStatus::QUEUED->value)->with('session:id,code')->get();
    }

    public function updateOrCreateShot(array $match, array $attributes): VideoShot
    {
        return VideoShot::updateOrCreate($match, $attributes);
    }
}
