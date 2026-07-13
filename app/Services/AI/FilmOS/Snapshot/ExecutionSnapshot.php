<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Immutable snapshot of the full execution state at production completion.
 *
 * Purpose: determinism verification and regression detection.
 * Two runs with identical input MUST produce identical canonicalHash().
 *
 * Phase D: sections-based plugin architecture.
 * Hash fields are NO LONGER direct constructor parameters.
 * Each phase contributes a SnapshotSection; this class merges them.
 *
 * Adding Phase E (or any future phase) requires:
 *   1. A new XxxSection implements SnapshotSection
 *   2. A new XxxBuilder that returns it
 *   3. Pass the section to SnapshotComposer::compose() at the call site
 *   ExecutionSnapshot itself does NOT change.
 *
 * Phase history:
 *   Phase A (PlanningSection):   dagHash, goalGraphHash, promptHash, schedulerHash, policyHash
 *   Phase B (ExecutionSection):  executionTopologyHash, checkpointHash, retrySequenceHash
 *   Phase C (ProviderSection):   capabilityHash, providerRouteHash
 *   Phase E (EventSection):      eventBusHash — (eventName, ordinal, canonicalData) in emission order
 */
final class ExecutionSnapshot
{
    /**
     * Increment when the canonical field set changes between schema versions.
     * Forces a deterministic mismatch on schema drift — never silent failure.
     * Bumped to 2 in Phase D (sections-based migration).
     */
    public const SCHEMA_VERSION = 2;

    /**
     * @param  SnapshotSection[]  $sections  one per phase; order determines merge priority
     */
    public function __construct(
        public readonly DeterminismManifest $manifest,
        public readonly string  $executionId,
        public readonly string  $productionId,
        public readonly float   $capturedAt,
        public readonly array   $sections,
        private readonly HashSerializer $serializer = new JsonHashSerializer(),
    ) {}

    /**
     * Canonical hash of all section fields.
     *
     * Includes schemaVersion so a schema change forces a mismatch even when field
     * values happen to be identical — prevents silent false-positives on drift.
     * Excludes: capturedAt, executionId — run-specific metadata.
     * Null fields are included as the literal string "null" so their
     * absence is still part of the comparison.
     * Fields are sorted by key for determinism regardless of section order.
     */
    public function canonicalHash(): string
    {
        if ($this->hashCache !== null) {
            return $this->hashCache;
        }

        $data = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'manifest'      => $this->manifest->canonicalHash(),
        ];

        foreach ($this->fieldIndex() as $key => $value) {
            $data[$key] = $value ?? 'null';
        }

        ksort($data);
        return $this->hashCache = $this->serializer->sha256($data);
    }

    /**
     * Fields that are null across all sections — each is a gap in determinism coverage.
     * @return string[]
     */
    public function gaps(): array
    {
        $gaps = [];
        foreach ($this->fieldIndex() as $key => $value) {
            if ($value === null) {
                $gaps[] = $key;
            }
        }
        return $gaps;
    }

    /**
     * Field-level diff of two snapshots.
     * Merges all section fields from both snapshots and compares key by key.
     * @return array<string, array{original: string|null, replay: string|null}>
     */
    public function diffWith(self $other): array
    {
        $mine   = $this->fieldIndex();
        $theirs = $other->fieldIndex();

        $allKeys = array_unique(array_merge(array_keys($mine), array_keys($theirs)));
        sort($allKeys);

        $diffs = [];
        foreach ($allKeys as $key) {
            $a = $mine[$key]   ?? null;
            $b = $theirs[$key] ?? null;
            if ($a !== $b) {
                $diffs[$key] = ['original' => $a, 'replay' => $b];
            }
        }
        return $diffs;
    }

    /**
     * Get a field value by name. O(1) — served from the cached field index.
     * Returns null if the field is absent in every section or its value is null.
     */
    public function get(string $field): ?string
    {
        return $this->fieldIndex()[$field] ?? null;
    }

    /**
     * All field names across all sections, sorted alphabetically.
     * Avoids hardcoding field lists in callers — new sections appear automatically.
     * Sort is stable so output is identical regardless of section composition order.
     * @return string[]
     */
    public function allFields(): array
    {
        $keys = array_keys($this->fieldIndex());
        sort($keys);
        return $keys;
    }

    public function shortHash(): string
    {
        return substr($this->canonicalHash(), 0, 12);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Lazy-cached canonical hash — computed once, reused on every subsequent call.
     * Safe because ExecutionSnapshot is immutable: hash never changes post-construction.
     */
    private ?string $hashCache = null;

    /**
     * Lazy-cached flat field index: fieldName → value.
     * Built once on first access, reused for get(), gaps(), diffWith(), canonicalHash().
     *
     * Throws DuplicateSnapshotFieldException if two sections declare the same key —
     * a plugin developer error that must fail loudly, not silently overwrite.
     *
     * @return array<string, string|null>
     */
    private ?array $fieldCache = null;

    private function fieldIndex(): array
    {
        if ($this->fieldCache !== null) {
            return $this->fieldCache;
        }

        $index   = [];
        $sources = [];

        foreach ($this->sections as $section) {
            foreach ($section->fields() as $key => $value) {
                if (!is_string($key) || trim($key) === '') {
                    throw new \LogicException(
                        "Section '{$section::name()}' returned an invalid field key. " .
                        "Keys must be non-empty strings with no leading/trailing whitespace."
                    );
                }
                if (array_key_exists($key, $index)) {
                    throw new DuplicateSnapshotFieldException($key, $sources[$key], $section::name());
                }
                $index[$key]   = $value;
                $sources[$key] = $section::name();
            }
        }

        return $this->fieldCache = $index;
    }
}
