<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Captures every versioned component that can affect output determinism.
 *
 * A replay is only guaranteed identical when ALL version fields match.
 * Using a single schemaVersion was insufficient once the grammar compiler,
 * backend dialect, and policy rule set became independently versioned.
 *
 * Fields:
 *   schemaVersion   — ExecutionSnapshot field set (increment = contract change)
 *   compilerVersion — CinematicGrammar → backend IR compiler
 *   backendVersion  — rendering backend dialect (e.g. 'mock-kling-v1', 'kling-api-v2')
 *   grammarVersion  — CinematicGrammar rule set
 *   worldVersion    — sha256 of canonical facts (WorldModel fingerprint)
 *   policyVersion   — PolicyEngine rule set
 */
final class DeterminismManifest
{
    public function __construct(
        public readonly int    $schemaVersion,
        public readonly string $compilerVersion,
        public readonly string $backendVersion,
        public readonly string $grammarVersion,
        public readonly string $worldVersion,
        public readonly string $policyVersion,
        private readonly HashSerializer $serializer = new JsonHashSerializer(),
    ) {}

    /**
     * Convenience factory for Phase A golden scenario runs.
     * worldVersion should be sha256(json_encode($facts)).
     */
    public static function current(string $worldVersion): self
    {
        return new self(
            schemaVersion:   ExecutionSnapshot::SCHEMA_VERSION,
            compilerVersion: '0.1.0-phase-a5',
            backendVersion:  'mock-kling-v1',
            grammarVersion:  '0.1.0',
            worldVersion:    $worldVersion,
            policyVersion:   '0.1.0',
        );
    }

    /**
     * Canonical hash of all version fields.
     * Included in ExecutionSnapshot::canonicalHash() so that a backend or
     * grammar upgrade is visible in the snapshot comparison.
     */
    public function canonicalHash(): string
    {
        return $this->serializer->sha256([
            'schemaVersion'   => $this->schemaVersion,
            'compilerVersion' => $this->compilerVersion,
            'backendVersion'  => $this->backendVersion,
            'grammarVersion'  => $this->grammarVersion,
            'worldVersion'    => $this->worldVersion,
            'policyVersion'   => $this->policyVersion,
        ]);
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'schemaVersion'   => $this->schemaVersion,
            'compilerVersion' => $this->compilerVersion,
            'backendVersion'  => $this->backendVersion,
            'grammarVersion'  => $this->grammarVersion,
            'worldVersion'    => $this->worldVersion,
            'policyVersion'   => $this->policyVersion,
        ];
    }
}
