<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\EventBus\Events;

use App\Services\AI\FilmOS\EventBus\AbstractFilmOSEvent;

final class ExecutionFinishedEvent extends AbstractFilmOSEvent
{
    public function __construct(
        public readonly string $executionId,
        public readonly bool   $fullyCompleted,
        public readonly int    $completedCount,
        public readonly int    $failedCount,
        public readonly int    $skippedCount,
        public readonly float  $totalElapsedMs,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'execution.finished';
    }

    public function payload(): array
    {
        return [
            'executionId'    => $this->executionId,
            'fullyCompleted' => $this->fullyCompleted,
            'completedCount' => $this->completedCount,
            'failedCount'    => $this->failedCount,
            'skippedCount'   => $this->skippedCount,
            'totalElapsedMs' => $this->totalElapsedMs,
        ];
    }

    public function canonicalData(): array
    {
        return [
            'fullyCompleted' => $this->fullyCompleted,
            'completedCount' => $this->completedCount,
            'failedCount'    => $this->failedCount,
            'skippedCount'   => $this->skippedCount,
            // excluded: executionId (run-instance), totalElapsedMs (timing)
        ];
    }
}
