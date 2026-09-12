<?php

declare(strict_types=1);

namespace App\Video\Render\StateMachine;

use App\Video\Render\Enums\RenderStatus;
use RuntimeException;

final class InvalidRenderStateTransition extends RuntimeException
{
    public static function between(RenderStatus $from, RenderStatus $to): self
    {
        return new self(sprintf('Invalid render transition %s -> %s.', $from->value, $to->value));
    }
}

