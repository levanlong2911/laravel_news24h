<?php

namespace App\Services\AI\Provider\Kling;

enum KlingVideoStatus: string
{
    case PENDING    = 'submitted';
    case PROCESSING = 'processing';
    case COMPLETED  = 'succeed';
    case FAILED     = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED;
    }

    public function isSuccess(): bool
    {
        return $this === self::COMPLETED;
    }
}
