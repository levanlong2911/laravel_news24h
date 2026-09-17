<?php

declare(strict_types=1);

namespace Tests\Video\Media;

use App\Video\Media\MediaProbe;
use App\Video\Media\Mp4Probe;
use Tests\TestCase;

/**
 * `Mp4Probe` MO TA file, khong phan xet no.
 *
 * Lop nay con duoc duong clip DA TRA TIEN dung (`RenderCheckpointService`,
 * `VideoRenderExecutionService`), nen hai thu phai dung: ba khoa cu khong duoc doi,
 * va chinh sach cua viec ghep clip khong duoc len day.
 */
class Mp4ProbeTest extends TestCase
{
    private function probe(): Mp4Probe
    {
        return new Mp4Probe((string) config('video.veo.ffprobe_bin'));
    }

    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/Video/'.$name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->probe()->available()) {
            $this->markTestSkipped('may nay khong chay duoc ffprobe');
        }
    }

    public function test_it_is_the_media_probe_the_rest_of_the_code_asks_for(): void
    {
        $this->assertInstanceOf(MediaProbe::class, $this->probe());
    }

    public function test_the_three_keys_the_paid_clip_path_reads_keep_their_shape(): void
    {
        $result = $this->probe()->inspect($this->fixture('pair_a.mp4'));

        // RenderCheckpointService:400 va VideoRenderExecutionService:402 doc dung ba
        // khoa nay de doi chieu manifest. Doi hinh dang cua chung la dung vao duong
        // da tra tien.
        $this->assertTrue($result['ok']);
        $this->assertIsInt($result['duration_ms']);
        $this->assertSame(64, $result['width']);
        $this->assertSame(64, $result['height']);
        $this->assertNull($result['error']);
    }

    public function test_it_reads_every_field_a_strict_copy_decision_needs(): void
    {
        $video = $this->probe()->inspect($this->fixture('pair_a.mp4'))['video'];

        $this->assertSame('h264', $video['codec_name']);
        $this->assertSame('High', $video['profile']);
        $this->assertSame(31, $video['level']);
        $this->assertSame('yuv420p', $video['pix_fmt']);
        $this->assertSame('24/1', $video['r_frame_rate']);
        $this->assertSame('24/1', $video['avg_frame_rate']);
        $this->assertSame('avc1', $video['codec_tag_string']);
        $this->assertMatchesRegularExpression('/^SHA256:[a-f0-9]{64}$/', $video['extradata_hash']);
        $this->assertNotNull($video['time_base']);
    }

    public function test_it_reads_the_audio_stream_that_every_veo_clip_carries(): void
    {
        $result = $this->probe()->inspect($this->fixture('pair_a.mp4'));

        // Ban cu cua probe khong hoi gi ve audio. Ghep ma khong biet audio la ra file
        // mat tieng hoac lech tieng, va khong co gi bao.
        $this->assertSame(1, $result['audio_count']);
        $this->assertSame('aac', $result['audio']['codec_name']);
        $this->assertSame(48000, $result['audio']['sample_rate']);
        $this->assertSame(2, $result['audio']['channels']);
        $this->assertSame('stereo', $result['audio']['channel_layout']);
        $this->assertMatchesRegularExpression('/^SHA256:/', $result['audio']['extradata_hash']);
    }

    public function test_a_field_ffprobe_never_wrote_comes_back_as_null(): void
    {
        $video = $this->probe()->inspect($this->fixture('pair_a.mp4'))['video'];

        // ffprobe BO QUA truong chua duoc dat thay vi ghi rong. Coi vang mat la loi
        // thi moi clip Veo deu bi tu choi — ca ba clip that deu khong co may truong
        // mau nay.
        $this->assertNull($video['color_transfer']);
        $this->assertNull($video['color_primaries']);
        $this->assertNull($video['rotation']);
    }

    public function test_it_counts_the_streams_and_names_them_for_a_diagnostic(): void
    {
        $result = $this->probe()->inspect($this->fixture('pair_a.mp4'));

        $this->assertSame(1, $result['video_count']);
        $this->assertSame(1, $result['audio_count']);
        $this->assertSame(0, $result['other_count']);
        $this->assertSame(
            [['index' => 0, 'codec_type' => 'video'], ['index' => 1, 'codec_type' => 'audio']],
            $result['streams'],
            'danh sach nay de thong bao loi doc duoc "data stream at index 2", '
            .'khong chi mot con dem',
        );
    }

    public function test_two_files_built_the_same_way_agree_on_every_normalised_field(): void
    {
        $a = $this->probe()->inspect($this->fixture('pair_a.mp4'));
        $b = $this->probe()->inspect($this->fixture('pair_b.mp4'));

        // So NGUYEN MANG chu khong liet ke tung truong: them mot truong moi vao
        // `videoStream()` ma quen so no thi test nay do ngay, khong cho ai phai nho
        // cap nhat mot danh sach song song.
        $this->assertSame($a['video'], $b['video']);
        $this->assertSame($a['audio'], $b['audio']);

        $this->assertNotSame(
            sha1_file($this->fixture('pair_a.mp4')),
            sha1_file($this->fixture('pair_b.mp4')),
            'hai fixture phai KHAC BYTES, neu khong phep so tren khong chung minh gi',
        );
    }

    public function test_a_file_built_at_another_frame_rate_reports_a_different_fingerprint(): void
    {
        $a = $this->probe()->inspect($this->fixture('pair_a.mp4'))['video'];
        $m = $this->probe()->inspect($this->fixture('mismatch.mp4'))['video'];

        $this->assertSame('30/1', $m['r_frame_rate']);
        $this->assertNotSame($a['extradata_hash'], $m['extradata_hash'], 'fps nam trong SPS');
    }

    public function test_a_file_that_is_not_media_fails_with_every_key_still_present(): void
    {
        $result = $this->probe()->inspect($this->fixture('concept_v16_before_phase_two.json'));

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);

        // Hinh dang tra ve phai DONG NHAT o ca hai nhanh, neu khong nguoi goi phai
        // nho kiem `ok` truoc moi khoa moi.
        foreach (['duration_ms', 'width', 'height', 'video', 'audio'] as $key) {
            $this->assertNull($result[$key], $key.' phai la null o nhanh that bai');
        }

        $this->assertSame(0, $result['video_count']);
        $this->assertSame(0, $result['audio_count']);
        $this->assertSame(0, $result['other_count']);
        $this->assertSame([], $result['streams']);
    }

    public function test_a_missing_file_is_a_failure_not_an_exception(): void
    {
        $result = $this->probe()->inspect($this->fixture('khong-he-ton-tai.mp4'));

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }
}
