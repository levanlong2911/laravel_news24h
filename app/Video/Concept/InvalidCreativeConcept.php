<?php

namespace App\Video\Concept;

use RuntimeException;

final class InvalidCreativeConcept extends RuntimeException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Creative concept failed validation: '.implode('; ', $violations));
    }
}
