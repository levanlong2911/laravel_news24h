<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\EventBus;

abstract class AbstractFilmOSEvent implements FilmOSEvent
{
    private readonly float $occurredAt;

    public function __construct()
    {
        $this->occurredAt = microtime(true);
    }

    public function occurredAt(): float
    {
        return $this->occurredAt;
    }
}
