<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * One phase's contribution to an ExecutionSnapshot.
 *
 * Each phase builder produces a SnapshotSection. SnapshotComposer iterates
 * all sections and merges their fields — no phase knows about another's fields.
 *
 * Adding Phase E (or any future phase) requires:
 *   1. A new XxxSection implements SnapshotSection
 *   2. A new XxxBuilder that returns it
 *   3. Register the builder in SnapshotComposer
 *   SnapshotComposer itself does not change.
 *
 * Implementer contract:
 *   name()           — stable, lowercase identifier (e.g. "planning", "execution").
 *                      Used in duplicate-field and missing-field error messages.
 *   requiredFields() — keys that MUST appear in fields() output (value may be null).
 *                      SnapshotComposer verifies this before building the snapshot.
 *   fields()         — the actual key → hash-value map; keys must be non-empty
 *                      strings with no leading/trailing whitespace; globally unique
 *                      across all sections in one composition.
 *                      Null value = field not yet captured (shows as gap in report).
 */
interface SnapshotSection
{
    /** Stable lowercase identifier for this section — used in error messages. */
    public static function name(): string;

    /**
     * Field keys that MUST appear in the fields() return array.
     * SnapshotComposer throws MissingRequiredSnapshotFieldException if any are absent.
     *
     * @return string[]
     */
    public static function requiredFields(): array;

    /**
     * Field keys that MAY appear in the fields() return array (value may be null).
     * SnapshotComposer throws UndeclaredSnapshotFieldException if fields() returns
     * a key that is absent from BOTH requiredFields() and optionalFields().
     *
     * Use this to explicitly declare fields a section adds conditionally or
     * whose value may be null. Every key in fields() must be declared here or
     * in requiredFields() — no silent undeclared fields allowed.
     *
     * @return string[]
     */
    public static function optionalFields(): array;

    /**
     * @return array<non-empty-string, string|null>  field name → hash value (null = gap)
     */
    public function fields(): array;
}
