<?php

namespace App\Services\AI\Pipeline;

final class PipelineStageDefinition
{
    public function __construct(
        public readonly string $stage,
        public readonly string $plannerClass,
        public readonly string $validatorClass,
        public readonly string $schemaFile,    // path relative to contracts/v1/
    ) {}
}
