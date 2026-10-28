<?php

declare(strict_types=1);

namespace App\Video\Llm;

use RuntimeException;

final class AnthropicStreamTimeout extends RuntimeException
{
    public const IDLE = 'idle';

    public const TOTAL = 'total';

    public function __construct(public readonly string $kind, public readonly float $waitedSeconds)
    {
        parent::__construct($kind === self::IDLE
            ? sprintf('No stream bytes for %.1f s.', $waitedSeconds)
            : 'The total time budget ran out while reading the stream.');
    }
}
