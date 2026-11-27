<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoSession;

interface VideoSessionRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  string  $projectId
     * @return ?VideoSession the oldest session of the project
     */
    public function findFirstSessionForProject(string $projectId): ?VideoSession;

    /**
     * @param  array<string, mixed>  $attributes  fillable attributes
     * @return VideoSession the new session with its generated id
     */
    public function createSession(array $attributes): VideoSession;

    /**
     * @param  ?string  $sessionId  null still runs the lookup (id is null)
     * @return ?string project_id of the session as stored
     */
    public function findProjectIdOfSession(?string $sessionId): ?string;
}
