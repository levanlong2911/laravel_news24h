<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\EventBus\Events;

use App\Services\AI\FilmOS\EventBus\AbstractFilmOSEvent;

final class NodeCompletedEvent extends AbstractFilmOSEvent
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $nodeId,
        public readonly string $taskId,
        public readonly float  $elapsedMs,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'execution.node.completed';
    }

    public function payload(): array
    {
        return [
            'executionId' => $this->executionId,
            'nodeId'      => $this->nodeId,
            'taskId'      => $this->taskId,
            'elapsedMs'   => $this->elapsedMs,
        ];
    }
}
