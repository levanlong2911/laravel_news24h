<?php

namespace Tests\Feature\Video;

use App\Services\VideoSessionService;
use Mockery;
use Tests\TestCase;

/**
 * `video:build-plan` phải MỎNG — chỉ đọc `--session=` rồi gọi đúng một hàm
 * service, không tự chứa logic. Test này khoá đúng hợp đồng đó, không đụng
 * DB/Claude thật (VideoSessionService bị mock hoàn toàn).
 */
class VideoBuildPlanCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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
}
