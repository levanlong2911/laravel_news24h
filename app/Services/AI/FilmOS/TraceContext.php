<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS;

final class TraceContext
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $projectId,
        public readonly string $jobId,
    ) {}

    public static function generate(string $projectId, string $jobId): self
    {
        return new self(
            traceId:   sprintf('%s-%s', now()->format('Ymd-His'), substr(md5(uniqid('', true)), 0, 8)),
            projectId: $projectId,
            jobId:     $jobId,
        );
    }
}
