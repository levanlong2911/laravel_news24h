<?php

namespace App\Repositories\Eloquent;

use App\Enums\VideoShotStatus;
use App\Models\VideoShot;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

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

    public function claimForSession(
        string $sessionId,
        string $workerId,
        string $claimToken,
        int $limit,
        DateTimeInterface $leaseExpiresAt,
    ): iterable {
        // MariaDB 10.4 has no SKIP LOCKED. A single UPDATE ... LIMIT is atomic:
        // concurrent workers cannot both match the same row once its status changes.
        $limit = max(1, min($limit, 100));
        $now = now();

        DB::update(
            'UPDATE video_shots
             SET status = ?, worker_id = ?, claim_token = ?, claimed_at = ?,
                 lease_expires_at = ?, updated_at = ?
             WHERE status = ? AND session_id = ?
             ORDER BY created_at, id
             LIMIT '.$limit,
            [
                VideoShotStatus::CLAIMED->value,
                $workerId,
                $claimToken,
                $now,
                $leaseExpiresAt,
                $now,
                VideoShotStatus::QUEUED->value,
                $sessionId,
            ],
        );

        return VideoShot::query()
            ->where('session_id', $sessionId)
            ->where('claim_token', $claimToken)
            ->with('session:id,code')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function heartbeatClaim(
        string $shotId,
        string $workerId,
        string $claimToken,
        DateTimeInterface $leaseExpiresAt,
    ): bool {
        return VideoShot::query()
            ->whereKey($shotId)
            ->where('worker_id', $workerId)
            ->where('claim_token', $claimToken)
            ->whereIn('status', [
                VideoShotStatus::CLAIMED->value,
                VideoShotStatus::RENDERING->value,
            ])
            ->where('lease_expires_at', '>', now())
            ->update([
                'status' => VideoShotStatus::RENDERING->value,
                'lease_expires_at' => $leaseExpiresAt,
            ]) === 1;
    }

    public function reclaimExpiredLeases(): int
    {
        return VideoShot::query()
            ->whereIn('status', [
                VideoShotStatus::CLAIMED->value,
                VideoShotStatus::RENDERING->value,
            ])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->update([
                'status' => VideoShotStatus::QUEUED->value,
                'worker_id' => null,
                'claim_token' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ]);
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
