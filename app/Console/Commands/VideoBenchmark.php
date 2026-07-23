<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Admin\ClaudeWriterService;
use App\Video\Analysis\BenchmarkRunner;
use App\Video\Analysis\ConfidenceAnalyzer;
use App\Video\Analysis\SemanticClaimBenchmarkRunner;
use App\Video\Article\RawArticle;
use App\Video\Director\ActionSelection;
use App\Video\Director\FakeDirector;
use App\Video\Editorial\EditorialInterpreter;
use App\Video\Extraction\CandidateClaim;
use App\Video\Extraction\CandidateEntity;
use App\Video\Extraction\CandidateWorldGraph;
use App\Video\Extraction\ClaudeExtractor;
use App\Video\Extraction\FakeExtractor;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Llm\CostAccumulatingLlmClient;
use App\Video\Llm\CostCeilingGate;
use App\Video\Llm\GatedLlmClient;
use App\Video\Pipeline\VideoPipelineFactory;
use App\Video\Pipeline\VideoPlanningPipeline;
use App\Video\Producer\FakeProducer;
use App\Video\Producer\ProducerOutput;
use App\Video\RenderPlan\RenderPlanMeta;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Benchmark harness của toàn AI Video pipeline — KHÔNG chỉ đo environment.
 * Thiết kế để mở rộng cột (visual_style coverage, action coverage, camera
 * diversity, prompt length...) mà không cần viết command mới — xem
 * BenchmarkRunner::runOne() cho từng cột hiện có.
 *
 * Command CHỈ lo: chọn bài (--articles/--sample), chọn Extractor/Producer/
 * Director thật hay giả (--extractor), ghi CSV. KHÔNG biết công thức confidence
 * (ConfidenceAnalyzer), KHÔNG biết cách chẩn đoán environment (EditorialInterpreter),
 * KHÔNG tự cộng dồn cost (CostAccumulatingLlmClient) — đúng vai orchestration thô.
 */
class VideoBenchmark extends Command
{
    protected $signature = 'video:benchmark
        {--articles= : Danh sách article UUID, phân tách bằng dấu phẩy}
        {--sample= : Preset có sẵn: yacht|nfl|mixed10|all}
        {--extractor=fake : fake (mặc định, $0, chỉ kiểm tra harness) hoặc claude (thật, tốn phí)}
        {--mode=full : full (mặc định, full pipeline: Extractor+Producer+Director) hoặc semantic (RẺ HƠN — chỉ Extractor, đo semantic_claims precision)}
        {--out= : Thư mục output (mặc định storage/app/benchmarks/<timestamp>/) — chứa results.csv + renderplans/<article_id>.json}';

    protected $description = 'Chạy VideoPlanningPipeline trên nhiều bài báo, ghi CSV đo lường (coverage, cost, environment...) + RenderPlan JSON từng bài';

    /** Bump khi đổi BỘ CỘT CSV — không phải khi pipeline đổi (đó là pipeline_version). */
    private const BENCHMARK_VERSION = 'env-benchmark-v1';

    /** article_id => domain — curated 2026-07-22, phủ 5 domain, không dồn NFL. */
    private const MIXED10 = [
        'a22acd2c-ea9f-4913-a329-c04926233c26' => 'yacht',
        'a24744a6-2477-4540-af84-59255cc606f9' => 'yacht',
        'a2231fa9-0a21-44eb-90de-62ab095bf322' => 'yacht',
        'a1c8b874-9c5b-4be3-9cb8-8f9c0c347da6' => 'nfl',
        'a1b883bd-57ff-49c8-8e5c-e60cbe753559' => 'nfl',
        'a2232036-c9c4-4fe6-a254-62761655f194' => 'nfl',
        'a24d0cfe-ac81-4eec-abcf-960d28ca77d5' => 'celebrity',
        'a2231efd-ce78-48b7-87a2-db5c1441d829' => 'celebrity',
        'a236e915-8bc4-4c97-a038-5e9184f440ea' => 'animal',
        'a2231ecb-d43f-4a74-aa29-a415fd2489ed' => 'general',
    ];

    private const CSV_HEADER = [
        'article_id', 'title', 'domain', 'status', 'error',
        'pipeline_version', 'benchmark_version', 'confidence_version', 'environment_version', 'style_version',
        'call_count', 'tokens_in', 'tokens_out', 'cost_usd', 'duration_ms',
        'entity_count', 'landscape_count',
        'environment_reason', 'environment_attributes', 'prohibition_count',
        'coverage_score', 'implemented_coverage_score', 'coverage_layers', 'missing_layers',
        'semantic_claims_total', 'semantic_claims_verified', 'semantic_claims_precision',
    ];

    public function __construct(private readonly ClaudeWriterService $claudeWriter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $extractorMode = $this->option('extractor');
        if (! in_array($extractorMode, ['fake', 'claude'], true)) {
            $this->error("--extractor phải là 'fake' hoặc 'claude', nhận: {$extractorMode}");
            return self::FAILURE;
        }

        $mode = $this->option('mode');
        if (! in_array($mode, ['full', 'semantic'], true)) {
            $this->error("--mode phải là 'full' hoặc 'semantic', nhận: {$mode}");
            return self::FAILURE;
        }

        $articles = $this->resolveArticles();
        if ($articles === []) {
            $this->error('Không có bài nào — dùng --articles=uuid1,uuid2 hoặc --sample=yacht|nfl|mixed10|all');
            return self::FAILURE;
        }

        return $mode === 'semantic'
            ? $this->handleSemantic($articles, $extractorMode)
            : $this->handleFull($articles, $extractorMode);
    }

    /**
     * @param list<array{model: Article, domain: string}> $articles
     */
    private function handleFull(array $articles, string $extractorMode): int
    {
        $runDir = $this->option('out') ?? storage_path('app/benchmarks/' . now()->format('Ymd_His'));
        $renderPlanDir = $runDir . '/renderplans';
        if (! is_dir($renderPlanDir)) {
            mkdir($renderPlanDir, 0o755, true);
        }

        $pipelineVersion = (string) config('video.pipeline_version', 'unknown');
        // style_version: visual_style chưa có nguồn (Producer/StylePlanner) —
        // để rỗng thay vì bịa số, đúng tinh thần "record, don't invent".
        $styleVersion = 'n/a (chưa xây)';

        $this->info(sprintf(
            'Benchmark %d bài, extractor=%s, pipeline=%s, confidence=%s, environment=%s',
            count($articles), $extractorMode, $pipelineVersion,
            ConfidenceAnalyzer::VERSION, EditorialInterpreter::ENVIRONMENT_MAPPING_VERSION,
        ));

        $csvPath = $runDir . '/results.csv';
        $handle  = fopen($csvPath, 'w');
        fputcsv($handle, self::CSV_HEADER);

        $bar = $this->output->createProgressBar(count($articles));
        foreach ($articles as $article) {
            $result = $this->runner($extractorMode)->runOne(
                new RawArticle($article['model']->id, $article['model']->title, (string) $article['model']->content),
                new RenderPlanMeta(Str::uuid()->toString(), $article['model']->id, $article['model']->title, 'en', now()->toIso8601String()),
                $article['domain'],
            );

            fputcsv($handle, [
                $result->articleId, $result->title, $result->domain, $result->status, $result->error,
                $pipelineVersion, self::BENCHMARK_VERSION, ConfidenceAnalyzer::VERSION,
                EditorialInterpreter::ENVIRONMENT_MAPPING_VERSION, $styleVersion,
                $result->callCount, $result->tokensIn, $result->tokensOut, $result->costUsd, $result->durationMs,
                $result->entityCount, $result->landscapeCount,
                $result->environmentReason, $result->environmentAttributes !== [] ? implode(',', $result->environmentAttributes) : '-',
                $result->prohibitionCount,
                $result->coverageScore, $result->implementedCoverageScore,
                implode(',', $result->coverageLayers), implode(',', $result->missingLayers),
                $result->semanticClaimsTotal, $result->semanticClaimsVerified, $result->semanticClaimsPrecision,
            ]);

            if ($result->renderPlan !== null) {
                file_put_contents(
                    $renderPlanDir . '/' . $result->articleId . '.json',
                    json_encode($result->renderPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                );
            }

            $bar->advance();
        }
        $bar->finish();
        fclose($handle);

        $this->newLine(2);
        $this->info("Xong -> {$csvPath}");
        $this->info("RenderPlan JSON từng bài -> {$renderPlanDir}/");

        return self::SUCCESS;
    }

    /**
     * @param list<array{model: Article, domain: string}> $articles
     */
    private function handleSemantic(array $articles, string $extractorMode): int
    {
        $runDir = $this->option('out') ?? storage_path('app/benchmarks/' . now()->format('Ymd_His'));
        $failuresDir = $runDir . '/semantic_failures';
        if (! is_dir($failuresDir)) {
            mkdir($failuresDir, 0o755, true);
        }

        $this->info(sprintf(
            'Semantic-only benchmark %d bài (chỉ Extractor, KHÔNG Producer/Director), extractor=%s',
            count($articles), $extractorMode,
        ));

        $csvPath = $runDir . '/semantic_results.csv';
        $handle  = fopen($csvPath, 'w');
        fputcsv($handle, [
            'article_id', 'title', 'domain', 'status', 'error',
            'call_count', 'tokens_in', 'tokens_out', 'cost_usd', 'duration_ms',
            'semantic_claims_total', 'semantic_claims_verified', 'semantic_claims_precision',
            'reason_verbatim', 'reason_normalized', 'reason_inferred', 'reason_other',
        ]);

        $bar = $this->output->createProgressBar(count($articles));
        foreach ($articles as $article) {
            $result = $this->semanticRunner($extractorMode)->runOne(
                new RawArticle($article['model']->id, $article['model']->title, (string) $article['model']->content),
                $article['domain'],
            );

            $reasons = $result->reasonDistribution;
            $other = array_sum(array_diff_key($reasons, array_flip(['verbatim', 'normalized', 'inferred'])));

            fputcsv($handle, [
                $result->articleId, $result->title, $result->domain, $result->status, $result->error,
                $result->callCount, $result->tokensIn, $result->tokensOut, $result->costUsd, $result->durationMs,
                $result->total, $result->verified, $result->precision,
                $reasons['verbatim'] ?? 0, $reasons['normalized'] ?? 0, $reasons['inferred'] ?? 0, $other,
            ]);

            if ($result->failures !== []) {
                file_put_contents(
                    $failuresDir . '/' . $result->articleId . '.json',
                    json_encode($result->failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                );
            }

            $bar->advance();
        }
        $bar->finish();
        fclose($handle);

        $this->newLine(2);
        $this->info("Xong -> {$csvPath}");
        $this->info("Failures chi tiết từng bài -> {$failuresDir}/");

        return self::SUCCESS;
    }

    /**
     * @return list<array{model: Article, domain: string}>
     */
    private function resolveArticles(): array
    {
        if ($this->option('articles')) {
            $ids = array_filter(array_map('trim', explode(',', $this->option('articles'))));
            return Article::whereIn('id', $ids)->get()
                ->map(fn (Article $a) => ['model' => $a, 'domain' => ''])
                ->all();
        }

        return match ($this->option('sample')) {
            'mixed10' => Article::whereIn('id', array_keys(self::MIXED10))->get()
                ->map(fn (Article $a) => ['model' => $a, 'domain' => self::MIXED10[$a->id]])
                ->all(),
            'yacht' => Article::where('title', 'like', '%yacht%')->get()
                ->map(fn (Article $a) => ['model' => $a, 'domain' => 'yacht'])->all(),
            'nfl' => Article::where(function ($q) {
                $q->where('title', 'like', '%Packers%')
                    ->orWhere('title', 'like', '%Steelers%')
                    ->orWhere('title', 'like', '%Cowboys%')
                    ->orWhere('title', 'like', '%NFL%');
            })->get()->map(fn (Article $a) => ['model' => $a, 'domain' => 'nfl'])->all(),
            'all' => Article::all()->map(fn (Article $a) => ['model' => $a, 'domain' => ''])->all(),
            default => [],
        };
    }

    private function runner(string $extractorMode): BenchmarkRunner
    {
        if ($extractorMode === 'fake') {
            $pipeline = new VideoPlanningPipeline(
                new FakeExtractor($this->fakeCandidates()),
                new FakeProducer(new ProducerOutput('benchmark dry-run', 'benchmark dry-run', 'benchmark dry-run', [])),
                new FakeDirector(new ActionSelection('', 0, [], 'calm', 'immediate')),
            );

            return new BenchmarkRunner($pipeline, new EditorialInterpreter(), new ConfidenceAnalyzer());
        }

        $llm = new CostAccumulatingLlmClient(new GatedLlmClient(
            new ClaudeWriterAdapter($this->claudeWriter),
            new CostCeilingGate(0.05),
        ));

        // Cùng factory với VideoSessionService (nút 🎬) — đảm bảo benchmark đo
        // ĐÚNG hành vi production thật, kể cả continuity.prohibitions (EditorialPolicy).
        $pipeline = VideoPipelineFactory::claude($llm, VideoPipelineFactory::productionPolicies());

        return new BenchmarkRunner($pipeline, new EditorialInterpreter(), new ConfidenceAnalyzer(), $llm);
    }

    private function semanticRunner(string $extractorMode): SemanticClaimBenchmarkRunner
    {
        if ($extractorMode === 'fake') {
            return new SemanticClaimBenchmarkRunner(new FakeExtractor($this->fakeCandidates()));
        }

        $llm = new CostAccumulatingLlmClient(new GatedLlmClient(
            new ClaudeWriterAdapter($this->claudeWriter),
            new CostCeilingGate(0.05),
        ));

        return new SemanticClaimBenchmarkRunner(new ClaudeExtractor($llm), costTracker: $llm);
    }

    /**
     * Fixture CỐ ĐỊNH cho --extractor=fake — evidence_quote gần như chắc chắn
     * KHÔNG khớp bài báo thật (khác nội dung mỗi bài), nên hầu hết sẽ bị
     * Gatekeeper loại. Đây là ĐÚNG kỳ vọng: mục đích fake mode là kiểm tra
     * HARNESS (CSV/cột/status/error) chạy đúng, KHÔNG phải đo dữ liệu thật.
     */
    private function fakeCandidates(): CandidateWorldGraph
    {
        return new CandidateWorldGraph([
            new CandidateEntity('subject', 'physical_object', [
                new CandidateClaim('subject', 'note', 'benchmark dry-run', 'benchmark dry-run'),
            ]),
        ]);
    }
}
