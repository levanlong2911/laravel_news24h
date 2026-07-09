<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Immutable snapshot of the full execution state at production completion.
 *
 * Purpose: determinism verification and regression detection.
 * Two runs with identical input MUST produce identical canonicalHash().
 *
 * Fields are added incrementally as subsystems are wired:
 *   Phase A (now):  dagHash, goalGraphHash, promptHash, schedulerHash, policyHash
 *   Phase B:        executionGraphHash, checkpointHash, retrySequenceHash
 *   Phase C:        capabilityHash, providerRouteHash
 *   Phase D:        eventBusHash
 *
 * Null fields are tracked via gaps() so reports show what is and isn't yet verified.
 */
final class ExecutionSnapshot
{
    /**
     * Increment when the set of fields in canonicalHash() changes.
     * This forces a deterministic mismatch on schema drift — never silent failure.
     */
    public const SCHEMA_VERSION = 1;

    public function __construct(
        // Versioned manifest — covers schema, compiler, backend, grammar, world, policy
        public readonly DeterminismManifest $manifest,

        // Identity
        public readonly string  $executionId,
        public readonly string  $productionId,
        public readonly float   $capturedAt,

        // Phase A — Planning layer
        public readonly string  $dagHash,
        public readonly string  $goalGraphHash,
        public readonly string  $promptHash,
        public readonly ?string $schedulerHash,

        // Phase B — Execution layer
        public readonly ?string $executionGraphHash,
        public readonly ?string $checkpointHash,
        public readonly ?string $retrySequenceHash,

        // Phase C — Provider + Capability layer
        public readonly ?string $capabilityHash,
        public readonly ?string $providerRouteHash,

        // Phase A (policy) / Phase D (event)
        public readonly ?string $policyHash,
        public readonly ?string $eventBusHash,
    ) {}

    /**
     * Canonical hash of all fields that MUST be identical for determinism.
     *
     * Excludes: capturedAt, executionId — run-specific metadata.
     * Null fields are included as the literal string "null" so their
     * absence is still part of the comparison (prevents false positives
     * when Phase B fields are added to one run but not another).
     */
    public function canonicalHash(): string
    {
        return hash('sha256', json_encode([
            'manifest'           => $this->manifest->canonicalHash(),
            'dagHash'            => $this->dagHash,
            'goalGraphHash'      => $this->goalGraphHash,
            'promptHash'         => $this->promptHash,
            'schedulerHash'      => $this->schedulerHash      ?? 'null',
            'executionGraphHash' => $this->executionGraphHash ?? 'null',
            'checkpointHash'     => $this->checkpointHash     ?? 'null',
            'retrySequenceHash'  => $this->retrySequenceHash  ?? 'null',
            'capabilityHash'     => $this->capabilityHash     ?? 'null',
            'providerRouteHash'  => $this->providerRouteHash  ?? 'null',
            'policyHash'         => $this->policyHash         ?? 'null',
            'eventBusHash'       => $this->eventBusHash       ?? 'null',
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Fields still null — each represents a gap in determinism coverage.
     * @return string[]
     */
    public function gaps(): array
    {
        $gaps = [];
        if ($this->schedulerHash === null)      $gaps[] = 'schedulerHash (Phase A)';
        if ($this->executionGraphHash === null) $gaps[] = 'executionGraphHash (Phase B)';
        if ($this->checkpointHash === null)     $gaps[] = 'checkpointHash (Phase B)';
        if ($this->retrySequenceHash === null)  $gaps[] = 'retrySequenceHash (Phase B)';
        if ($this->capabilityHash === null)     $gaps[] = 'capabilityHash (Phase C)';
        if ($this->providerRouteHash === null)  $gaps[] = 'providerRouteHash (Phase C)';
        if ($this->policyHash === null)         $gaps[] = 'policyHash (Phase A / Policy wired)';
        if ($this->eventBusHash === null)       $gaps[] = 'eventBusHash (Phase D)';
        return $gaps;
    }

    /**
     * Field-level diff of two snapshots.
     * @return array<string, array{original: string|null, replay: string|null}>
     */
    public function diffWith(self $other): array
    {
        $fields = [
            'dagHash'            => [$this->dagHash,            $other->dagHash],
            'goalGraphHash'      => [$this->goalGraphHash,      $other->goalGraphHash],
            'promptHash'         => [$this->promptHash,         $other->promptHash],
            'schedulerHash'      => [$this->schedulerHash,      $other->schedulerHash],
            'executionGraphHash' => [$this->executionGraphHash, $other->executionGraphHash],
            'checkpointHash'     => [$this->checkpointHash,     $other->checkpointHash],
            'retrySequenceHash'  => [$this->retrySequenceHash,  $other->retrySequenceHash],
            'capabilityHash'     => [$this->capabilityHash,     $other->capabilityHash],
            'providerRouteHash'  => [$this->providerRouteHash,  $other->providerRouteHash],
            'policyHash'         => [$this->policyHash,         $other->policyHash],
            'eventBusHash'       => [$this->eventBusHash,       $other->eventBusHash],
        ];

        $diffs = [];
        foreach ($fields as $field => [$a, $b]) {
            if ($a !== $b) {
                $diffs[$field] = ['original' => $a, 'replay' => $b];
            }
        }
        return $diffs;
    }

    public function shortHash(): string
    {
        return substr($this->canonicalHash(), 0, 12);
    }
}
