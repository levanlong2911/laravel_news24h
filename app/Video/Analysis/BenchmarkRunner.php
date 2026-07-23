<?php

namespace App\Video\Analysis;

use App\Video\Article\RawArticle;
use App\Video\Editorial\EditorialInterpreter;
use App\Video\Extraction\SemanticClaimPrecisionAnalyzer;
use App\Video\Llm\CostAccumulatingLlmClient;
use App\Video\Pipeline\VideoPlanningPipeline;
use App\Video\RenderPlan\RenderPlanMeta;
use App\Video\World\EntityType;

/**
 * Đo 1 bài báo qua VideoPlanningPipeline, trả về 1 "hàng" cho `video:benchmark`.
 * Tách khỏi Artisan command để test được KHÔNG cần DB (giống pattern mọi test
 * Video khác trong session này — pure unit, FakeExtractor/FakeProducer/FakeDirector).
 *
 * KHÔNG biết CSV, KHÔNG biết cách chọn article — chỉ orchestration đo lường
 * cho ĐÚNG MỘT bài, dùng VideoPlanningPipeline thật (không chép lại logic).
 */
final class BenchmarkRunner
{
    public function __construct(
        private readonly VideoPlanningPipeline $pipeline,
        private readonly EditorialInterpreter $editorial = new EditorialInterpreter(),
        private readonly ConfidenceAnalyzer $confidence = new ConfidenceAnalyzer(),
        private readonly ?CostAccumulatingLlmClient $costTracker = null,
        private readonly SemanticClaimPrecisionAnalyzer $semanticClaims = new SemanticClaimPrecisionAnalyzer(),
    ) {
    }

    public function runOne(RawArticle $article, RenderPlanMeta $meta, string $domain = ''): BenchmarkResult
    {
        $this->costTracker?->reset();
        $world = null;
        $semanticReport = null;

        try {
            $plan = $this->pipeline->plan(
                $article,
                $meta,
                60.0,
                onWorldVerified: function ($verified) use (&$world): void {
                    $world = $verified;
                },
                // B1: chỉ ĐO — analyzer không ghi gì vào $world/$plan.
                onExtracted: function ($extraction, $index) use (&$semanticReport): void {
                    $semanticReport = $this->semanticClaims->analyze($extraction->candidates, $index);
                },
            );
        } catch (\Throwable $e) {
            return $this->result($article, $domain, status: 'ERROR', error: $e->getMessage());
        }

        $entityCount    = $world !== null ? count($world->entities()) : 0;
        $landscapeCount = $world !== null
            ? count(array_filter($world->entities(), fn ($e) => $e->type === EntityType::Landscape))
            : 0;
        $reason = $world !== null ? $this->editorial->environmentDiagnosisFor($world) : 'NONE';

        $report = $this->confidence->analyze($plan);

        return $this->result($article, $domain, status: 'SUCCESS', error: '', extra: [
            'entity_count'    => $entityCount,
            'landscape_count' => $landscapeCount,
            'environment_reason'     => $reason,
            'environment_attributes' => array_keys($plan['world_environment'] ?? []),
            'prohibition_count'      => count($plan['continuity']['prohibitions'] ?? []),
            'coverage_score'  => $report->coverageScore,
            'coverage_layers' => $report->coverageLayers,
            'missing_layers'  => $report->missingLayers,
            'implemented_coverage_score' => $report->implementedCoverageScore,
            'render_plan'     => $plan,
            'semantic_claims_total'     => $semanticReport?->total ?? 0,
            'semantic_claims_verified'  => $semanticReport?->verified ?? 0,
            'semantic_claims_precision' => $semanticReport?->precision() ?? 0.0,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function result(RawArticle $article, string $domain, string $status, string $error, array $extra = []): BenchmarkResult
    {
        $totals = $this->costTracker?->totals() ?? ['call_count' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0.0, 'latency_ms' => 0];

        return new BenchmarkResult(
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
            entityCount: $extra['entity_count'] ?? 0,
            landscapeCount: $extra['landscape_count'] ?? 0,
            environmentReason: $extra['environment_reason'] ?? 'NONE',
            environmentAttributes: $extra['environment_attributes'] ?? [],
            prohibitionCount: $extra['prohibition_count'] ?? 0,
            coverageScore: $extra['coverage_score'] ?? 0.0,
            coverageLayers: $extra['coverage_layers'] ?? [],
            missingLayers: $extra['missing_layers'] ?? [],
            implementedCoverageScore: $extra['implemented_coverage_score'] ?? 0.0,
            renderPlan: $extra['render_plan'] ?? null,
            semanticClaimsTotal: $extra['semantic_claims_total'] ?? 0,
            semanticClaimsVerified: $extra['semantic_claims_verified'] ?? 0,
            semanticClaimsPrecision: $extra['semantic_claims_precision'] ?? 0.0,
        );
    }
}
