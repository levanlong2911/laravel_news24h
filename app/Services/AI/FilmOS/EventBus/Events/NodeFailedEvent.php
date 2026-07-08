<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\EventBus\Events;

use App\Services\AI\FilmOS\EventBus\AbstractFilmOSEvent;

final class NodeFailedEvent extends AbstractFilmOSEvent
{
    public function __construct(
        public readonly string  $executionId,
        public readonly string  $nodeId,
        public readonly string  $taskId,
        public readonly string  $errorMessage,
        public readonly int     $retryCount,
        public readonly ?string $provider = null,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'execution.node.failed';
    }

    public function payload(): array
    {
        return [
            'executionId'  => $this->executionId,
            'nodeId'       => $this->nodeId,
            'taskId'       => $this->taskId,
            'errorMessage' => $this->errorMessage,
            'retryCount'   => $this->retryCount,
            'provider'     => $this->provider,
        ];
    }
}
