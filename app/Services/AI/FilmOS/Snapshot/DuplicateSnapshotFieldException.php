<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Thrown when two SnapshotSection objects in the same composition declare the same field key.
 *
 * Each field key must be globally unique across all sections in one compose() call.
 * A collision indicates a developer error (two phases claiming the same hash slot)
 * and must fail immediately — silent overwrite would produce wrong canonicalHash().
 */
final class DuplicateSnapshotFieldException extends \LogicException
{
    /**
     * @param string $field         the duplicate key
     * @param string $firstSection  SnapshotSection::name() of the first section that claimed it
     * @param string $secondSection SnapshotSection::name() of the second section that claimed it
     */
    public function __construct(string $field, string $firstSection, string $secondSection)
    {
        parent::__construct(
            "Duplicate snapshot field '{$field}' declared by both '{$firstSection}' and '{$secondSection}'. " .
            "Each field key must be unique across all sections in a composition. " .
            "Assign distinct field names or merge the conflicting sections."
        );
    }
}
