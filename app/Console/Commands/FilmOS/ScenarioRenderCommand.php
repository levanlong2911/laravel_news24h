<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\Benchmark\Scenario\FactVisuals;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioBootstrapper;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioLoader;
use App\Services\AI\FilmOS\Benchmark\Scenario\ScenarioSchemaException;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditor;
use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;
use App\Services\AI\FilmOS\Prompting\Adapter\PromptRendererRegistry;
use App\Services\AI\FilmOS\Prompting\Compiler\NarrativePromptCompiler;
use App\Services\AI\FilmOS\Prompting\Render\KlingRenderRequestBuilder;
use App\Services\AI\FilmOS\Prompting\Render\RenderOptions;
use App\Services\AI\FilmOS\Prompting\Render\RenderRequest;
use App\Services\AI\FilmOS\Prompting\Render\RenderRequestBuilder;
use App\Services\AI\FilmOS\Prompting\Render\RenderRequestBuilderRegistry;
use App\Services\AI\FilmOS\Runtime\RenderRuntime;
use App\Services\AI\FilmOS\Runtime\RuntimeEvent;
use Illuminate\Console\Command;

/**
 * Render one benchmark scenario into a real video: it runs the same pipeline as
 * filmos:scenario up to the RenderedPrompt, then bridges to execution
 *   RenderedPrompt -> RenderRequestBuilder -> RenderRequest -> toPayload()
 *                  -> RenderRuntime::runPayload() -> ProviderClient -> video.
 *
 * Unlike filmos:scenario (free, CI-safe), this SPENDS provider credit. Use
 * --dry-run to build and inspect the exact payload without calling the provider.
 *
 *   php artisan filmos:render nfl_last_second_bomb --dry-run
 *   php artisan filmos:render nfl_last_second_bomb --duration=10
 */
final class ScenarioRenderCommand extends Command
{
    protected $signature = 'filmos:render
                            {id : Scenario id (filename without .json)}
                            {--provider=kling : Video provider to render with}
                            {--duration= : Clip length in seconds (defaults to the compiled duration)}
                            {--aspect=16:9 : Aspect ratio}
                            {--seed= : Optional seed for reproducibility}
                            {--dry-run : Build and print the payload without calling the provider (no cost)}';

    protected $description = 'Render a benchmark scenario into a real video (spends provider credit; use --dry-run to preview the payload)';

    public function handle(
        NarrativeAuditor $auditor,
        PromptRendererRegistry $renderers,
        RenderRequestBuilderRegistry $builders,
    ): int {
        $providerId = ProviderId::tryFrom((string) $this->option('provider'));
        if ($providerId === null || !$renderers->has($providerId) || !$builders->has($providerId)) {
            $known = implode(', ', array_map(static fn(ProviderId $p) => $p->value, ProviderId::cases()));
            $this->error("Unknown or unregistered provider '{$this->option('provider')}'. Known: {$known}");
            return self::FAILURE;
        }

        try {
            $doc = (new ScenarioLoader())->fromId((string) $this->argument('id'));
        } catch (ScenarioSchemaException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        // Same pipeline as filmos:scenario, up to the vendor-rendered prompt.
        $assembled = (new ScenarioBootstrapper())->assemble($doc);
        $audit     = $auditor->audit($assembled->timeline, $assembled->state);
        $state     = $assembled->state;

        $ir = (new NarrativePromptCompiler())->compile(
            $state->story, $state->characters, $state->scene, $state->world,
            $state->production, $state->performance, $audit,
            FactVisuals::fromFacts($doc->facts),
            $doc->visualStyle,
        );
        $rendered = $renderers->get($providerId)->render($ir);

        // Bridge: RenderedPrompt -> RenderRequest -> provider payload.
        $builder = $builders->get($providerId);
        $request = $builder->build($rendered, $this->renderOptions($doc->durationSeconds));
        $payload = $this->payloadFor($providerId, $builder, $request);

        if ($this->option('dry-run')) {
            $this->line("PAYLOAD ({$providerId->value}):");
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Dry run — no video was rendered and no credit was spent.');
            return self::SUCCESS;
        }

        // Real render — spends credit. Reuses the existing execution loop.
        $traceId = $doc->id . '_' . date('Ymd_His');
        $this->info("Rendering '{$doc->id}' via {$providerId->value} (trace {$traceId})...");

        $runtime = app(RenderRuntime::class);
        $result  = $runtime->runPayload($traceId, $payload);

        if ($result->status !== RuntimeEvent::DOWNLOAD_COMPLETED) {
            $this->error("Render did not complete: {$result->status->value}");
            if ($result->metadata !== []) {
                $this->line((string) json_encode($result->metadata, JSON_UNESCAPED_SLASHES));
            }
            return self::FAILURE;
        }

        $this->info('Render complete.');
        $this->line("  asset:    {$result->assetUrl}");
        // Report the requested length: the provider's result metadata is unreliable
        // (FAL reports 5s for a real 10s clip), whereas the request is what was rendered.
        $this->line("  duration: {$request->durationSeconds}s (requested)");
        return self::SUCCESS;
    }

    /** Build RenderOptions from CLI flags, defaulting to the scenario's authored length. */
    private function renderOptions(int $scenarioDuration): RenderOptions
    {
        $duration = $this->option('duration') !== null && $this->option('duration') !== ''
            ? (int) $this->option('duration')
            : ($scenarioDuration > 0 ? $scenarioDuration : 5);

        $seed = $this->option('seed') !== null && $this->option('seed') !== ''
            ? (int) $this->option('seed')
            : null;

        return new RenderOptions(
            durationSeconds: max(1, $duration),
            aspectRatio:     (string) $this->option('aspect'),
            seed:            $seed,
        );
    }

    /**
     * Map a RenderRequest into the provider's submit payload. toPayload() is
     * provider-specific (not on the RenderRequestBuilder interface), so the
     * concrete builder is narrowed here — one place, explicit extension point.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(ProviderId $provider, RenderRequestBuilder $builder, RenderRequest $request): array
    {
        return match (true) {
            $builder instanceof KlingRenderRequestBuilder => $builder->toPayload($request),
            default => throw new \RuntimeException("Provider '{$provider->value}' has no payload mapper yet."),
        };
    }
}
