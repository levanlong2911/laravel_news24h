<?php

declare(strict_types=1);

namespace Tests\Integration\FilmOS;

use App\Services\AI\FilmOS\Planning\PlanningIR;
use App\Services\AI\FilmOS\Prompt\PromptCompiler;
use App\Services\AI\FilmOS\Prompt\PromptLearningEngine;
use App\Services\AI\FilmOS\Prompt\PromptRuleEngine;
use App\Services\AI\FilmOS\Prompt\Rules\DurationCameraRule;
use App\Services\AI\FilmOS\Render\Serializers\KlingSerializer;
use App\Services\AI\FilmOS\Runtime\Clients\KlingClient;
use App\Services\AI\FilmOS\Runtime\RenderRuntime;
use App\Services\AI\FilmOS\Runtime\RetryPolicy;
use App\Services\AI\FilmOS\Runtime\RuntimeEvent;
use App\Services\AI\Provider\Kling\FalKlingApiClient;
use Tests\TestCase;

/**
 * @group integration
 * @group external
 *
 * Requires two env vars to be set explicitly:
 *   RUN_INTEGRATION_TESTS=true   — opt-in gate (prevents accidental CI runs)
 *   FAL_API_KEY=your_key         — fal.ai API key (matches config('kling.fal_api_key'))
 *
 * Optional:
 *   FAL_KLING_MODEL=v1.6/standard  — FAL routing model (matches config('kling.fal_model'))
 *                                    defaults to v1.6/standard per known-good quirk
 *
 * Max wall time: 60 polls × 5s = 5 minutes.
 * Run manually:
 *   RUN_INTEGRATION_TESTS=true FAL_API_KEY=xxx php artisan test --group=integration
 */
final class KlingIntegrationTest extends TestCase
{
    private const GOLDEN_DIR   = __DIR__ . '/../../../resources/filmos/golden';
    private const MAX_POLLS    = 60;   // 5 min absolute ceiling
    private const POLL_BACKOFF = 5.0;  // seconds
    private const DEFAULT_MODEL = 'v1.6/standard'; // v2.1 known-broken per FAL quirks

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped(
                'Integration tests disabled. ' .
                'Run with: RUN_INTEGRATION_TESTS=true FAL_API_KEY=xxx php artisan test --group=integration'
            );
        }

        if (!getenv('FAL_API_KEY')) {
            $this->markTestSkipped(
                'FAL_API_KEY env var not set — cannot call Kling API. ' .
                'Set FAL_API_KEY=your_key to run this test.'
            );
        }
    }

    /**
     * @test
     *
     * Exercises the complete production pipeline path:
     *   planning_ir.json fixture
     *     → PromptCompiler
     *     → PromptRuleEngine (DurationCameraRule)
     *     → PromptLearningEngine
     *     → RenderIR
     *     → KlingSerializer
     *     → FalKlingApiClient (real HTTP to fal.ai)
     *     → RenderRuntime poll loop
     *     → ProviderResult (DOWNLOAD_COMPLETED)
     *
     * Uses the sports_touchdown fixture: 5s clip, close_up camera, urgent visual.
     */
    public function sports_touchdown_full_pipeline_renders_to_download_completed(): void
    {
        // ── 1. Load fixture ───────────────────────────────────────────────────
        $data = json_decode(
            file_get_contents(self::GOLDEN_DIR . '/sports_touchdown/planning_ir.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        // Use a unique traceId per run so it doesn't collide with golden fixtures.
        $traceId    = 'integration-' . date('YmdHis');
        $planningIr = new PlanningIR(
            traceId:     $traceId,
            version:     $data['version'],
            shotId:      $data['shotId'],
            shotOrder:   $data['shotOrder'],
            goalId:      $data['goalId'],
            renderHints: $data['renderHints'] ?? [],
            constraints: $data['constraints'] ?? [],
            attributes:  $data['attributes']  ?? [],
        );

        // ── 2. Build pipeline (same wiring as production) ─────────────────────
        $compiler       = new PromptCompiler();
        $ruleEngine     = new PromptRuleEngine([new DurationCameraRule()]);
        $learningEngine = new PromptLearningEngine();
        $serializer     = new KlingSerializer();

        // One FalKlingApiClient instance shared across submit/poll/download so
        // the in-memory statusUrls/resultUrls map is preserved between calls.
        // Model read from FAL_KLING_MODEL (matches config('kling.fal_model')); default = v1.6/standard.
        $falClient   = new FalKlingApiClient(
            apiKey:  (string) getenv('FAL_API_KEY'),
            model:   (string) (getenv('FAL_KLING_MODEL') ?: self::DEFAULT_MODEL),
            timeout: 30,
        );
        $klingClient = new KlingClient($falClient);
        $retryPolicy = new RetryPolicy(
            maxAttempts: self::MAX_POLLS,
            backoff:     self::POLL_BACKOFF,
        );
        $runtime = new RenderRuntime($serializer, $klingClient, $retryPolicy);

        // ── 3. Execute full pipeline ──────────────────────────────────────────
        $graph    = $compiler->compile($planningIr);
        $graph    = $ruleEngine->apply($graph);
        $renderIr = $learningEngine->compile($graph);

        $startedAt = microtime(true);
        $result    = $runtime->run($renderIr);
        $elapsed   = round(microtime(true) - $startedAt, 1);

        // ── 4. Guard: clear failure message on timeout ────────────────────────
        if ($result->status === RuntimeEvent::TIMEOUT) {
            $this->fail(sprintf(
                "Kling job timed out after %.1fs\n  requestId : %s\n  last status: %s\n  traceId   : %s",
                $elapsed,
                $result->requestId,
                $result->status->value,
                $result->traceId,
            ));
        }

        // ── 5. Assert ProviderResult contract ─────────────────────────────────

        // Final status must be DOWNLOAD_COMPLETED
        $this->assertSame(
            RuntimeEvent::DOWNLOAD_COMPLETED,
            $result->status,
            sprintf(
                'Expected DOWNLOAD_COMPLETED, got %s after %.1fs (requestId: %s)',
                $result->status->value,
                $elapsed,
                $result->requestId,
            ),
        );

        // traceId propagates unchanged through the entire pipeline
        $this->assertSame($traceId, $result->traceId);

        // Provider assigns a non-empty requestId at submit time
        $this->assertNotEmpty($result->requestId);

        // Asset URL is a valid HTTP(S) address — don't assert hostname (CDN may change)
        $this->assertNotEmpty($result->assetUrl);
        $this->assertStringStartsWith('http', $result->assetUrl);

        // Video duration is populated (FalKlingApiClient returns provider-reported seconds)
        $this->assertGreaterThan(0.0, $result->duration);
    }
}
