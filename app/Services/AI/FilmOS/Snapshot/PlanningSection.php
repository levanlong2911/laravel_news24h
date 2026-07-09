<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Phase A snapshot section — planning layer hashes.
 *
 * Produced by PlanningSnapshotBuilder and consumed by SnapshotComposer.
 * Field keys match ExecutionSnapshot constructor parameter names exactly.
 */
final class PlanningSection implements SnapshotSection
{
    public function __construct(
        public readonly string  $dagHash,
        public readonly string  $goalGraphHash,
        public readonly string  $promptHash,
        public readonly ?string $schedulerHash,
        public readonly ?string $policyHash,
    ) {}

    public function fields(): array
    {
        return [
            'dagHash'       => $this->dagHash,
            'goalGraphHash' => $this->goalGraphHash,
            'promptHash'    => $this->promptHash,
            'schedulerHash' => $this->schedulerHash,
            'policyHash'    => $this->policyHash,
        ];
    }
}
