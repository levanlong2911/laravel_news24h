<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AI\FilmOS\Benchmark\BenchmarkRecorder;
use App\Services\AI\FilmOS\Benchmark\BenchmarkRepository;
use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotionChangedHandler;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Character\CharacterIntroducedHandler;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDirectedHandler;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ProductionEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ProductionPlannedHandler;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditor;
use App\Services\AI\FilmOS\Narrative\QA\Rules\CameraFocusNodeExistsRule;
use App\Services\AI\FilmOS\Narrative\QA\Rules\DanglingCharacterWorldRefRule;
use App\Services\AI\FilmOS\Narrative\QA\Rules\DanglingSceneWorldRefRule;
use App\Services\AI\FilmOS\Narrative\QA\Rules\DuplicateIntroductionRule;
use App\Services\AI\FilmOS\Narrative\QA\Rules\EmotionWithoutIntroductionRule;
use App\Services\AI\FilmOS\Narrative\QA\Rules\MissingCameraRule;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguredHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneEventFactory;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodePlacedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneNodeRemovedHandler;
use App\Services\AI\FilmOS\Narrative\Scene\SceneRelationEstablishedHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\Clock;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\SystemClock;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Narrative\World\WorldEventFactory;
use App\Services\AI\FilmOS\Narrative\World\WorldFactAssertedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectPlacedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectRemovedHandler;
use App\Services\AI\FilmOS\Pipeline\DefaultRenderAssetAssembler;
use App\Services\AI\FilmOS\Pipeline\FilmOSPipeline;
use App\Services\AI\FilmOS\Pipeline\FilmOSPipelineAdapter;
use App\Services\AI\FilmOS\Pipeline\FilmOSShotRuntime;
use App\Services\AI\FilmOS\Pipeline\LazyLegacyPipeline;
use App\Services\AI\FilmOS\Pipeline\RenderAssetAssembler;
use App\Services\AI\FilmOS\Pipeline\ShotRenderService;
use App\Services\AI\FilmOS\Production\FfmpegPipeline;
use App\Services\AI\FilmOS\Production\VideoProductionPipeline;
use App\Services\AI\FilmOS\Prompt\PromptCompiler;
use App\Services\AI\FilmOS\Prompt\PromptLearningEngine;
use App\Services\AI\FilmOS\Prompt\PromptRuleEngine;
use App\Services\AI\FilmOS\Prompt\Rules\DurationCameraRule;
use App\Services\AI\FilmOS\Render\Serializers\KlingSerializer;
use App\Services\AI\FilmOS\Runtime\Clients\KlingClient;
use App\Services\AI\FilmOS\Runtime\RenderRuntime;
use App\Services\AI\FilmOS\Runtime\RetryPolicy;
use App\Services\AI\Provider\Kling\FalKlingApiClient;
use Illuminate\Support\ServiceProvider;

class FilmOSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RenderAssetAssembler::class, function () {
            return new DefaultRenderAssetAssembler(
                new FfmpegPipeline(config('filmos.ffmpeg_path', 'ffmpeg')),
            );
        });

        // D0 Knowledge Layer — Timeline must be singleton so Recorder and callers share the same instance
        $this->app->singleton(SemanticTimeline::class, InMemorySemanticTimeline::class);

        $this->app->bind(TimelineRecorder::class, function () {
            return new TimelineRecorder($this->app->make(SemanticTimeline::class));
        });

        $this->app->bind(TimelineProjector::class, function () {
            return new DefaultTimelineProjector(handlers: [
                new ShotPlannedProjectionHandler(),        // priority 0   — Story (D0)
                new WorldObjectPlacedHandler(),            // priority 100 — World (D3)
                new WorldObjectRemovedHandler(),           // priority 100
                new WorldFactAssertedHandler(),            // priority 100
                new CharacterIntroducedHandler(),          // priority 200 — Character (D2)
                new CharacterEmotionChangedHandler(),      // priority 200
                new SceneNodePlacedHandler(),              // priority 300 — Scene (D4)
                new SceneNodeRemovedHandler(),             // priority 300
                new SceneRelationEstablishedHandler(),     // priority 300
                new CameraConfiguredHandler(),             // priority 300
                new ProductionPlannedHandler(),            // priority 400 — Production (staging)
                new PerformanceDirectedHandler(),          // priority 500 — Performance (acting)
            ]);
        });

        $this->app->bind(ShotPlannedEventFactory::class, ShotPlannedEventFactory::class);

        // D3 World Layer
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(WorldEventFactory::class, function () {
            return new WorldEventFactory($this->app->make(Clock::class));
        });

        // D4 Scene Layer
        $this->app->bind(SceneEventFactory::class, function () {
            return new SceneEventFactory($this->app->make(Clock::class));
        });

        // D2 Character Layer
        $this->app->bind(CharacterEventFactory::class, function () {
            return new CharacterEventFactory($this->app->make(Clock::class));
        });

        // Production Layer (staging knowledge)
        $this->app->bind(ProductionEventFactory::class, function () {
            return new ProductionEventFactory($this->app->make(Clock::class));
        });

        // Performance Layer (acting knowledge)
        $this->app->bind(PerformanceEventFactory::class, function () {
            return new PerformanceEventFactory($this->app->make(Clock::class));
        });

        $this->app->bind(NarrativeBootstrapper::class, function () {
            return new NarrativeBootstrapper(
                worldFactory:     $this->app->make(WorldEventFactory::class),
                shotFactory:      $this->app->make(ShotPlannedEventFactory::class),
                sceneFactory:     $this->app->make(SceneEventFactory::class),
                characterFactory:  $this->app->make(CharacterEventFactory::class),
                productionFactory:  $this->app->make(ProductionEventFactory::class),
                performanceFactory: $this->app->make(PerformanceEventFactory::class),
                recorder:           $this->app->make(TimelineRecorder::class),
            );
        });

        // D5 QA Layer — canonical rule order (deterministic reports; never container order)
        $this->app->bind(NarrativeAuditor::class, function () {
            return new NarrativeAuditor(rules: [
                // Character (D2)
                new EmotionWithoutIntroductionRule(),
                new DuplicateIntroductionRule(),
                new DanglingCharacterWorldRefRule(),
                // Scene (D4)
                new DanglingSceneWorldRefRule(),
                // Camera (D4)
                new MissingCameraRule(),
                new CameraFocusNodeExistsRule(),
            ]);
        });

        $this->app->bind(VideoProductionPipeline::class, function () {
            $assembler     = $this->app->make(RenderAssetAssembler::class);
            $renderRuntime = $this->buildRenderRuntime();

            $legacy = new LazyLegacyPipeline(
                promptCompiler: new PromptCompiler(),
                learningEngine: PromptLearningEngine::baseline(),
                renderRuntime:  $renderRuntime,
                assembler:      $assembler,
            );

            return new FilmOSPipelineAdapter(
                enabled: (bool) config('filmos.enabled', false),
                legacy:  $legacy,
                filmOS:  $this->buildFilmOSPipeline($renderRuntime, $assembler),
            );
        });
    }

    private function buildRenderRuntime(): RenderRuntime
    {
        return new RenderRuntime(
            new KlingSerializer(),
            new KlingClient(FalKlingApiClient::fromConfig()),
            new RetryPolicy(
                maxAttempts: (int) config('filmos.render_max_polls', 60),
                backoff:     (float) config('filmos.render_poll_backoff', 5.0),
            ),
        );
    }

    private function buildFilmOSPipeline(RenderRuntime $renderRuntime, RenderAssetAssembler $assembler): FilmOSPipeline
    {
        $shotRuntime = new FilmOSShotRuntime(
            promptCompiler:  new PromptCompiler(),
            ruleEngine:      new PromptRuleEngine([new DurationCameraRule()]),
            learningEngine:  PromptLearningEngine::baseline(),
            renderRuntime:   $renderRuntime,
        );

        $shotRender = new ShotRenderService(
            shotRuntime:         $shotRuntime,
            benchmarkRecorder:   new BenchmarkRecorder(),
            benchmarkRepository: new BenchmarkRepository(),
        );

        return new FilmOSPipeline(
            shotRender: $shotRender,
            assembler:  $assembler,
        );
    }
}
