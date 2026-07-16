<?php

declare(strict_types=1);

namespace Tests\Feature\FilmOS;

use App\Services\AI\FilmOS\Runtime\ProviderClient;
use App\Services\AI\FilmOS\Runtime\ProviderResult;
use App\Services\AI\FilmOS\Runtime\RenderRuntime;
use App\Services\AI\FilmOS\Runtime\RetryPolicy;
use App\Services\AI\FilmOS\Runtime\RuntimeEvent;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `filmos:render`. Never touches the network: --dry-run stops
 * before execution, and the real path binds a fake ProviderClient so the whole
 * bridge (RenderedPrompt -> RenderRequest -> payload -> runPayload) runs free.
 */
final class ScenarioRenderCommandTest extends TestCase
{
    public function test_dry_run_prints_the_kling_payload_and_spends_nothing(): void
    {
        $code   = Artisan::call('filmos:render', ['id' => 'nfl_last_second_bomb', '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('PAYLOAD', $output);
        $this->assertStringContainsString('"mode": "std"', $output);        // Kling-only quirk present
        $this->assertStringContainsString('"model_name": "kling-v1"', $output);
        $this->assertStringContainsString('no credit was spent', $output);

        // Article data is wired end-to-end: facts[].visual_hint + character appearance reach the prompt.
        $this->assertStringContainsString('KEY VISUALS', $output);
        $this->assertStringContainsStringIgnoringCase('two defenders converging', $output);   // facts[F2].visual_hint
        $this->assertStringContainsString('red jersey number 12', $output);                   // character.appearance
        $this->assertLessThan(                                                                 // ranked HIGH before MEDIUM
            stripos($output, 'lone figure downfield'),
            stripos($output, 'two defenders converging'),
        );
    }

    public function test_real_path_runs_the_bridge_and_reports_the_asset(): void
    {
        $this->bindFakeRuntime('https://cdn.example.com/nfl.mp4');

        $code   = Artisan::call('filmos:render', ['id' => 'nfl_last_second_bomb']);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Render complete', $output);
        $this->assertStringContainsString('https://cdn.example.com/nfl.mp4', $output);
    }

    public function test_unknown_provider_fails(): void
    {
        $code = Artisan::call('filmos:render', ['id' => 'nfl_last_second_bomb', '--provider' => 'nope']);

        $this->assertSame(1, $code);
    }

    public function test_missing_scenario_fails_with_message(): void
    {
        $code = Artisan::call('filmos:render', ['id' => 'does_not_exist', '--dry-run' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('does_not_exist', Artisan::output());
    }

    /** Swap the container's RenderRuntime for one driven by a canned, network-free client. */
    private function bindFakeRuntime(string $assetUrl): void
    {
        $client = new class ($assetUrl) implements ProviderClient {
            public function __construct(private readonly string $assetUrl) {}

            public function submit(string $traceId, array $payload): ProviderResult
            {
                return new ProviderResult($traceId, 'kling', 'fake-task', RuntimeEvent::COMPLETED);
            }

            public function poll(ProviderResult $result): ProviderResult
            {
                return $result;
            }

            public function download(ProviderResult $result): ProviderResult
            {
                return new ProviderResult(
                    traceId:   $result->traceId,
                    provider:  $result->provider,
                    requestId: $result->requestId,
                    status:    RuntimeEvent::DOWNLOAD_COMPLETED,
                    assetUrl:  $this->assetUrl,
                    duration:  5.0,
                );
            }
        };

        $this->app->bind(RenderRuntime::class, static fn () => new RenderRuntime(
            serializer:  new \App\Services\AI\FilmOS\Render\Serializers\KlingSerializer(),
            client:      $client,
            retryPolicy: new RetryPolicy(maxAttempts: 1, backoff: 0.0),
        ));
    }
}
