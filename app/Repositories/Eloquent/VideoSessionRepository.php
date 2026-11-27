<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoSession;
use App\Repositories\Interfaces\VideoSessionRepositoryInterface;

class VideoSessionRepository extends BaseRepository implements VideoSessionRepositoryInterface
{
    public function getModel(): string
    {
        return VideoSession::class;
    }

    public function findFirstSessionForProject(string $projectId): ?VideoSession
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->orderBy('created_at')
            ->first();
    }

    public function createSession(array $attributes): VideoSession
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function findProjectIdOfSession(?string $sessionId): ?string
    {
        return $this->model->newQuery()->whereKey($sessionId)->value('project_id');
    }
}
