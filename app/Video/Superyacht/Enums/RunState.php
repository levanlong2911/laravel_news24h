<?php

namespace App\Video\Superyacht\Enums;

enum RunState: string
{
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case COMPLETED_WITH_FLAGS = 'COMPLETED_WITH_FLAGS';
    case AWAITING_REVIEW = 'AWAITING_REVIEW';
    case FAILED = 'FAILED';

    public function allowsPublish(): bool
    {
        return in_array($this, [self::COMPLETED, self::COMPLETED_WITH_FLAGS], true);
    }
}
