<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Thrown by SnapshotComposer when a SnapshotSection's fields() output contains
 * keys not declared in either requiredFields() or optionalFields().
 *
 * Every field a section exposes must be explicitly declared in the contract.
 * An undeclared field is a developer error — it means someone added a new field
 * to fields() without updating the section's declared contract, which makes
 * the section's behavior impossible to reason about or test.
 *
 * Fix: add the undeclared key(s) to optionalFields() (or requiredFields() if
 * the field should always be present).
 */
final class UndeclaredSnapshotFieldException extends \LogicException
{
    /**
     * @param string   $sectionName  value of SnapshotSection::name()
     * @param string[] $undeclared   field keys present in fields() but absent from both contracts
     */
    public function __construct(string $sectionName, array $undeclared)
    {
        $quoted = implode(', ', array_map(fn($f) => "'{$f}'", $undeclared));
        $noun   = count($undeclared) === 1 ? 'field' : 'fields';

        parent::__construct(
            "Section '{$sectionName}' returned undeclared {$noun} {$quoted}. " .
            "Every field returned from fields() must appear in requiredFields() or optionalFields(). " .
            "Add the undeclared key(s) to optionalFields() (or requiredFields() if always present)."
        );
    }
}
