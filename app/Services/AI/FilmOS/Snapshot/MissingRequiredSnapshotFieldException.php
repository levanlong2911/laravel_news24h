<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Thrown by SnapshotComposer when a SnapshotSection's fields() output is missing
 * one or more keys declared in its requiredFields().
 *
 * This is a developer error: the section's constructor accepted the value but
 * fields() forgot to include the corresponding key in its return array.
 * Fail loud at compose time — never silently produce a gap.
 */
final class MissingRequiredSnapshotFieldException extends \LogicException
{
    /**
     * @param string   $sectionName  value of SnapshotSection::name()
     * @param string[] $missing      field keys absent from fields() output
     */
    public function __construct(string $sectionName, array $missing)
    {
        $quoted = implode(', ', array_map(fn($f) => "'{$f}'", $missing));
        $noun   = count($missing) === 1 ? 'field' : 'fields';

        parent::__construct(
            "Section '{$sectionName}' declares required {$noun} {$quoted} but did not return " .
            (count($missing) === 1 ? "it" : "them") . " from fields(). " .
            "Add the missing key(s) to the fields() return array."
        );
    }
}
