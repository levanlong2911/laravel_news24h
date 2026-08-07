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

    /**
     * MỌI shot của một session, không lọc trạng thái — dùng cho lượt THỬ RENDER.
     *
     * Cố ý khác `findQueuedWithSession()`: thứ cần soi trước khi duyệt lại đang ở
     * `draft`, nên lọc theo `queued` là lượt thử không thấy gì. Hàm này KHÔNG
     * được dùng cho đường render thật — ở đó chỉ shot đã duyệt mới được tiêu tiền.
     */
    public function findAllOfSessionWithSession(string $sessionId): iterable
    {
        return VideoShot::where('session_id', $sessionId)
            ->with('session:id,code')
            ->orderBy('kind')
            ->orderBy('shot_code')
            ->get();
    }

    public function updateOrCreateShot(array $match, array $attributes): VideoShot
    {
        return VideoShot::updateOrCreate($match, $attributes);
    }
}
