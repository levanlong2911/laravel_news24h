<?php

namespace App\Video\Analysis;

/**
 * Output của SemanticClaimBenchmarkRunner — chỉ DATA. Nhẹ hơn BenchmarkResult
 * có chủ ý: không coverage/entity_count/landscape... (những thứ đó cần
 * Gatekeeper+Producer+Director, đường CHỈ-Extractor này không chạy tới).
 */
final class SemanticClaimBenchmarkResult
{
    /**
     * @param list<array{entity_id: string, attribute: string, evidence_quote: string, reason: string}> $failures
     */
    public function __construct(
        public readonly string $articleId,
        public readonly string $title,
        public readonly string $domain,
        public readonly string $status,
        public readonly string $error,
        public readonly int $callCount,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly float $costUsd,
        public readonly int $durationMs,
        public readonly int $total,
        public readonly int $verified,
        public readonly float $precision,
        public readonly array $failures,
        /** @var array<string, int> B1.1 — xem SemanticClaimReport::$reasonDistribution */
        public readonly array $reasonDistribution = [],
    ) {
    }
}
