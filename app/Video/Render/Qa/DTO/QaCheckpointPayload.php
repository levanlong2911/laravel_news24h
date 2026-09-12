<?php

declare(strict_types=1);

namespace App\Video\Render\Qa\DTO;

final readonly class QaCheckpointPayload
{
    public function __construct(
        public string $qaReportHash,
        public string $renderId,
        public string $requestHash,
        public string $artifactHash,
        public string $decision,
        public string $repairDecision,
        public string $visionProvider,
        public string $visionModel,
        public array $report,
        public array $usage = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            qaReportHash: $payload['qa_report_hash'],
            renderId: $payload['render_id'],
            requestHash: $payload['request_hash'],
            artifactHash: $payload['artifact_hash'],
            decision: $payload['decision'],
            repairDecision: $payload['repair_decision'],
            visionProvider: $payload['vision_provider'],
            visionModel: $payload['vision_model'],
            report: $payload['report'],
            usage: $payload['usage'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'qa_report_hash' => $this->qaReportHash,
            'render_id' => $this->renderId,
            'request_hash' => $this->requestHash,
            'artifact_hash' => $this->artifactHash,
            'decision' => $this->decision,
            'repair_decision' => $this->repairDecision,
            'vision_provider' => $this->visionProvider,
            'vision_model' => $this->visionModel,
            'report' => $this->report,
            'usage' => $this->usage,
        ];
    }
}
