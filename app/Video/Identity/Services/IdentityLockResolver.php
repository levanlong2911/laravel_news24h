<?php

declare(strict_types=1);

namespace App\Video\Identity\Services;

use App\Video\Identity\Enums\IdentityLockStatus;
use App\Video\Identity\Models\IdentityLock;

final class IdentityLockResolver
{
    public function frozenForProject(string $projectId): ?IdentityLock
    {
        return IdentityLock::query()
            ->where('video_project_id', $projectId)
            ->where('status', IdentityLockStatus::FROZEN)
            ->latest('identity_lock_version')
            ->first();
    }
}
