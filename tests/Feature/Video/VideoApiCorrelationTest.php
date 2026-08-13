<?php

namespace Tests\Feature\Video;

use App\Exceptions\Handler;
use App\Services\VideoSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class VideoApiCorrelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['video.api_token' => 'correlation-test-token']);
    }

    public function test_successful_request_gets_a_generated_correlation_id_header(): void
    {
        $response = $this->withHeaders(['X-Video-Token' => 'correlation-test-token'])
            ->postJson('/api/video-shots/reclaim-expired');

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('X-Correlation-Id'));
    }

    public function test_a_provided_correlation_id_is_echoed_back_verbatim(): void
    {
        $response = $this->withHeaders([
            'X-Video-Token' => 'correlation-test-token',
            'X-Correlation-Id' => 'my-own-correlation-id',
        ])->postJson('/api/video-shots/reclaim-expired');

        $response->assertOk();
        $this->assertSame('my-own-correlation-id', $response->headers->get('X-Correlation-Id'));
    }

    public function test_an_overly_long_correlation_id_is_replaced_with_a_generated_uuid(): void
    {
        $response = $this->withHeaders([
            'X-Video-Token' => 'correlation-test-token',
            'X-Correlation-Id' => str_repeat('a', 5000),
        ])->postJson('/api/video-shots/reclaim-expired');

        $response->assertOk();
        $returned = $response->headers->get('X-Correlation-Id');
        $this->assertNotSame(str_repeat('a', 5000), $returned);
        $this->assertLessThanOrEqual(100, strlen($returned));
    }

    public function test_a_correlation_id_with_invalid_characters_is_replaced_with_a_generated_uuid(): void
    {
        $response = $this->withHeaders([
            'X-Video-Token' => 'correlation-test-token',
            'X-Correlation-Id' => "abc\r\nX-Injected: evil",
        ])->postJson('/api/video-shots/reclaim-expired');

        $response->assertOk();
        $returned = $response->headers->get('X-Correlation-Id');
        $this->assertStringNotContainsString("\r\n", $returned);
        $this->assertStringNotContainsString('Injected', $returned);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,100}$/', $returned);
    }

    public function test_unauthorized_requests_still_get_a_correlation_id(): void
    {
        $response = $this->postJson('/api/video-shots/reclaim-expired');

        $response->assertUnauthorized();
        $this->assertNotEmpty($response->headers->get('X-Correlation-Id'));
    }

    public function test_an_unhandled_exception_is_logged_with_correlation_id_and_still_returns_a_normal_response(): void
    {
        Log::spy();
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('storeFromPython')->once()->andThrow(new \RuntimeException('boom test'));
        $this->app->instance(VideoSessionService::class, $mock);

        $response = $this->withHeaders([
            'X-Video-Token' => 'correlation-test-token',
            'X-Correlation-Id' => 'crash-correlation-id',
        ])->postJson('/api/render-plans', [
            'project' => 'p', 'code' => 'code1', 'shots' => [[
                'shot_code' => 's1', 'kind' => 'motion', 'beat' => 'b1',
            ]],
        ]);

        $response->assertStatus(500);
        $this->assertSame('crash-correlation-id', $response->headers->get('X-Correlation-Id'));

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) {
                return $message === 'video_api_exception'
                    && ($context['correlation_id'] ?? null) === 'crash-correlation-id'
                    && $context['method'] === 'POST'
                    && $context['path'] === 'api/render-plans'
                    && $context['exception'] instanceof \RuntimeException;
            })
            ->once();
    }

    public function test_the_default_laravel_report_is_suppressed_to_avoid_double_logging(): void
    {
        Log::spy();
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('storeFromPython')->once()->andThrow(new \RuntimeException('boom no double log'));
        $this->app->instance(VideoSessionService::class, $mock);

        $this->withHeaders(['X-Video-Token' => 'correlation-test-token'])->postJson('/api/render-plans', [
            'project' => 'p', 'code' => 'code1', 'shots' => [[
                'shot_code' => 's1', 'kind' => 'motion', 'beat' => 'b1',
            ]],
        ]);

        Log::shouldHaveReceived('error')->once();
    }

    public function test_exception_log_does_not_include_the_request_body(): void
    {
        Log::spy();
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('storeFromPython')->once()->andThrow(new \RuntimeException('boom test'));
        $this->app->instance(VideoSessionService::class, $mock);

        $this->withHeaders(['X-Video-Token' => 'correlation-test-token'])->postJson('/api/render-plans', [
            'project' => 'p', 'code' => 'code1', 'shots' => [[
                'shot_code' => 's1', 'kind' => 'motion', 'beat' => 'b1',
                'compiled_prompt' => 'a secret prompt that must not leak into logs',
            ]],
        ]);

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) {
                $flat = strtolower(json_encode($context));

                return ! str_contains($flat, 'secret prompt') && ! array_key_exists('shots', $context);
            })
            ->once();
    }

    public function test_shot_id_route_param_is_captured_in_the_exception_log(): void
    {
        Log::spy();
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('reportShotResult')->once()->andThrow(new \RuntimeException('boom test'));
        $this->app->instance(VideoSessionService::class, $mock);

        $this->withHeaders(['X-Video-Token' => 'correlation-test-token'])
            ->patchJson('/api/video-shots/shot-abc/result', ['success' => true]);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $message === 'video_api_exception'
                && $context['shot_id'] === 'shot-abc')
            ->once();
    }

    private function isVideoApiRequest(string $path): bool
    {
        $method = new ReflectionMethod(Handler::class, 'isVideoApiRequest');
        $method->setAccessible(true);

        return $method->invoke(app(Handler::class), Request::create($path));
    }

    public function test_video_api_paths_are_recognized(): void
    {
        $this->assertTrue($this->isVideoApiRequest('/api/render-plans'));
        $this->assertTrue($this->isVideoApiRequest('/api/video-sessions/composing'));
        $this->assertTrue($this->isVideoApiRequest('/api/video-shots/abc/result'));
        $this->assertTrue($this->isVideoApiRequest('/api/video-finals/abc/result'));
    }

    public function test_non_video_api_paths_are_not_recognized(): void
    {
        $this->assertFalse($this->isVideoApiRequest('/api/posts'));
        $this->assertFalse($this->isVideoApiRequest('/admin/video-session'));
        $this->assertFalse($this->isVideoApiRequest('/nonexistent-route-xyz'));
    }
}
