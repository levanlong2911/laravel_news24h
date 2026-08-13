<?php

namespace App\Video\Director;

use InvalidArgumentException;
use RuntimeException;

final class DirectorSelectionFailed extends RuntimeException
{
    public const REASON_NO_VALID_INDEX_AFTER_RETRY = 'NO_VALID_INDEX_AFTER_RETRY';

    private function __construct(
        public readonly int $sceneOrdinal,
        public readonly string $reason,
        public readonly int $attempts,
    ) {
        parent::__construct(sprintf(
            'Director selection failed for scene ordinal %d after %d attempts',
            $sceneOrdinal,
            $attempts,
        ));
    }

    public static function afterRetry(int $sceneOrdinal, int $attempts): self
    {
        if ($sceneOrdinal < 1) {
            throw new InvalidArgumentException('sceneOrdinal must be at least 1');
        }

        if ($attempts < 2) {
            throw new InvalidArgumentException('attempts must be at least 2');
        }

        return new self($sceneOrdinal, self::REASON_NO_VALID_INDEX_AFTER_RETRY, $attempts);
    }
}
