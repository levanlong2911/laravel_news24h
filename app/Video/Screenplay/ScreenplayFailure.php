<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

use RuntimeException;

final class ScreenplayFailure extends RuntimeException
{
    public const TRUNCATED = 'truncated';

    public const REFUSED = 'refused';

    public const INVALID_JSON = 'invalid_json';

    /** @param array<string, mixed> $usage */
    public function __construct(
        string $message,
        public readonly string $rawResponse = '',
        public readonly array $usage = [],
        public readonly string $kind = self::INVALID_JSON,
    ) {
        parent::__construct($message);
    }
}
