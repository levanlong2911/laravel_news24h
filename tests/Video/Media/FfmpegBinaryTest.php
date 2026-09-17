<?php

declare(strict_types=1);

namespace Tests\Video\Media;

use App\Video\Media\FfmpegBinary;
use App\Video\Media\FfmpegResult;
use App\Video\Media\FfmpegRunner;
use Tests\TestCase;

/**
 * Cong toi ffmpeg: giai duong dan, va noi duoc ly do khi khong chay duoc.
 *
 * Phan giai duong dan khong dung toi dia nen chay duoc o moi may. Phan chay that
 * thi skip khi may khong co ffmpeg.
 */
class FfmpegBinaryTest extends TestCase
{
    public function test_an_explicit_setting_always_wins(): void
    {
        [$binary, $source] = FfmpegBinary::resolve('/opt/custom/ffmpeg', '/usr/bin/ffprobe');

        $this->assertSame('/opt/custom/ffmpeg', $binary);
        $this->assertSame(FfmpegBinary::SOURCE_CONFIGURED, $source);
    }

    public function test_a_concrete_ffprobe_path_gives_its_neighbour(): void
    {
        [$binary, $source] = FfmpegBinary::resolve(null, '/usr/local/bin/ffprobe');

        $this->assertSame('/usr/local/bin/ffmpeg', $binary);
        $this->assertSame(FfmpegBinary::SOURCE_DERIVED, $source);
    }

    public function test_it_keeps_the_windows_extension(): void
    {
        [$binary] = FfmpegBinary::resolve(null, 'C:/tools/ffmpeg/bin/ffprobe.exe');

        $this->assertSame('C:/tools/ffmpeg/bin/ffmpeg.exe', $binary);
    }

    public function test_a_bare_name_stays_a_bare_name(): void
    {
        // Lam phau thuat chuoi tren mot ten tran se de ra mot duong dan khong ton tai,
        // roi `problem()` bao sai cho — nguoi doc di tim mot thu muc khong he duoc cau
        // hinh o dau ca.
        [$binary, $source] = FfmpegBinary::resolve(null, 'ffprobe');

        $this->assertSame('ffmpeg', $binary);
        $this->assertSame(FfmpegBinary::SOURCE_DERIVED, $source);
    }

    public function test_an_empty_setting_counts_as_absent(): void
    {
        [$binary] = FfmpegBinary::resolve('   ', '/usr/bin/ffprobe');

        $this->assertSame('/usr/bin/ffmpeg', $binary);
    }

    public function test_a_binary_that_is_not_there_reports_where_it_looked(): void
    {
        $ffmpeg = new FfmpegBinary('/khong/he/ton/tai/ffmpeg', FfmpegBinary::SOURCE_DERIVED, 5);

        $problem = $ffmpeg->problem();

        $this->assertNotNull($problem);
        $this->assertStringContainsString('/khong/he/ton/tai/ffmpeg', $problem);
        $this->assertStringContainsString(FfmpegBinary::SOURCE_DERIVED, $problem);
        $this->assertStringContainsString('VIDEO_FFMPEG_BIN', $problem, 'phai noi bien nao can sua');
        $this->assertNull($ffmpeg->version());
    }

    public function test_it_is_the_runner_the_rest_of_the_code_asks_for(): void
    {
        $this->assertInstanceOf(
            FfmpegRunner::class,
            new FfmpegBinary('ffmpeg', FfmpegBinary::SOURCE_DERIVED),
        );
    }

    public function test_a_failure_result_carries_the_same_shape_as_a_real_run(): void
    {
        // Ban gia trong test phai cung hop dong voi tien trinh that, neu khong cai
        // test chung minh duoc chi la ban gia cua chinh no.
        $result = FfmpegResult::failed('boom');

        $this->assertFalse($result->successful);
        $this->assertNull($result->exitCode);
        $this->assertSame('', $result->stdout);
        $this->assertSame('boom', $result->stderr);
        $this->assertSame('boom', $result->tail());
    }

    public function test_the_tail_keeps_the_end_because_ffmpeg_says_why_at_the_end(): void
    {
        $result = FfmpegResult::failed(str_repeat('a', 100).'NGUYEN NHAN');

        $this->assertStringEndsWith('NGUYEN NHAN', $result->tail(20));
        $this->assertStringStartsWith('…', $result->tail(20));
    }

    public function test_the_real_binary_runs_and_reports_a_version(): void
    {
        $ffmpeg = app(FfmpegBinary::class);

        if (! $ffmpeg->available()) {
            $this->markTestSkipped('may nay khong chay duoc ffmpeg');
        }

        $this->assertStringContainsString('ffmpeg version', (string) $ffmpeg->version());
        $this->assertNull($ffmpeg->problem());
    }
}
