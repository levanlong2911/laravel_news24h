<?php

declare(strict_types=1);

namespace App\Video\Render\Qa\DTO;

final readonly class RepairDispatchPayload
{
    public function __construct(
        public string $qaRunId,
        public string $parentRenderId,
        public string $parentRequestHash,
        public string $parentArtifactHash,
        public int $repairGeneration,
        public string $repairScopeId,
        public string $sourceQaReportHash,
        public array $repairPlan,
        public ?string $repairRequestHash = null,
    ) {}
}
