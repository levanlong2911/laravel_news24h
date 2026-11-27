<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoSessionEvent;

interface VideoSessionEventRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  string  $projectId
     * @param  string  $operationId
     * @return ?VideoSessionEvent the event recorded under that operation in a session of the project
     */
    public function findOperationForProject(string $projectId, string $operationId): ?VideoSessionEvent;

    /**
     * @param  string  $sessionId
     * @param  string  $operationId
     * @return ?VideoSessionEvent the event recorded under that operation in the session
     */
    public function findOperationForSession(string $sessionId, string $operationId): ?VideoSessionEvent;

    /**
     * @param  array<string, mixed>  $attributes  fillable attributes; events are only ever added, never changed or removed
     * @return VideoSessionEvent the new event with its generated id
     */
    public function createEvent(array $attributes): VideoSessionEvent;
}
