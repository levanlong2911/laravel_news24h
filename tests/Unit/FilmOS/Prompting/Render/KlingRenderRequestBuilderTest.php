<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Prompting\Render;

use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;
use App\Services\AI\FilmOS\Prompting\Adapter\RenderedPrompt;
use App\Services\AI\FilmOS\Prompting\Render\KlingRenderRequestBuilder;
use App\Services\AI\FilmOS\Prompting\Render\RenderOptions;
use App\Services\AI\FilmOS\Prompting\Render\RenderRequest;
use PHPUnit\Framework\TestCase;

/**
 * KlingRenderRequestBuilder owns the RenderedPrompt -> RenderRequest -> Kling
 * payload mapping. Tests assert the CONTRACT (keys/values KlingClient::submit
 * consumes), including the Kling-only quirks (mode, cfg_scale, 5|10 duration).
 */
final class KlingRenderRequestBuilderTest extends TestCase
{
    public function test_provider_is_kling(): void
    {
        $this->assertSame(ProviderId::KLING, (new KlingRenderRequestBuilder())->provider());
    }

    public function test_build_carries_prompt_and_options_into_a_neutral_request(): void
    {
        $request = (new KlingRenderRequestBuilder())->build(
            new RenderedPrompt('a quarterback throws', 'blurry, extra limbs'),
            new RenderOptions(durationSeconds: 10, aspectRatio: '9:16', seed: 42),
        );

        $this->assertSame(ProviderId::KLING, $request->provider);
        $this->assertSame('a quarterback throws', $request->positive);
        $this->assertSame('blurry, extra limbs', $request->negative);
        $this->assertSame(10, $request->durationSeconds);
        $this->assertSame('9:16', $request->aspectRatio);
        $this->assertSame(42, $request->seed);
        $this->assertSame('kling-v1', $request->model, 'defaults to the frozen Kling model');
    }

    public function test_options_model_overrides_the_default(): void
    {
        $request = (new KlingRenderRequestBuilder())->build(
            new RenderedPrompt('x'),
            new RenderOptions(model: 'kling-v1-custom'),
        );

        $this->assertSame('kling-v1-custom', $request->model);
    }

    public function test_to_payload_produces_the_keys_kling_client_consumes(): void
    {
        $payload = (new KlingRenderRequestBuilder())->toPayload(
            new RenderRequest(ProviderId::KLING, 'kling-v1', 'a spiral against the sky', 'text, watermark', 5, '16:9'),
        );

        $this->assertSame('kling-v1', $payload['model_name']);
        $this->assertSame('a spiral against the sky', $payload['prompt']);
        $this->assertSame('text, watermark', $payload['negative_prompt']);
        $this->assertSame('std', $payload['mode']);          // Kling-only, injected here
        $this->assertSame(0.5, $payload['cfg_scale']);        // Kling-only, injected here
        $this->assertSame('5', $payload['duration']);         // string, as the API expects
        $this->assertSame('16:9', $payload['aspect_ratio']);
    }

    public function test_to_payload_omits_negative_prompt_when_absent(): void
    {
        $payload = (new KlingRenderRequestBuilder())->toPayload(
            new RenderRequest(ProviderId::KLING, 'kling-v1', 'only positive'),
        );

        $this->assertArrayNotHasKey('negative_prompt', $payload);
    }

    /**
     * @dataProvider durations
     */
    public function test_to_payload_clamps_duration_to_kling_5_or_10(int $requested, string $expected): void
    {
        $payload = (new KlingRenderRequestBuilder())->toPayload(
            new RenderRequest(ProviderId::KLING, 'kling-v1', 'x', null, $requested),
        );

        $this->assertSame($expected, $payload['duration']);
    }

    /** @return array<string, array{int, string}> */
    public static function durations(): array
    {
        return [
            'exactly 5'      => [5, '5'],
            'exactly 10'     => [10, '10'],
            '7 rounds to 5'  => [7, '5'],   // tie -> nearest lower valid value
            '8 rounds to 10' => [8, '10'],
            '3 clamps to 5'  => [3, '5'],
            '20 clamps to 10' => [20, '10'],
        ];
    }
}
