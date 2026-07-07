<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Admin\FactExtractorService;
use App\Services\AI\PromptCompiler\PromptDocumentBuilder;
use App\Services\AI\PromptCompiler\Renderers\KlingRenderer;
use App\Services\AI\ScenePlanner\ScenePlanner;
use App\Services\AI\Video\ArticleVideoSceneExtractor;
use App\Services\AI\Video\KlingSceneMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Generate Kling T2V prompts from a real article — no hardcoded fixtures.
 *
 * Pipeline:
 *   Article → FactExtractorService (real facts) → ArticleVideoSceneExtractor (3 scenes via Claude)
 *   → KlingSceneMapper (→ DSL) → ScenePlanner::enrich → KlingRenderer::renderLayered
 *
 * Usage:
 *   php artisan video:generate-kling {article_id}
 *   php artisan video:generate-kling {article_id} --json
 *   php artisan video:generate-kling {article_id} --out=payload_15s.json
 */
class GenerateArticleKlingCommand extends Command
{
    protected $signature = 'video:generate-kling
                            {article : Article ID (UUID) or slug}
                            {--json : Output JSON payload to stdout only}
                            {--out= : Write JSON payload to this file path (default: payload.json in project root)}
                            {--max-tokens=300 : Token budget for renderLayered}';

    protected $description = 'Generate 3×5s Kling T2V prompts from real article data (no hardcoded fixtures)';

    public function handle(
        FactExtractorService       $factExtractor,
        ArticleVideoSceneExtractor $sceneExtractor,
        KlingSceneMapper           $mapper,
        ScenePlanner               $scenePlanner,
    ): int {
        $articleArg = $this->argument('article');
        $jsonOnly   = $this->option('json');
        $outFile    = $this->option('out');
        $maxTokens  = (int) $this->option('max-tokens');

        // ── 1. Load article ───────────────────────────────────────────────────
        $article = Str::isUuid($articleArg)
            ? Article::find($articleArg)
            : Article::where('slug', $articleArg)->first();

        if (!$article) {
            $this->error("Article not found: {$articleArg}");
            return self::FAILURE;
        }

        if (!$jsonOnly) {
            $this->info("Article: {$article->title}");
            $this->line("  ID: {$article->id}");
            $this->newLine();
        }

        // ── 2. Extract facts (idempotent — returns existing row if any) ───────
        if (!$jsonOnly) {
            $this->line('[1/3] Extracting article facts…');
        }

        $facts = $factExtractor->run($article);

        if (!$jsonOnly) {
            $factCount = count($facts->facts_json ?? []);
            $this->line("  confidence={$facts->confidence}  facts={$factCount}"
                . ($facts->escalated_to_sonnet ? '  (escalated to Sonnet)' : ''));
            $this->newLine();
        }

        // ── 3. Extract 3 T2V scene specs from real facts via Claude ──────────
        if (!$jsonOnly) {
            $this->line('[2/3] Planning 3 × 5s scenes from article facts (Claude Sonnet)…');
        }

        try {
            $scenes = $sceneExtractor->extract($article, $facts);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        // ── 4. Build Kling prompt for each scene ──────────────────────────────
        if (!$jsonOnly) {
            $this->line('[3/3] Building Kling prompts via priority-layer renderer…');
            $this->newLine();
        }

        $klingScenes = [];

        foreach ($scenes as $i => $scene) {
            $dsl      = $mapper->toDsl($scene, $i + 1);
            $enriched = $scenePlanner->enrich($dsl);
            $doc      = PromptDocumentBuilder::build($enriched);
            $prompt   = KlingRenderer::renderLayered($doc, $enriched, $maxTokens);

            $klingScenes[] = array_merge($scene, [
                'kling_prompt'   => $prompt,
                'prompt_chars'   => mb_strlen($prompt),
                'prompt_tokens'  => (int) ceil(mb_strlen($prompt) / 4),
                'dsl_snapshot'   => [
                    'cam'   => $dsl['cam'],
                    'light' => $dsl['light'],
                    'emo'   => $dsl['emo'],
                    'move'  => $dsl['move'],
                    'lens'  => $dsl['lens'],
                ],
            ]);

            if (!$jsonOnly) {
                $n = $i + 1;
                $chars  = mb_strlen($prompt);
                $tokens = (int) ceil($chars / 4);
                $this->info("── Scene {$n} ({$scene['duration_seconds']}s) ──────────────────────────────────────");
                $this->line("  subject  : {$scene['subject']}");
                $this->line("  action   : {$scene['action_type']}");
                $this->line("  setting  : {$scene['setting']}");
                $this->line("  camera   : {$scene['camera']}  |  mood: {$scene['mood']}  |  time: {$scene['time_of_day']}");
                $this->line("  key_fact : {$scene['key_fact']}");
                $this->newLine();
                $this->line("  KLING PROMPT ({$chars} chars / {$tokens} tokens):");
                $this->line("  " . str_replace("\n", "\n  ", $prompt));
                $this->newLine();
            }
        }

        // ── 5. Build output payload ───────────────────────────────────────────
        $payload = [
            'article_id'      => $article->id,
            'article_title'   => $article->title,
            'article_slug'    => $article->slug,
            'generated_at'    => now()->toIso8601String(),
            'target_seconds'  => 15,
            'clip_count'      => count($klingScenes),
            'clip_seconds'    => 5,
            'facts_confidence'=> $facts->confidence,
            'scenes'          => $klingScenes,
        ];

        // ── 6. Output ─────────────────────────────────────────────────────────
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($jsonOnly) {
            $this->line($json);
            return self::SUCCESS;
        }

        // Write to file (default: payload.json in project root, or --out path)
        $outputPath = $outFile
            ? $outFile
            : base_path('payload.json');

        file_put_contents($outputPath, $json);

        $this->info("Payload written to: {$outputPath}");
        $this->newLine();
        $this->line('Next step:');
        $this->line('  python tools/benchmark_render.py --payload=' . $outputPath . ' --scene=1');
        $this->line('  python tools/render_article_video.py --payload=' . $outputPath);

        return self::SUCCESS;
    }
}
