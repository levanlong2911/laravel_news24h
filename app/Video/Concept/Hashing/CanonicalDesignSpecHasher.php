<?php

declare(strict_types=1);

namespace App\Video\Concept\Hashing;

use InvalidArgumentException;

final class CanonicalDesignSpecHasher
{
    public const ALGORITHM = 'sha256';

    public function hash(
        string $canonicalJson
    ): string {
        if ($canonicalJson === '') {
            throw new InvalidArgumentException(
                'Canonical JSON must not be empty.'
            );
        }

        return hash(
            self::ALGORITHM,
            $canonicalJson
        );
    }
}
