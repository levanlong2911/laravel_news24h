<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoSession;
use App\Models\VideoSessionEvent;
use App\Repositories\Interfaces\VideoSessionEventRepositoryInterface;

class VideoSessionEventRepository extends BaseRepository implements VideoSessionEventRepositoryInterface
{
    use AppendOnlyRepository;

    public function getModel(): string
    {
        return VideoSessionEvent::class;
    }

    public function findOperationForProject(string $projectId, string $operationId): ?VideoSessionEvent
    {
        return $this->model->newQuery()
            ->whereIn('session_id', VideoSession::query()->where('project_id', $projectId)->select('id'))
            ->where('operation_id', $operationId)
            ->first();
    }

    public function findOperationForSession(string $sessionId, string $operationId): ?VideoSessionEvent
    {
        return $this->model->newQuery()
            ->where('session_id', $sessionId)
            ->where('operation_id', $operationId)
            ->first();
    }

    public function createEvent(array $attributes): VideoSessionEvent
    {
        return $this->model->newQuery()->create($attributes);
    }
}
