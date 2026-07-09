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
 * Field contract:
 *   Keys must exactly match ExecutionSnapshot constructor parameter names.
 *   Null value = field not yet captured (shows as gap in report).
 */
interface SnapshotSection
{
    /**
     * @return array<string, string|null>  field name → hash value
     */
    public function fields(): array;
}
