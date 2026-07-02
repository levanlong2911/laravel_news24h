<?php

namespace App\DTOs;

use Illuminate\Support\Str;

final class PipelineContext
{
    public readonly string $correlationId;

    public function __construct(
        public readonly string $projectId,
        public readonly string $workflowVersion = '1.0',
        public readonly string $plannerVersion  = '1.0',
        public readonly string $compilerVersion = '1.0',
        public readonly string $contractVersion = '1.0',
        ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? (string) Str::uuid();
    }

    public function toArray(): array
    {
        return [
            'project_id'       => $this->projectId,
            'correlation_id'   => $this->correlationId,
            'workflow_version' => $this->workflowVersion,
            'planner_version'  => $this->plannerVersion,
            'compiler_version' => $this->compilerVersion,
            'contract_version' => $this->contractVersion,
        ];
    }

    /** Compute a cache key for a given input + all version fields. */
    public function cacheHash(array $inputData): string
    {
        $parts = [
            'contract_version' => $this->contractVersion,
            'compiler_version' => $this->compilerVersion,
            'input'            => $inputData,
            'planner_version'  => $this->plannerVersion,
            'workflow_version' => $this->workflowVersion,
        ];
        ksort($parts['input']);
        $payload = json_encode($parts, JSON_UNESCAPED_UNICODE);

        return hash('sha256', $payload);
    }
}
