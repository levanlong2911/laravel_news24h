<?php

declare(strict_types=1);

namespace Tests\Video\Media;

use App\Video\Media\Mp4Probe;
use App\Video\Media\VideoFrameCounter;
use Tests\TestCase;

/**
 * Phep dem khung la CHOT INTEGRITY cua viec ghep.
 *
 * `format.duration` khong thay duoc mat khung — no bam theo audio. Neu phep dem nay
 * tra ve mot con so sai ma van bao "ok", thi cai chot ay bien mat ma khong ai biet.
 */
class VideoFrameCounterTest extends TestCase
{
    private function counter(): VideoFrameCounter
    {
        return new VideoFrameCounter((string) config('video.veo.ffprobe_bin'));
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

    public function test_it_counts_the_frames_in_a_real_file(): void
    {
        $this->skipWithoutFfprobe();

        $result = $this->counter()->count($this->fixture('pair_a.mp4'));

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame(24, $result->frames);
        $this->assertNull($result->error);
    }

    public function test_a_file_that_is_not_media_is_a_failure_not_a_zero(): void
    {
        $this->skipWithoutFfprobe();

        $result = $this->counter()->count($this->fixture('concept_v16_before_phase_two.json'));

        $this->assertFalse($result->ok);
        $this->assertNull($result->frames);
        $this->assertNotNull($result->error);
    }

    public function test_a_missing_file_is_a_failure_not_a_zero(): void
    {
        $this->skipWithoutFfprobe();

        $result = $this->counter()->count($this->fixture('khong-he-ton-tai.mp4'));

        $this->assertFalse($result->ok);
        $this->assertNull($result->frames);
    }
}
