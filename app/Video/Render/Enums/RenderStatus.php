<?php

declare(strict_types=1);

namespace App\Video\Render\Enums;

enum RenderStatus: string
{
    case QUEUED = 'queued';
    case CLAIMED = 'claimed';
    case PREPARING = 'preparing';
    case SUBMITTING = 'submitting';
    case SUBMITTED = 'submitted';
    case PROVIDER_UNKNOWN = 'provider_unknown';
    case PROVIDER_RUNNING = 'provider_running';
    case POLLING = 'polling';
    case ARTIFACT_WRITING = 'artifact_writing';
    case CHECKPOINTING = 'checkpointing';
    case SUCCEEDED = 'succeeded';
    case RETRY_WAIT = 'retry_wait';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::SUCCEEDED,
            self::FAILED,
            self::CANCELLED => true,
            default => false,
        };
    }

    public function canBeClaimed(): bool
    {
        return match ($this) {
            self::QUEUED,
            self::RETRY_WAIT => true,
            default => false,
        };
    }
}
