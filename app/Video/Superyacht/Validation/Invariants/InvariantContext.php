<?php

namespace App\Video\Superyacht\Validation\Invariants;

/**
 * Everything the cross-field invariants need to know about the rest of the
 * project. Two implementations ship with the starter:
 *  - ArrayInvariantContext: plain arrays, used by unit tests and the CLI;
 *  - DatabaseInvariantContext: reads the sy_* tables for one project.
 */
interface InvariantContext
{
    /** Locked CanonicalDesign document (decoded, associative), or null if none is locked yet. */
    public function lockedCanonical(): ?array;

    /** Latest approved ShotSpec for a shot id, or null. */
    public function shotSpec(string $shotId): ?array;

    /**
     * True when artifact "$type" revision $revision exists and is APPROVED, FROZEN or EXECUTED.
     * $key narrows per-shot artifacts (e.g. type shot_spec, key SC07_SH02).
     */
    public function isLockedRevision(string $type, int $revision, ?string $key = null): bool;

    /** Locked cut grid (cut times in seconds), or null when timing is not locked yet. */
    public function cutGrid(): ?array;

    /** Constraint registry keyed by constraint id. @return array<string,array> */
    public function constraints(): array;

    /** Hash of the CompiledShot revision if it is FROZEN, otherwise null. */
    public function frozenCompiledShotHash(string $shotId, int $revision): ?string;

    /** "sha256:…" of the stored file at $path, or null if the file is unknown. */
    public function storedFileHash(string $path): ?string;

    /**
     * Tool profile for a model id, or null.
     * Keys: provider, verified (bool), durations_sec (list|null), aspect_ratios (list), resolutions (list), kinds (list)
     */
    public function toolProfile(string $modelId): ?array;

    /** True when the artifact revision exists with exactly this hash. */
    public function artifactRevisionExists(string $artifactId, int $revision, string $hash): bool;

    /** True when $decidedBy is a real human user (not "system", not a worker). */
    public function isHumanUser(string $decidedBy): bool;
}
