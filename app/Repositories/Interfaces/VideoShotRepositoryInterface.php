<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoShot;

interface VideoShotRepositoryInterface extends RepositoryInterface
{
    public function approveByIds(string $sessionId, array $shotIds): int;

    public function queueApprovedForSession(string $sessionId): int;

    public function findQueuedWithSession(): iterable;

    /** MỌI shot của session, không lọc trạng thái — CHỈ cho lượt thử render. */
    public function findAllOfSessionWithSession(string $sessionId): iterable;

    public function updateOrCreateShot(array $match, array $attributes): VideoShot;
}
