<?php

namespace App\Video\Inspiration;

use RuntimeException;

final class InvalidInspirationBrief extends RuntimeException
{
    /** @param list<string> $violations */
    public function __construct(
        public readonly array $violations,
        public readonly string $rawResponse = '',
    ) {
        parent::__construct('Inspiration brief failed validation: '.implode('; ', $violations));
    }
}
