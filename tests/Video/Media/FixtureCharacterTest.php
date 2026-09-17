<?php

declare(strict_types=1);

namespace Tests\Video\Media;

use App\Video\Media\Mp4Probe;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Moi fixture phai MANG dung tinh chat no duoc dung ra de mang.
 *
 * Lop nay ton tai vi mot su that dat gia: toi da dung mot file VFR, do bang
 * `r_frame_rate != avg_frame_rate`, va no la CFR. Dau hieu khong phai bang chung.
 * Mot fixture mat tinh chat cua no se lam moi test dung no xanh mot cach vo nghia
 * — va no se im lang cho toi luc co nguoi truy mot loi khac.
 *
 * Dung lai bo fixture: tests/Fixtures/Video/build-fc.sh
 */
class FixtureCharacterTest extends TestCase
{
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

    /** @return array{out: string, err: string, code: ?int} */
    private function ffprobe(string ...$args): array
    {
        $process = new Process([(string) config('video.veo.ffprobe_bin'), '-v', 'error', ...$args]);
        $process->setTimeout(60);
        $process->run();

        return [
            'out' => $process->getOutput(),
            'err' => $process->getErrorOutput(),
            'code' => $process->getExitCode(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function readStreams(string $file, string $selector, string $entries): array
    {
        // File vang mat cung phai la MOT LOI, khong duoc thanh "khong co luong nao".
        $this->assertFileExists($file);

        $result = $this->ffprobe(
            '-select_streams', $selector, '-show_entries', 'stream='.$entries, '-of', 'json', $file,
        );

        $name = basename($file);

        $this->assertSame(0, $result['code'], 'ffprobe that bai tren '.$name);
        $this->assertSame('', trim($result['err']), 'ffprobe bao loi tren '.$name.': '.$result['err']);

        $json = json_decode($result['out'], true);

        $this->assertIsArray($json, 'ffprobe khong tra ve JSON tren '.$name);
        $this->assertArrayHasKey('streams', $json, $name);
        $this->assertIsArray($json['streams'], $name.': `streams` khong phai mang');

        // Loai bo phan tu sai cau truc la tu bit mat mot dau hieu. Bao ra.
        foreach ($json['streams'] as $index => $stream) {
            $this->assertIsArray($stream, $name.': luong thu '.$index.' khong phai mang');
        }

        return array_values($json['streams']);
    }

    /** @return array<string, string> dung MOT luong khop, khong hon */
    private function stream(string $file, string $selector, string $entries): array
    {
        $streams = $this->readStreams($file, $selector, $entries);

        $this->assertCount(1, $streams, basename($file).' phai co dung mot luong '.$selector);

        return array_map('strval', $streams[0]);
    }

    private function assertHasNoStream(string $file, string $selector): void
    {
        // Di qua `readStreams()` chu khong tu goi ffprobe: nho vay "khong co luong"
        // chi duoc ket luan SAU KHI file ton tai va ffprobe chay sach.
        $this->assertSame([], $this->readStreams($file, $selector, 'duration'), basename($file));
    }

    /**
     * Khoang cach giua cac khung, tinh bang TICK NGUYEN cua time_base luong.
     *
     * Khong dung `pts_time`: ffprobe doi no sang giay roi lam tron, va mot nhip deu
     * 1024/15360 hien ra thanh "66.666 va 66.667" — hai gia tri khac nhau cho mot
     * file hoan toan deu. Chinh cho do da danh lua toi mot lan.
     *
     * @return array<int, int> tick => so lan
     */
    private function frameDeltas(string $file): array
    {
        $this->assertFileExists($file);

        $result = $this->ffprobe(
            '-select_streams', 'v:0', '-show_entries', 'frame=pts', '-of', 'json', $file,
        );

        $name = basename($file);

        $this->assertSame(0, $result['code'], 'ffprobe that bai tren '.$name);
        $this->assertSame('', trim($result['err']), 'ffprobe bao loi tren '.$name.': '.$result['err']);

        $json = json_decode($result['out'], true);

        $this->assertIsArray($json, $name);
        $this->assertArrayHasKey('frames', $json, $name);
        $this->assertIsArray($json['frames'], $name.': `frames` khong phai mang');
        $this->assertNotSame([], $json['frames'], $name.' khong doc duoc khung nao');

        $pts = [];

        foreach ($json['frames'] as $index => $frame) {
            // Bo qua mot khung khong doc duoc la lam nhip dan ra gap doi o dung cho
            // do — tao ra mot "VFR" khong co that.
            $this->assertIsArray($frame, $name.': khung thu '.$index.' khong phai mang');
            $this->assertArrayHasKey('pts', $frame, $name.': khung thu '.$index.' khong co pts');

            $raw = $frame['pts'];

            $this->assertTrue(
                is_int($raw) || (is_string($raw) && ctype_digit($raw)),
                $name.': pts cua khung thu '.$index.' khong phai so nguyen',
            );

            $pts[] = (int) $raw;
        }

        sort($pts);
        $deltas = [];

        for ($i = 1; $i < count($pts); $i++) {
            $deltas[] = $pts[$i] - $pts[$i - 1];
        }

        $counted = array_count_values($deltas);
        ksort($counted);

        return $counted;
    }

    public function test_the_acceptance_pair_is_exactly_eight_seconds_at_twenty_four_fps(): void
    {
        $this->skipWithoutFfprobe();

        // 192 + 192 - 12 = 372. Ca nghiem thu cua muc 2 dung tren hai con so nay.
        foreach (['fc_a.mp4', 'fc_b.mp4'] as $name) {
            $file = $this->fixture($name);
            $video = $this->stream($file, 'v:0', 'duration,nb_frames,r_frame_rate,sample_aspect_ratio');
            $audio = $this->stream($file, 'a:0', 'duration,sample_rate,channels');

            $this->assertSame('8.000000', $video['duration'], $name);
            $this->assertSame('192', $video['nb_frames'], $name);
            $this->assertSame('24/1', $video['r_frame_rate'], $name);
            $this->assertSame('1:1', $video['sample_aspect_ratio'], $name);
            $this->assertSame('8.000000', $audio['duration'], $name);
            $this->assertSame('48000', $audio['sample_rate'], $name);
            $this->assertSame('2', $audio['channels'], $name);

            // Mot delta duy nhat = nhip deu. Cap nghiem thu phai la CFR.
            $this->assertCount(1, $this->frameDeltas($file), $name.' phai la CFR');
        }
    }

    public function test_the_thirty_fps_fixture_really_runs_at_thirty(): void
    {
        $this->skipWithoutFfprobe();

        $video = $this->stream($this->fixture('fc_30fps.mp4'), 'v:0', 'duration,nb_frames,r_frame_rate');

        // Nguon khac FPS dau ra la ca ma `trim=end_frame` de bi dat sai cho nhat.
        $this->assertSame('30/1', $video['r_frame_rate']);
        $this->assertSame('120', $video['nb_frames']);
        $this->assertSame('4.000000', $video['duration']);
    }

    public function test_the_variable_rate_fixture_really_varies(): void
    {
        $this->skipWithoutFfprobe();

        $file = $this->fixture('fc_vfr.mp4');
        $video = $this->stream($file, 'v:0', 'nb_frames,time_base');

        $this->assertSame('90', $video['nb_frames']);
        $this->assertSame('1/15360', $video['time_base']);

        // Ghim ca PHAN BO da do, khong chi "co nhieu hon mot gia tri": mot ban dung
        // lai cho ra nhip khac la phai bao, chu khong phai im lang van xanh.
        $this->assertSame([512 => 30, 1024 => 59], $this->frameDeltas($file));
    }

    public function test_the_stretched_pixel_fixture_is_not_square(): void
    {
        $this->skipWithoutFfprobe();

        $this->assertSame(
            '2:1',
            $this->stream($this->fixture('fc_sar21.mp4'), 'v:0', 'sample_aspect_ratio')['sample_aspect_ratio'],
        );
    }

    public function test_the_mismatched_fixtures_really_mismatch(): void
    {
        $this->skipWithoutFfprobe();

        foreach ([
            'fc_audio_longer.mp4' => ['4.000000', '6.000000'],
            'fc_audio_shorter.mp4' => ['6.000000', '2.000000'],
        ] as $name => [$video, $audio]) {
            $file = $this->fixture($name);

            $this->assertSame($video, $this->stream($file, 'v:0', 'duration')['duration'], $name);
            $this->assertSame($audio, $this->stream($file, 'a:0', 'duration')['duration'], $name);
        }

        $this->assertHasNoStream($this->fixture('fc_no_audio.mp4'), 'a:0');
        $this->assertHasNoStream($this->fixture('fc_vfr.mp4'), 'a:0');
    }

    public function test_the_offset_fixture_starts_its_audio_after_its_video(): void
    {
        $this->skipWithoutFfprobe();

        $file = $this->fixture('fc_offset.mp4');
        $video = (float) $this->stream($file, 'v:0', 'start_time')['start_time'];
        $audio = (float) $this->stream($file, 'a:0', 'start_time')['start_time'];

        $this->assertGreaterThan(0.0, $video, 'video phai bat dau khac 0');

        // CHIEU cua do lech la mot phan cua fixture: script tiem `-itsoffset 0.5`
        // cho tieng, nen tieng phai den SAU. Dung `abs()` thi mot ban dung lai lam
        // tieng den truoc van di qua, va chinh sach giu do lech khong con duoc kiem.
        $this->assertGreaterThan($video, $audio, 'tieng phai bat dau sau hinh');
        $this->assertGreaterThan(0.4, $audio - $video);
        $this->assertLessThan(0.6, $audio - $video);
    }

    public function test_the_broken_fixture_cannot_be_read_at_all(): void
    {
        $this->skipWithoutFfprobe();

        $file = $this->fixture('fc_broken.mp4');

        // File mat cung cho `ok = false` va `error != null` — khong co dong nay thi
        // test xanh ca khi fixture khong con ton tai.
        $this->assertFileExists($file);

        $probe = app(Mp4Probe::class)->inspect($file);

        $this->assertFalse($probe['ok']);
        $this->assertNotNull($probe['error']);
    }

    public function test_the_truncated_fixture_makes_ffprobe_succeed_while_complaining(): void
    {
        $this->skipWithoutFfprobe();

        $file = $this->fixture('fc_truncated.mp4');

        $this->assertFileExists($file);

        $result = $this->ffprobe(
            '-count_frames', '-select_streams', 'v:0', '-print_format', 'json',
            '-show_entries', 'stream=nb_read_frames', $file,
        );

        // Day la ca duy nhat chung minh duoc lo hong cua `VideoFrameCounter`: ma
        // thoat 0, mot con so hop le, va mot dong stderr bi vut di.
        $this->assertSame(0, $result['code']);
        $this->assertNotSame('', trim($result['err']));

        $json = json_decode($result['out'], true);

        $this->assertIsArray($json);

        $frames = $json['streams'][0]['nb_read_frames'] ?? null;

        $this->assertIsString($frames);
        $this->assertGreaterThan(0, (int) $frames);
    }
}
