<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Thrown by CanonicalArray::deepSort() when the recursion depth exceeds the
 * maximum allowed depth, indicating a circular reference or an abnormally
 * deep data structure passed to the canonical hash pipeline.
 *
 * Canonical data should be shallow DTOs. If this exception fires it almost
 * always means either:
 *   - A PHP array reference cycle was introduced (e.g. $arr['x'] =& $arr)
 *   - A runtime object graph was passed instead of a canonical DTO array
 *
 * Both are developer errors that must fail loudly — never silently truncate.
 */
final class CircularCanonicalizationException extends \LogicException
{
    public function __construct(int $depth, int $maxDepth)
    {
        parent::__construct(
            "CanonicalArray::deepSort() exceeded maximum recursion depth of {$maxDepth} " .
            "(reached depth {$depth}). Canonical hash data must be acyclic and shallow. " .
            "Check for circular array references or runtime object graphs passed to the hash pipeline."
        );
    }
}
