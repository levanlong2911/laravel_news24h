<?php

namespace App\Services\AI\Provider;

enum RenderVideoStatus: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case STORING    = 'storing';    // Provider done; downloading artifact to internal storage.
    case COMPLETED  = 'completed';
    case FAILED     = 'failed';
    case CANCELLED  = 'cancelled';
    case TIMEOUT    = 'timeout';
    case UNKNOWN    = 'unknown';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED, self::TIMEOUT => true,
            default => false,
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::COMPLETED;
    }
}
