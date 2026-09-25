<?php

namespace App\Video\Inspiration;

use RuntimeException;

final class InvalidInspirationBrief extends RuntimeException
{
    /**
     * @param  list<string>  $violations
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public readonly array $violations,
        public readonly string $rawResponse = '',
        public readonly array $usage = [],
    ) {
        parent::__construct('Inspiration brief failed validation: '.implode('; ', $violations));
    }
}
