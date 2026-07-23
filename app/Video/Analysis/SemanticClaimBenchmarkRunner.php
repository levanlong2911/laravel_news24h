<?php

namespace App\Video\Analysis;

use App\Video\Article\ArticleNormalizer;
use App\Video\Article\RawArticle;
use App\Video\Extraction\Extractor;
use App\Video\Extraction\SemanticClaimPrecisionAnalyzer;
use App\Video\Llm\CostAccumulatingLlmClient;

/**
 * B1 (2026-07-22, xem project_benchmark_pilot10_findings memory) — đo precision
 * semanticClaims RẺ HƠN BenchmarkRunner: chỉ gọi Extractor (1 lệnh/bài), KHÔNG
 * qua Gatekeeper/Producer/Director/Assembler — những tầng đó không cần thiết
 * để đo precision, chỉ tốn thêm tiền. Tách riêng khỏi BenchmarkRunner để không
 * cõng 2 luồng đo khác hẳn nhau (full pipeline vs Extractor-only) vào 1 class.
 */
final class SemanticClaimBenchmarkRunner
{
    public function __construct(
        private readonly Extractor $extractor,
        private readonly ArticleNormalizer $normalizer = new ArticleNormalizer(),
        private readonly SemanticClaimPrecisionAnalyzer $analyzer = new SemanticClaimPrecisionAnalyzer(),
        private readonly ?CostAccumulatingLlmClient $costTracker = null,
    ) {
    }

    public function runOne(RawArticle $article, string $domain = ''): SemanticClaimBenchmarkResult
    {
        $this->costTracker?->reset();

        try {
            $index      = $this->normalizer->normalize($article);
            $extraction = $this->extractor->extract($article, $index);
            $report     = $this->analyzer->analyze($extraction->candidates, $index);
        } catch (\Throwable $e) {
            return $this->result($article, $domain, status: 'ERROR', error: $e->getMessage());
        }

        return $this->result($article, $domain, status: 'SUCCESS', error: '', report: $report);
    }

    private function result(RawArticle $article, string $domain, string $status, string $error, ?\App\Video\Extraction\SemanticClaimReport $report = null): SemanticClaimBenchmarkResult
    {
        $totals = $this->costTracker?->totals() ?? ['call_count' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0.0, 'latency_ms' => 0];

        return new SemanticClaimBenchmarkResult(
            articleId: $article->id,
            title: $article->title,
            domain: $domain,
            status: $status,
            error: $error,
            callCount: $totals['call_count'],
            tokensIn: $totals['tokens_in'],
            tokensOut: $totals['tokens_out'],
            costUsd: $totals['cost_usd'],
            durationMs: $totals['latency_ms'],
            total: $report?->total ?? 0,
            verified: $report?->verified ?? 0,
            precision: $report?->precision() ?? 0.0,
            failures: $report?->failures ?? [],
            reasonDistribution: $report?->reasonDistribution ?? [],
        );
    }
}
