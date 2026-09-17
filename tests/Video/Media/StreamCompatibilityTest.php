<?php

declare(strict_types=1);

namespace Tests\Video\Media;

use App\Video\Media\StreamCompatibility;
use Tests\TestCase;

/**
 * Toan bo chinh sach ghep copy nam o day — va CHI o day.
 *
 * `Mp4Probe` con duoc duong clip da tra tien dung, nen mot luat sinh ra cho viec
 * ghep khong duoc leo len do.
 */
class StreamCompatibilityTest extends TestCase
{
    private function policy(): StreamCompatibility
    {
        return new StreamCompatibility;
    }

    /** @param array<string, mixed> $override */
    private function probe(array $override = []): array
    {
        return array_replace([
            'ok' => true,
            'duration_ms' => 1000,
            'width' => 64,
            'height' => 64,
            'error' => null,
            'video_count' => 1,
            'audio_count' => 1,
            'other_count' => 0,
            'streams' => [
                ['index' => 0, 'codec_type' => 'video'],
                ['index' => 1, 'codec_type' => 'audio'],
            ],
            'video' => [
                'codec_name' => 'h264', 'profile' => 'High', 'level' => 31,
                'pix_fmt' => 'yuv420p', 'r_frame_rate' => '24/1', 'avg_frame_rate' => '24/1',
                'time_base' => '1/12288', 'width' => 64, 'height' => 64,
                'codec_tag_string' => 'avc1', 'extradata_hash' => 'SHA256:aaa',
                'sample_aspect_ratio' => null, 'color_range' => null, 'color_space' => null,
                'color_transfer' => null, 'color_primaries' => null,
                'field_order' => 'progressive', 'rotation' => null,
            ],
            'audio' => [
                'codec_name' => 'aac', 'profile' => 'LC', 'sample_rate' => 48000,
                'channels' => 2, 'channel_layout' => 'stereo', 'sample_fmt' => 'fltp',
                'time_base' => '1/48000', 'codec_tag_string' => 'mp4a',
                'extradata_hash' => 'SHA256:bbb',
            ],
        ], $override);
    }

    public function test_a_file_that_could_not_be_read_is_not_a_layout_problem(): void
    {
        // Hai thu khac nhau bao nguoi chay lam hai viec khac nhau: SUA file, hay CHON
        // clip khac. Gop chung mot ma loi la bat ho doan.
        $verdict = $this->policy()->unusable(
            ['ok' => false, 'error' => 'ffprobe that bai'], 'clip 1',
        );

        $this->assertFalse($verdict->accepted);
        $this->assertSame(StreamCompatibility::UNREADABLE, $verdict->code);
    }

    public function test_two_video_streams_are_a_layout_problem(): void
    {
        $verdict = $this->policy()->unusable($this->probe([
            'video_count' => 2,
            'streams' => [
                ['index' => 0, 'codec_type' => 'video'],
                ['index' => 1, 'codec_type' => 'video'],
            ],
        ]), 'clip 1');

        $this->assertSame(StreamCompatibility::UNSUPPORTED_LAYOUT, $verdict->code);
        $this->assertStringContainsString('can dung 1 luong video, co 2', $verdict->reasons[0]);
    }

    public function test_a_side_stream_is_named_with_its_index(): void
    {
        $verdict = $this->policy()->unusable($this->probe([
            'other_count' => 1,
            'streams' => [
                ['index' => 0, 'codec_type' => 'video'],
                ['index' => 1, 'codec_type' => 'audio'],
                ['index' => 2, 'codec_type' => 'data'],
            ],
        ]), 'clip 3');

        $this->assertSame(StreamCompatibility::UNSUPPORTED_LAYOUT, $verdict->code);
        $this->assertStringContainsString('luong data o index 2', implode(' ', $verdict->reasons),
            'mot con dem khong du de chan — nguoi doc phai biet luong nao, o dau');
    }

    public function test_a_missing_required_video_field_is_incomplete_not_a_match(): void
    {
        $probe = $this->probe();
        $probe['video']['extradata_hash'] = null;

        $verdict = $this->policy()->unusable($probe, 'clip 1');

        $this->assertSame(StreamCompatibility::INCOMPLETE, $verdict->code);
        $this->assertStringContainsString('video thieu `extradata_hash`', $verdict->reasons[0]);
    }

    public function test_audio_without_a_profile_is_still_usable(): void
    {
        // Nhieu codec hop le khong he co khai niem `profile` — PCM chang han. Bat buoc
        // no thi mot luong audio dung bi bao "thieu truong" cho mot thu khong ton tai.
        $probe = $this->probe();
        $probe['audio']['profile'] = null;

        $this->assertTrue($this->policy()->unusable($probe, 'clip 1')->accepted);
    }

    public function test_a_file_with_no_audio_at_all_is_usable(): void
    {
        $verdict = $this->policy()->unusable($this->probe([
            'audio' => null,
            'audio_count' => 0,
            'streams' => [['index' => 0, 'codec_type' => 'video']],
        ]), 'clip 1');

        $this->assertTrue($verdict->accepted);
    }

    public function test_two_identical_files_may_be_joined(): void
    {
        $verdict = $this->policy()->differences($this->probe(), $this->probe(), 'clip 1', 'clip 2');

        $this->assertTrue($verdict->accepted);
        $this->assertSame([], $verdict->reasons);
    }

    public function test_a_different_frame_rate_names_both_frame_rate_fields(): void
    {
        $other = $this->probe();
        $other['video']['r_frame_rate'] = '30/1';
        $other['video']['avg_frame_rate'] = '30/1';

        $verdict = $this->policy()->differences($this->probe(), $other, 'clip 1', 'clip 2');

        $this->assertSame(StreamCompatibility::MISMATCH, $verdict->code);
        $reasons = implode(' ', $verdict->reasons);
        $this->assertStringContainsString('video.r_frame_rate', $reasons);
        $this->assertStringContainsString('video.avg_frame_rate', $reasons);
    }

    public function test_audio_on_one_side_only_is_a_mismatch(): void
    {
        $silent = $this->probe(['audio' => null, 'audio_count' => 0]);

        $verdict = $this->policy()->differences($this->probe(), $silent, 'clip 1', 'clip 2');

        $this->assertSame(StreamCompatibility::MISMATCH, $verdict->code);
        $this->assertStringContainsString('audio:', implode(' ', $verdict->reasons));
    }

    public function test_two_ways_of_writing_the_same_rotation_are_still_refused(): void
    {
        // KHONG doan tuong duong. Doan sai thi ra mot video xoay nguoc, va khong co
        // gi bao cho toi luc co nguoi mo file ra xem.
        $a = $this->probe();
        $a['video']['rotation'] = '90';

        $b = $this->probe();
        $b['video']['rotation'] = 'displaymatrix: rotation of -90.00 degrees';

        $verdict = $this->policy()->differences($a, $b, 'clip 1', 'clip 2');

        $this->assertSame(StreamCompatibility::MISMATCH, $verdict->code);
        $this->assertStringContainsString('video.rotation', implode(' ', $verdict->reasons));
    }

    public function test_an_optional_field_absent_on_both_sides_matches(): void
    {
        $this->assertTrue(
            $this->policy()->differences($this->probe(), $this->probe(), 'a', 'b')->accepted,
            'ffprobe BO QUA truong chua duoc dat — coi vang mat la lech thi khong file nao ghep duoc',
        );
    }

    public function test_an_optional_field_present_on_one_side_only_is_a_mismatch(): void
    {
        $other = $this->probe();
        $other['video']['color_space'] = 'bt709';

        $verdict = $this->policy()->differences($this->probe(), $other, 'clip 1', 'clip 2');

        $this->assertSame(StreamCompatibility::MISMATCH, $verdict->code);
        $this->assertStringContainsString('video.color_space', implode(' ', $verdict->reasons));
    }
}
