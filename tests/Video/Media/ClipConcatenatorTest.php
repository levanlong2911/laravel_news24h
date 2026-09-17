<?php

declare(strict_types=1);

namespace Tests\Video\Media;

use App\Video\Media\ClipConcatenationResult;
use App\Video\Media\ClipConcatenator;
use App\Video\Media\FfmpegResult;
use App\Video\Media\FfmpegRunner;
use App\Video\Media\MediaProbe;
use App\Video\Media\Mp4Probe;
use App\Video\Media\StreamCompatibility;
use App\Video\Media\VideoFrameCounter;
use Tests\TestCase;

/**
 * Ghep clip: chinh sach tu choi, va thu tu tu choi.
 *
 * Khang dinh dat gia nhat o day la "metadata lech thi KHONG HE goi ffmpeg". No chi
 * kiem duoc nho moi noi `FfmpegRunner`: mot ban gia dem so lan duoc goi, va con so
 * phai la 0. Neu lop tu dung `Process` ben trong thi khang dinh nay khong the kiem.
 */
class ClipConcatenatorTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'concat_'.uniqid().'.mp4';
    }

    protected function tearDown(): void
    {
        @unlink($this->output);

        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/Video/'.$name);
    }

    private function skipWithoutFfprobe(): void
    {
        if (! app(Mp4Probe::class)->available()) {
            $this->markTestSkipped('may nay khong chay duoc ffprobe');
        }
    }

    private function concatenator(FfmpegRunner $runner, ?MediaProbe $probe = null): ClipConcatenator
    {
        return new ClipConcatenator(
            probe: $probe ?? app(Mp4Probe::class),
            ffmpeg: $runner,
            compatibility: app(StreamCompatibility::class),
            frames: app(VideoFrameCounter::class),
            tempRoot: sys_get_temp_dir(),
            durationToleranceMs: 250,
        );
    }

    /** Mot `FfmpegRunner` gia, dem so lan duoc goi. */
    private function countingRunner(?FfmpegResult $answer = null): FfmpegRunner
    {
        return new class($answer) implements FfmpegRunner
        {
            public int $calls = 0;

            public function __construct(private readonly ?FfmpegResult $answer) {}

            public function run(array $args, ?int $timeoutSeconds = null): FfmpegResult
            {
                $this->calls++;

                return $this->answer ?? new FfmpegResult(true, 0, '', '');
            }
        };
    }

    public function test_one_file_is_not_a_join(): void
    {
        $runner = $this->countingRunner();

        $result = $this->concatenator($runner)->concat([$this->fixture('pair_a.mp4')], $this->output);

        $this->assertFalse($result->successful);
        $this->assertSame(ClipConcatenator::NOT_ENOUGH_INPUT, $result->code);
        $this->assertSame(0, $runner->calls);
    }

    public function test_an_existing_output_is_refused_before_anything_happens(): void
    {
        touch($this->output);
        $runner = $this->countingRunner();

        $result = $this->concatenator($runner)->concat(
            [$this->fixture('pair_a.mp4'), $this->fixture('pair_b.mp4')], $this->output,
        );

        // Tu choi TRUOC khi lam gi: duoi kia co mot buoc xoa output khi that bai, va
        // neu file do la cua nguoi goi thi cai xoa ay huy mot thu ta khong tao ra.
        $this->assertFalse($result->successful);
        $this->assertSame(ClipConcatenator::OUTPUT_ALREADY_EXISTS, $result->code);
        $this->assertSame(0, $runner->calls);
        $this->assertFileExists($this->output);
    }

    public function test_mismatched_metadata_never_reaches_ffmpeg(): void
    {
        $this->skipWithoutFfprobe();

        $runner = $this->countingRunner();

        $result = $this->concatenator($runner)->concat(
            [$this->fixture('pair_a.mp4'), $this->fixture('mismatch.mp4')], $this->output,
        );

        // Day la ly do moi noi `FfmpegRunner` ton tai.
        $this->assertFalse($result->successful);
        $this->assertSame(StreamCompatibility::MISMATCH, $result->code);
        $this->assertSame(0, $runner->calls);
        $this->assertNotSame([], $result->reasons);
    }

    public function test_an_unreadable_file_is_refused_before_ffmpeg(): void
    {
        $this->skipWithoutFfprobe();

        $runner = $this->countingRunner();

        $result = $this->concatenator($runner)->concat(
            [$this->fixture('pair_a.mp4'), $this->fixture('khong-he-ton-tai.mp4')], $this->output,
        );

        $this->assertFalse($result->successful);
        $this->assertSame(StreamCompatibility::UNREADABLE, $result->code);
        $this->assertSame(0, $runner->calls);
    }

    public function test_a_failing_ffmpeg_is_reported_not_swallowed(): void
    {
        $this->skipWithoutFfprobe();

        $runner = $this->countingRunner(FfmpegResult::failed('khong ghi duoc output', 1));

        $result = $this->concatenator($runner)->concat(
            [$this->fixture('pair_a.mp4'), $this->fixture('pair_b.mp4')], $this->output,
        );

        $this->assertFalse($result->successful);
        $this->assertSame(ClipConcatenator::FFMPEG_FAILED, $result->code);
        $this->assertSame(1, $runner->calls);
    }

    public function test_a_zero_exit_with_no_output_file_is_still_a_failure(): void
    {
        $this->skipWithoutFfprobe();

        // ffmpeg tra 0 ma khong sinh ra file la chuyen co that. `successful` chi noi
        // ve tien trinh; noi goi phai DO LAI file.
        $runner = $this->countingRunner(new FfmpegResult(true, 0, '', ''));

        $result = $this->concatenator($runner)->concat(
            [$this->fixture('pair_a.mp4'), $this->fixture('pair_b.mp4')], $this->output,
        );

        $this->assertFalse($result->successful);
        $this->assertSame(ClipConcatenator::OUTPUT_UNUSABLE, $result->code);
    }

    public function test_a_real_join_keeps_every_frame(): void
    {
        $this->skipWithoutFfprobe();

        $result = $this->concatenator(app(FfmpegRunner::class))->concat(
            [$this->fixture('pair_a.mp4'), $this->fixture('pair_b.mp4')], $this->output,
        );

        $this->assertTrue($result->successful, implode(' / ', $result->reasons));

        // So khung moi la chot: `format.duration` bam theo audio, nen bo mot khung
        // hinh cung khong lam no nhuc nhich.
        $this->assertSame($result->expectedVideoFrames, $result->videoFrames);
        $this->assertNotNull($result->sha256);
        $this->assertGreaterThan(0, (int) $result->bytes);
        $this->assertFileExists($this->output);
    }

    public function test_the_output_is_removed_when_the_join_fails(): void
    {
        $this->skipWithoutFfprobe();

        // Mot runner gia ghi ra mot file rac roi bao thanh cong: output ton tai nhung
        // khong dung duoc, va no KHONG duoc phep o lai de bi tuong nham la ket qua.
        $output = $this->output;

        $runner = new class($output) implements FfmpegRunner
        {
            public function __construct(private readonly string $output) {}

            public function run(array $args, ?int $timeoutSeconds = null): FfmpegResult
            {
                file_put_contents($this->output, 'khong phai mp4');

                return new FfmpegResult(true, 0, '', '');
            }
        };

        $result = $this->concatenator($runner)->concat(
            [$this->fixture('pair_a.mp4'), $this->fixture('pair_b.mp4')], $output,
        );

        $this->assertFalse($result->successful);
        $this->assertFileDoesNotExist($output);
    }

    public function test_a_refusal_always_carries_a_reason(): void
    {
        $runner = $this->countingRunner();

        $result = $this->concatenator($runner)->concat([], $this->output);

        $this->assertInstanceOf(ClipConcatenationResult::class, $result);
        $this->assertFalse($result->successful);
        $this->assertNotSame([], $result->reasons);
        $this->assertNotNull($result->code);
    }
}
