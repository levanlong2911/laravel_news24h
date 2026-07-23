<?php

namespace App\Video\Analysis;

/**
 * Output của BenchmarkRunner::runOne() — DTO thuần data, KHÔNG biết CSV/JSON/
 * dashboard sẽ đọc nó thế nào (đó là việc của caller). `renderPlan` giữ lại
 * RenderPlan đầy đủ (null khi status=ERROR) để caller lưu artifact — DTO
 * không tự ghi file, chỉ mang dữ liệu.
 */
final class BenchmarkResult
{
    /**
     * @param list<string> $environmentAttributes
     * @param list<string> $coverageLayers
     * @param list<string> $missingLayers
     * @param ?array<string, mixed> $renderPlan
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
        public readonly int $entityCount,
        public readonly int $landscapeCount,
        public readonly string $environmentReason,
        public readonly array $environmentAttributes,
        public readonly int $prohibitionCount,
        public readonly float $coverageScore,
        public readonly array $coverageLayers,
        public readonly array $missingLayers,
        public readonly float $implementedCoverageScore = 0.0,
        public readonly ?array $renderPlan = null,
        /** B1 semanticClaims precision (đo, KHÔNG ảnh hưởng renderPlan) — xem SemanticClaimPrecisionAnalyzer. */
        public readonly int $semanticClaimsTotal = 0,
        public readonly int $semanticClaimsVerified = 0,
        public readonly float $semanticClaimsPrecision = 0.0,
    ) {
    }
}
