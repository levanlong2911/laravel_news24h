<?php

namespace Tests\Feature\Video;

use App\Services\VideoSessionService;
use Mockery;
use Tests\TestCase;

/**
 * `video:reset-planning-claim` phải MỎNG — cùng quy ước với `video:build-plan`
 * (xem VideoBuildPlanCommandTest). Không đụng DB thật ở đây (VideoSessionService
 * bị mock hoàn toàn) — logic thật của resetPlanningClaim() đã có test riêng
 * trong VideoPlanningBackgroundTest.
 */
class VideoResetPlanningClaimCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fails_fast_without_session_option(): void
    {
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldNotReceive('resetPlanningClaim');
        $this->app->instance(VideoSessionService::class, $mock);

        // --force de khong dung o buoc xac nhan truoc khi kip kiem tra --session=.
        $this->artisan('video:reset-planning-claim', ['--force' => true])->assertExitCode(1);
    }

    public function test_delegates_the_session_code_verbatim_and_succeeds(): void
    {
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('resetPlanningClaim')->once()->with('art_abc123_260812_120000')->andReturn(true);
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:reset-planning-claim', [
            '--session' => 'art_abc123_260812_120000', '--force' => true,
        ])->assertExitCode(0);
    }

    public function test_returns_failure_exit_code_when_nothing_was_reset(): void
    {
        // Sai ma session, hoac session khong con o trang thai planning.
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('resetPlanningClaim')->once()->andReturn(false);
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:reset-planning-claim', ['--session' => 'art_xyz', '--force' => true])
            ->assertExitCode(1);
    }

    // ---- Xac nhan CO CHU DICH: chan bam nham lam mo claim khi tien trinh cu con song ----

    public function test_without_force_it_asks_for_confirmation_and_stops_when_denied(): void
    {
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldNotReceive('resetPlanningClaim');
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:reset-planning-claim', ['--session' => 'art_abc'])
            ->expectsConfirmation(
                'Ban da tu xac nhan (process list, log) tien trinh video:build-plan cu cho session art_abc DA CHET THAT chua?',
                'no',
            )
            ->assertExitCode(1);
    }

    public function test_without_force_it_proceeds_when_confirmed(): void
    {
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('resetPlanningClaim')->once()->with('art_abc')->andReturn(true);
        $this->app->instance(VideoSessionService::class, $mock);

        $this->artisan('video:reset-planning-claim', ['--session' => 'art_abc'])
            ->expectsConfirmation(
                'Ban da tu xac nhan (process list, log) tien trinh video:build-plan cu cho session art_abc DA CHET THAT chua?',
                'yes',
            )
            ->assertExitCode(0);
    }

    public function test_force_skips_the_confirmation_prompt_entirely(): void
    {
        $mock = Mockery::mock(VideoSessionService::class);
        $mock->shouldReceive('resetPlanningClaim')->once()->andReturn(true);
        $this->app->instance(VideoSessionService::class, $mock);

        // Khong goi expectsConfirmation(): neu command van hoi, PendingCommand
        // se nem loi vi khong co cau tra loi nao duoc chuan bi san — day chinh
        // la phep thu --force co that su bo qua buoc hoi hay khong.
        $this->artisan('video:reset-planning-claim', ['--session' => 'art_abc', '--force' => true])
            ->assertExitCode(0);
    }
}
