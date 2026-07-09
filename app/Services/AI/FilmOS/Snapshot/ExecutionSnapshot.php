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
 *   Phase 1 (now):  dagHash, goalGraphHash, promptHash, schedulerHash
 *   Phase B:        executionGraphHash, checkpointHash, retrySequenceHash
 *   Phase C:        capabilityHash, providerRouteHash
 *   Phase D+:       eventBusHash, policyHash
 *
 * Null fields are explicitly tracked via gaps() so the report
 * shows what is and isn't yet verified.
 */
final class ExecutionSnapshot
{
    /** Increment when canonicalHash() field set changes — forces replay mismatch on schema drift. */
    public const SCHEMA_VERSION = 1;

    public function __construct(
        // Schema version — increment when canonicalHash() field set changes
        public readonly int     $schemaVersion,

        // Identity
        public readonly string  $executionId,
        public readonly string  $productionId,
        public readonly float   $capturedAt,

        // Phase 1 — Planning layer (available now)
        public readonly string  $dagHash,            // DecisionDAG topology
        public readonly string  $goalGraphHash,      // GoalGraph topology
        public readonly string  $promptHash,         // sha256 of all PromptIRs (canonical)
        public readonly ?string $schedulerHash,      // task submission order (null until scheduler captured)

        // Phase B — Execution layer (available after Provider Test Harness)
        public readonly ?string $executionGraphHash, // ExecutionGraph topology + node statuses
        public readonly ?string $checkpointHash,     // checkpoint sequence (ordered node completions)
        public readonly ?string $retrySequenceHash,  // [nodeId → retryCount] ordered

        // Phase C — Provider + Capability layer
        public readonly ?string $capabilityHash,     // CapabilityRegistry snapshot
        public readonly ?string $providerRouteHash,  // which provider handled which task

        // Phase D+ — Policy + Event layer
        public readonly ?string $policyHash,         // PolicyDecision per shot
        public readonly ?string $eventBusHash,       // EventBus event sequence
    ) {}

    /**
     * Canonical hash of all fields that MUST be identical for determinism.
     *
     * Excludes: capturedAt, executionId (these are run-specific metadata).
     * Null fields are included as the literal string "null" so their
     * absence is still part of the comparison (prevents false positives
     * when Phase B fields are added to one run but not another).
     */
    public function canonicalHash(): string
    {
        return hash('sha256', json_encode([
            'schemaVersion'      => $this->schemaVersion,
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
     * Fields still null in this snapshot.
     * Each entry represents a gap in determinism coverage.
     *
     * @return string[]
     */
    public function gaps(): array
    {
        $gaps = [];
        if ($this->schedulerHash === null)      $gaps[] = 'schedulerHash (Phase 1)';
        if ($this->executionGraphHash === null) $gaps[] = 'executionGraphHash (Phase B)';
        if ($this->checkpointHash === null)     $gaps[] = 'checkpointHash (Phase B)';
        if ($this->retrySequenceHash === null)  $gaps[] = 'retrySequenceHash (Phase B)';
        if ($this->capabilityHash === null)     $gaps[] = 'capabilityHash (Phase C)';
        if ($this->providerRouteHash === null)  $gaps[] = 'providerRouteHash (Phase C)';
        if ($this->policyHash === null)         $gaps[] = 'policyHash (Phase D)';
        if ($this->eventBusHash === null)       $gaps[] = 'eventBusHash (Phase D)';
        return $gaps;
    }

    /**
     * Diff two snapshots: returns field-level details for each diverging field.
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

    /** Short string representation — useful in table output. */
    public function shortHash(): string
    {
        return substr($this->canonicalHash(), 0, 12);
    }
}
