<?php

namespace App\Video\Superyacht\Validation\Invariants;

use App\Video\Superyacht\Validation\ValidationReport;

interface InvariantSet
{
    /** Schema name this set applies to (e.g. "ShotSpec"). */
    public function schema(): string;

    /** @param array $doc decoded document (associative arrays) */
    public function check(array $doc, InvariantContext $ctx): ValidationReport;
}
