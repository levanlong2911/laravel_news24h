<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoShot;
use DateTimeInterface;

interface VideoShotRepositoryInterface extends RepositoryInterface
{
    public function approveByIds(string $sessionId, array $shotIds): int;

    public function queueApprovedForSession(string $sessionId): int;

    public function findQueuedWithSession(): iterable;

    /**
     * Atomically claim up to `$limit` queued shots from one session.
     *
     * @return iterable<int, VideoShot>
     */
    public function claimForSession(
        string $sessionId,
        string $workerId,
        string $claimToken,
        int $limit,
        DateTimeInterface $leaseExpiresAt,
    ): iterable;

    public function heartbeatClaim(
        string $shotId,
        string $workerId,
        string $claimToken,
        DateTimeInterface $leaseExpiresAt,
    ): bool;

    public function reclaimExpiredLeases(): int;

    /** MỌI shot của session, không lọc trạng thái — CHỈ cho lượt thử render. */
    public function findAllOfSessionWithSession(string $sessionId): iterable;

    public function updateOrCreateShot(array $match, array $attributes): VideoShot;
}
