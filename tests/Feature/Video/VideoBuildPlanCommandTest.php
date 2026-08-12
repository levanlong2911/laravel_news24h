<?php

namespace Tests\Feature\Video;

use App\Services\VideoSessionService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * `video:build-plan` phải MỎNG — chỉ đọc `--session=` rồi gọi đúng một hàm
 * service, không tự chứa logic. Test này khoá đúng hợp đồng đó, không đụng
 * DB/Claude thật (VideoSessionService bị mock hoàn toàn).
 */
class VideoBuildPlanCommandTest extends TestCase
{
    public function test_fails_fast_without_session_option(): void
    {
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldNotReceive('runVideoPlanningPipeline');
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:build-plan')->assertExitCode(1);
    }

    public function test_delegates_the_session_code_verbatim_and_succeeds(): void
    {
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('runVideoPlanningPipeline')->once()->with('art_abc123_260812_120000')->andReturn(true);
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:build-plan', ['--session' => 'art_abc123_260812_120000'])->assertExitCode(0);
    }

    public function test_returns_failure_exit_code_when_the_pipeline_reports_failure(): void
    {
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('runVideoPlanningPipeline')->once()->andReturn(false);
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:build-plan', ['--session' => 'art_xyz'])->assertExitCode(1);
    }

    public function test_logs_start_and_completion_with_session_code_on_success(): void
    {
        Log::spy();
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('runVideoPlanningPipeline')->once()->andReturn(true);
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:build-plan', ['--session' => 'art_abc']);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'video:build-plan: bat dau'
                && $context['code'] === 'art_abc')
            ->once();
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'video:build-plan: hoan tat'
                && $context['code'] === 'art_abc' && is_int($context['duration_ms']))
            ->once();
    }

    public function test_logs_failure_with_session_code_when_pipeline_reports_failure(): void
    {
        Log::spy();
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('runVideoPlanningPipeline')->once()->andReturn(false);
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:build-plan', ['--session' => 'art_abc']);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'video:build-plan: that bai'
                && $context['code'] === 'art_abc')
            ->once();
    }

    public function test_logs_the_exception_when_the_pipeline_throws(): void
    {
        Log::spy();
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('runVideoPlanningPipeline')->once()->andThrow(new \RuntimeException('boom'));
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:build-plan', ['--session' => 'art_abc'])->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => $message === 'video:build-plan: loi ngoai du kien'
                && $context['code'] === 'art_abc' && $context['exception'] instanceof \RuntimeException)
            ->once();
    }

    public function test_never_logs_a_prompt_or_token_field(): void
    {
        Log::spy();
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('runVideoPlanningPipeline')->once()->andReturn(true);
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:build-plan', ['--session' => 'art_abc']);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                $flat = strtolower(json_encode($context));

                return ! str_contains($flat, 'token') && ! str_contains($flat, 'prompt');
            })
            ->atLeast()->once();
    }
}
