<?php

namespace Tests\Feature\Video;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VideoPruneRunnerLogsCommandTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDir = storage_path('framework/testing/video-runner-logs-'.uniqid());
        mkdir($this->logDir, recursive: true);
        config(['video.runner.log_dir' => $this->logDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir.DIRECTORY_SEPARATOR.'*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($this->logDir);
        parent::tearDown();
    }

    private function putLogFile(string $name, int $mtime): string
    {
        $path = $this->logDir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, 'log');
        touch($path, $mtime);

        return $path;
    }

    public function test_deletes_matching_files_older_than_retention(): void
    {
        $old = $this->putLogFile('sess1_session_runner_20260101_120000.log', strtotime('-30 days'));

        $this->artisan('video:prune-runner-logs', ['--days' => 21])->assertExitCode(0);

        $this->assertFileDoesNotExist($old);
    }

    public function test_keeps_matching_files_within_retention(): void
    {
        $recent = $this->putLogFile('sess1_session_runner_20260101_120000.log', strtotime('-1 day'));

        $this->artisan('video:prune-runner-logs', ['--days' => 21]);

        $this->assertFileExists($recent);
    }

    public function test_keeps_old_files_that_do_not_match_the_runner_naming_pattern(): void
    {
        $unrelated = $this->putLogFile('readme.log', strtotime('-30 days'));

        $this->artisan('video:prune-runner-logs', ['--days' => 21]);

        $this->assertFileExists($unrelated);
    }

    public function test_option_days_overrides_the_configured_retention(): void
    {
        $file = $this->putLogFile('sess1_session_runner_20260101_120000.log', strtotime('-10 days'));

        $this->artisan('video:prune-runner-logs', ['--days' => 5])->assertExitCode(0);

        $this->assertFileDoesNotExist($file);
    }

    public function test_does_nothing_when_log_dir_is_missing(): void
    {
        config(['video.runner.log_dir' => $this->logDir.'/does-not-exist']);

        $this->artisan('video:prune-runner-logs')->assertExitCode(0);
    }

    public function test_does_nothing_when_log_dir_is_not_configured(): void
    {
        config(['video.runner.log_dir' => '']);

        $this->artisan('video:prune-runner-logs')->assertExitCode(0);
    }

    public function test_logs_a_summary_with_counts(): void
    {
        Log::spy();
        $this->putLogFile('sess1_session_runner_20260101_120000.log', strtotime('-30 days'));
        $this->putLogFile('sess2_session_runner_20260101_120000.log', strtotime('-1 day'));

        $this->artisan('video:prune-runner-logs', ['--days' => 21]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'video:prune-runner-logs: hoan tat'
                && $context['deleted'] === 1)
            ->once();
    }

    // ---- --days khong hop le: tu choi thay vi xoa nham tren cutoff sai (0/am -> cutoff = hien tai/tuong lai) ----

    public function test_rejects_zero_days_and_deletes_nothing(): void
    {
        $file = $this->putLogFile('sess1_session_runner_20260101_120000.log', strtotime('-30 days'));

        $this->artisan('video:prune-runner-logs', ['--days' => 0])->assertExitCode(1);

        $this->assertFileExists($file);
    }

    public function test_rejects_negative_days_and_deletes_nothing(): void
    {
        $file = $this->putLogFile('sess1_session_runner_20260101_120000.log', strtotime('-1 hour'));

        $this->artisan('video:prune-runner-logs', ['--days' => -5])->assertExitCode(1);

        $this->assertFileExists($file);
    }

    public function test_rejects_a_non_numeric_days_value_and_deletes_nothing(): void
    {
        $file = $this->putLogFile('sess1_session_runner_20260101_120000.log', strtotime('-30 days'));

        $this->artisan('video:prune-runner-logs', ['--days' => 'abc'])->assertExitCode(1);

        $this->assertFileExists($file);
    }

    public function test_rejects_a_misconfigured_retention_default_and_deletes_nothing(): void
    {
        config(['video.runner.log_retention_days' => 0]);
        $file = $this->putLogFile('sess1_session_runner_20260101_120000.log', strtotime('-30 days'));

        $this->artisan('video:prune-runner-logs')->assertExitCode(1);

        $this->assertFileExists($file);
    }
}
