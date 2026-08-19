<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum VideoPlanningStageStatus: string
{
    use EnumTrait;

    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
