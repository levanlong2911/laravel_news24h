<?php

declare(strict_types=1);

namespace Tests\Video\Media;

use App\Video\Media\ClipConcatenator;
use App\Video\Media\FfmpegBinary;
use App\Video\Media\Mp4Probe;
use App\Video\Media\StreamCompatibility;
use App\Video\Media\VideoFrameCounter;
use Tests\TestCase;

/**
 * Do troi do dai khi ghep, va noi ro no KHONG chung minh duoc gi ve khung hinh.
 *
 * Nguong `concat_duration_tolerance_ms` chi co nghia neu biet troi that su la bao
 * nhieu va no co cong don theo so clip khong. Do that (ca fixture lan clip Veo 8
 * giay) cho ket qua giong nhau: troi la HANG SO, khong nhan len.
 *
 * Quan trong hon: test nay KHONG khang dinh "khong mat khung". `format.duration` bam
 * theo AUDIO — do that tren file da ghep: video 20.000000s, audio 20.021333s, format
 * 20.021333s. Bo mot khung video khong doi duration mot chut nao. Chot integrity do
 * thuoc ve `VideoFrameCounter`, va no duoc kiem o `ClipConcatenatorTest`.
 */
class VideoConcatDriftTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(FfmpegBinary::class)->available() || ! app(Mp4Probe::class)->available()) {
            $this->markTestSkipped('may nay khong chay duoc ffmpeg/ffprobe');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function concatenator(): ClipConcatenator
    {
        return new ClipConcatenator(
            probe: app(Mp4Probe::class),
            ffmpeg: app(FfmpegBinary::class),
            compatibility: new StreamCompatibility,
            frames: app(VideoFrameCounter::class),
            tempRoot: sys_get_temp_dir(),
            // Rong co y: test nay DO troi, khong kiem nguong.
            durationToleranceMs: 60_000,
        );
    }

    /** @return array{0: int, 1: int} [$driftMs, $frames] */
    private function joinTimes(int $count): array
    {
        $files = [];

        for ($i = 0; $i < $count; $i++) {
            $files[] = base_path('tests/Fixtures/Video/'.($i % 2 === 0 ? 'pair_a.mp4' : 'pair_b.mp4'));
        }

        $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drift_'.$count.'_'.uniqid().'.mp4';
        $this->written[] = $output;

        $result = $this->concatenator()->concat($files, $output);

        $this->assertTrue($result->successful, implode(' | ', $result->reasons));

        return [$result->durationMs - $result->expectedDurationMs, $result->videoFrames];
    }

    public function test_drift_does_not_grow_with_the_number_of_joins(): void
    {
        [$driftTwo] = $this->joinTimes(2);
        [$driftTwenty] = $this->joinTimes(20);

        // Neu troi cong don thi 19 moi noi phai lon hon han 1 moi noi. Do that: bang
        // nhau — vi chi co MOT duoi audio cho ca chuoi, khong phai mot cho moi noi.
        $this->assertLessThan(
            50,
            abs($driftTwenty - $driftTwo),
            sprintf('troi phai la hang so: 2 clip=%dms, 20 clip=%dms', $driftTwo, $driftTwenty),
        );
    }

    public function test_the_drift_is_about_one_audio_frame(): void
    {
        [$drift] = $this->joinTimes(5);

        // 1024 mau o 48kHz = 21,33ms. Neu con so nay nhay len hang tram thi mot thu
        // khac da doi, va nguong 250ms can duoc nhin lai.
        $this->assertLessThan(100, abs($drift), 'troi do duoc: '.$drift.'ms');
    }

    public function test_the_production_tolerance_has_room_over_what_was_measured(): void
    {
        [$drift] = $this->joinTimes(10);

        $tolerance = (int) config('video.veo.concat_duration_tolerance_ms');

        $this->assertGreaterThan(
            abs($drift) * 3,
            $tolerance,
            sprintf('nguong %dms phai rong hon han muc do duoc %dms', $tolerance, $drift),
        );
    }

    public function test_the_frame_count_is_what_proves_nothing_was_dropped(): void
    {
        [, $frames] = $this->joinTimes(10);

        // Doi chieu voi test tren: duration KHONG doi khi mat mot khung, con con so
        // nay thi doi ngay.
        $this->assertSame(240, $frames, '10 clip x 24 khung');
    }
}
