<?php

namespace App\Video\Story;

use RuntimeException;

final class InvalidCreativeArc extends RuntimeException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Creative arc is invalid: '.implode('; ', $violations));
    }
}
