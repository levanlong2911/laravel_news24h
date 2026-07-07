<?php

namespace Tests\Unit\AI\Provider\Kling;

use App\Services\AI\Provider\Kling\Dto\SubmitVideoRequest;
use App\Services\AI\Provider\Kling\KlingRequestFactory;
use PHPUnit\Framework\TestCase;

final class KlingRequestFactoryTest extends TestCase
{
    private KlingRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new KlingRequestFactory();
    }

    public function test_build_submit_payload_maps_all_fields(): void
    {
        $request = new SubmitVideoRequest(
            prompt:          'A cinematic shot of a villa pool',
            negativePrompt:  'blurry, text, watermark',
            model:           'kling-v1',
            mode:            'std',
            durationSeconds: 5,
            aspectRatio:     '16:9',
            cfgScale:        0.5,
        );

        $payload = $this->factory->buildSubmitPayload($request);

        $this->assertSame('kling-v1', $payload['model_name']);
        $this->assertSame('A cinematic shot of a villa pool', $payload['prompt']);
        $this->assertSame('blurry, text, watermark', $payload['negative_prompt']);
        $this->assertSame(0.5, $payload['cfg_scale']);
        $this->assertSame('std', $payload['mode']);
        $this->assertSame('5', $payload['duration']);
        $this->assertSame('16:9', $payload['aspect_ratio']);
    }

    public function test_empty_negative_prompt_is_omitted(): void
    {
        $request = new SubmitVideoRequest(
            prompt:          'test',
            negativePrompt:  '',
            model:           'kling-v1',
            mode:            'std',
            durationSeconds: 5,
            aspectRatio:     '16:9',
            cfgScale:        0.5,
        );

        $payload = $this->factory->buildSubmitPayload($request);

        $this->assertArrayNotHasKey('negative_prompt', $payload);
    }

    public function test_duration_is_serialized_as_string(): void
    {
        $request = new SubmitVideoRequest(
            prompt: 'test', negativePrompt: '', model: 'kling-v1',
            mode: 'pro', durationSeconds: 10, aspectRatio: '9:16', cfgScale: 0.7,
        );

        $payload = $this->factory->buildSubmitPayload($request);

        $this->assertIsString($payload['duration']);
        $this->assertSame('10', $payload['duration']);
    }
}
