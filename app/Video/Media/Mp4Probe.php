<?php

namespace App\Video\Media;

use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Do that thong so cua file video bang ffprobe.
 *
 * Duration nguoi dung chon KHONG duoc dung lam bang chung file hop le — no chi la
 * ky vong de doi chieu. Ca duration, width va height deu lay tu day.
 *
 * Lop nay MO TA file, khong phan xet no. "Bao nhieu luong thi chap nhan duoc" va
 * "truong nao bat buoc" la chinh sach cua viec GHEP CLIP, va `Mp4Probe` con duoc
 * duong clip da tra tien dung — dat chinh sach ghep vao day thi mot clip Veo co
 * them mot luong data se hong o chang checkpoint vi mot luat sinh ra cho viec khac.
 *
 * Ngoai le duy nhat la tinh HOP LE CUA FILE: khong co luong video nao, khong doc
 * duoc duration, hoac kich thuoc bang khong thi day khong phai video dung duoc, va
 * do la su that ve file chu khong phai chinh sach.
 */
final class Mp4Probe implements MediaProbe
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeoutSeconds = 20,
    ) {}

    public function available(): bool
    {
        return $this->problem() === null;
    }

    /**
     * Ly do KHONG chay duoc, hoac `null` neu chay duoc.
     *
     * Tra ve ly do chu khong chi true/false, vi loi hay gap nhat khong phai "may
     * thieu ffmpeg" ma la "tien trinh web co PATH khac shell": go `ffprobe` trong
     * terminal thi thay, ma PHP thi khong. Mot cau "khong chay duoc" khong du de
     * ai do biet phai lam gi tiep.
     */
    public function problem(): ?string
    {
        $process = new Process([$this->binary, '-version']);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            return $this->hint(Str::limit($e->getMessage(), 160));
        }

        if (! $process->isSuccessful()) {
            return $this->hint(Str::limit($process->getErrorOutput(), 160));
        }

        return null;
    }

    private function hint(string $detail): string
    {
        $named = str_contains($this->binary, DIRECTORY_SEPARATOR) || str_contains($this->binary, '/');

        return sprintf(
            'khong chay duoc "%s" (%s).%s',
            $this->binary,
            $detail !== '' ? $detail : 'khong ro nguyen nhan',
            $named
                ? ' Kiem lai duong dan trong VIDEO_FFPROBE_BIN.'
                : ' Tien trinh web co the co PATH khac terminal — dat VIDEO_FFPROBE_BIN'
                    .' bang duong dan tuyet doi toi ffprobe roi khoi dong lai may chu.',
        );
    }

    /**
     * `-show_streams` nguyen ve chu khong loc bang `-show_entries`.
     *
     * Ban day du cua mot clip chi khoang 2,5 KB, con cu phap `-show_entries` cho
     * `side_data` rat de viet sai va khi sai thi no im lang tra ve thieu truong —
     * dung kieu hong ma chi phat hien ra luc da ghep xong.
     *
     * @return array{ok: bool, duration_ms: ?int, width: ?int, height: ?int, error: ?string,
     *               video: ?array<string, mixed>, audio: ?array<string, mixed>,
     *               video_count: int, audio_count: int, other_count: int,
     *               streams: list<array{index: int, codec_type: string}>}
     */
    public function inspect(string $file, ?int $timeoutSeconds = null): array
    {
        $process = new Process([
            $this->binary, '-v', 'error', '-print_format', 'json',
            '-show_data_hash', 'SHA256', '-show_format', '-show_streams', $file,
        ]);
        // Mot luot ghep co ngan sach cho CA LUOT. Probe tung clip bang han muc rieng
        // cua no thi tong thoi gian van vuot du moi lan goi deu "trong han".
        $process->setTimeout($timeoutSeconds ?? $this->timeoutSeconds);

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            return $this->fail('khong chay duoc ffprobe: '.Str::limit($e->getMessage(), 200));
        }

        if (! $process->isSuccessful()) {
            return $this->fail('ffprobe that bai: '.Str::limit($process->getErrorOutput(), 200));
        }

        $json = json_decode($process->getOutput(), true);

        if (! is_array($json)) {
            return $this->fail('ffprobe tra ve khong phai JSON');
        }

        $raw = $json['format']['duration'] ?? null;
        $seconds = is_numeric($raw) ? (float) $raw : null;

        if ($seconds === null || ! is_finite($seconds) || $seconds <= 0) {
            return $this->fail('ffprobe khong doc duoc duration hop le');
        }

        $streams = array_values(array_filter((array) ($json['streams'] ?? []), 'is_array'));

        $inventory = [];
        $videos = [];
        $audios = [];
        $others = 0;

        foreach ($streams as $position => $stream) {
            $type = (string) ($stream['codec_type'] ?? '');
            $index = isset($stream['index']) ? (int) $stream['index'] : $position;

            $inventory[] = ['index' => $index, 'codec_type' => $type !== '' ? $type : 'unknown'];

            match ($type) {
                'video' => $videos[] = $stream,
                'audio' => $audios[] = $stream,
                default => $others++,
            };
        }

        if ($videos === []) {
            return $this->fail('file khong co luong video nao');
        }

        $width = (int) ($videos[0]['width'] ?? 0);
        $height = (int) ($videos[0]['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return $this->fail('luong video khong co kich thuoc hop le');
        }

        return [
            'ok' => true,
            'duration_ms' => (int) round($seconds * 1000),
            'width' => $width,
            'height' => $height,
            'error' => null,
            // `null` khi KHONG phai dung mot luong: nguoi doc khong bao gio vo tinh
            // so nham mot luong tuy y trong nhieu luong. So luong that nam o
            // `video_count`/`audio_count`, va chinh sach doc no de tu choi.
            'video' => count($videos) === 1 ? $this->videoStream($videos[0]) : null,
            'audio' => count($audios) === 1 ? $this->audioStream($audios[0]) : null,
            'video_count' => count($videos),
            'audio_count' => count($audios),
            'other_count' => $others,
            'streams' => $inventory,
        ];
    }

    /**
     * @param  array<string, mixed>  $stream
     * @return array<string, mixed>
     */
    private function videoStream(array $stream): array
    {
        return [
            'codec_name' => $this->text($stream, 'codec_name'),
            'profile' => $this->text($stream, 'profile'),
            'level' => isset($stream['level']) ? (int) $stream['level'] : null,
            // Thoi gian cua CHINH luong hinh, khong phai cua container.
            // `format.duration` bam theo luong dai hon — do that: mot file hinh 4s
            // tieng 6s bao `format.duration = 6.000000`. Ai cat hinh theo con so do
            // se doi mot khoang khong ton tai.
            'duration' => $this->number($stream, 'duration'),
            'start_time' => $this->number($stream, 'start_time'),
            'nb_frames' => isset($stream['nb_frames']) ? (int) $stream['nb_frames'] : null,
            'pix_fmt' => $this->text($stream, 'pix_fmt'),
            'r_frame_rate' => $this->text($stream, 'r_frame_rate'),
            // `r_frame_rate` mot minh khong phan biet duoc VFR: hai file cung
            // `24/1` van co the khac nhau o nhip khung that su.
            'avg_frame_rate' => $this->text($stream, 'avg_frame_rate'),
            'time_base' => $this->text($stream, 'time_base'),
            'width' => isset($stream['width']) ? (int) $stream['width'] : null,
            'height' => isset($stream['height']) ? (int) $stream['height'] : null,
            'codec_tag_string' => $this->text($stream, 'codec_tag_string'),
            'extradata_hash' => $this->text($stream, 'extradata_hash'),
            'sample_aspect_ratio' => $this->text($stream, 'sample_aspect_ratio'),
            'color_range' => $this->text($stream, 'color_range'),
            'color_space' => $this->text($stream, 'color_space'),
            'color_transfer' => $this->text($stream, 'color_transfer'),
            'color_primaries' => $this->text($stream, 'color_primaries'),
            'field_order' => $this->text($stream, 'field_order'),
            'rotation' => $this->rotation($stream),
        ];
    }

    /**
     * @param  array<string, mixed>  $stream
     * @return array<string, mixed>
     */
    private function audioStream(array $stream): array
    {
        return [
            'codec_name' => $this->text($stream, 'codec_name'),
            'profile' => $this->text($stream, 'profile'),
            // Tieng co the bat dau o mot thoi diem KHAC hinh. Do that tren
            // `fc_offset.mp4`: hinh 1.500000, tieng 1.978000. Bo qua no la xoa phang
            // mot do lech co the la chu y cua nguoi dung.
            'duration' => $this->number($stream, 'duration'),
            'start_time' => $this->number($stream, 'start_time'),
            'sample_rate' => isset($stream['sample_rate']) ? (int) $stream['sample_rate'] : null,
            'channels' => isset($stream['channels']) ? (int) $stream['channels'] : null,
            'channel_layout' => $this->text($stream, 'channel_layout'),
            'sample_fmt' => $this->text($stream, 'sample_fmt'),
            'time_base' => $this->text($stream, 'time_base'),
            'codec_tag_string' => $this->text($stream, 'codec_tag_string'),
            'extradata_hash' => $this->text($stream, 'extradata_hash'),
        ];
    }

    /**
     * Goc xoay nam trong `side_data_list`, khong phai mot truong thang.
     *
     * Chua clip nao cua du an co no, nhung vang mat khong co nghia la khong can
     * doc: chi can mot file co `displaymatrix` ma duoc ghep copy la ra video xoay
     * nguoc, va khong co gi bao.
     *
     * Tra ve BIEU DIEN THO, khong quy doi: mot file co the ghi `rotation` la so, file
     * khac ghi `displaymatrix` la ma tran. Day chi doc; chinh sach o
     * `StreamCompatibility` phai tu choi hai bieu dien khac nhau CHU KHONG doan xem
     * chung co cung mot phep quay hay khong.
     *
     * @param  array<string, mixed>  $stream
     */
    private function rotation(array $stream): ?string
    {
        foreach ((array) ($stream['side_data_list'] ?? []) as $side) {
            if (! is_array($side)) {
                continue;
            }

            if (isset($side['rotation'])) {
                return (string) $side['rotation'];
            }

            if (isset($side['displaymatrix'])) {
                return trim((string) $side['displaymatrix']);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $stream */
    /**
     * Gia tri so HUU HAN, hoac `null`.
     *
     * ffprobe viet `"N/A"` khi khong biet, va `(float) 'N/A'` ra `0.0` — mot do dai
     * bang khong trong nhu mot su that ve file, trong khi no la mot chua-biet.
     *
     * @param  array<string, mixed>  $stream
     */
    private function number(array $stream, string $key): ?float
    {
        $value = $stream[$key] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return is_finite($value) ? $value : null;
    }

    private function text(array $stream, string $key): ?string
    {
        $value = $stream[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        // ffprobe viet "unknown" / "N/A" cho thu no khong doc duoc. Giu nguyen chuoi
        // do thi hai file cung "khong biet" se duoc coi la khop nhau.
        return $value === '' || $value === 'unknown' || $value === 'N/A' ? null : $value;
    }

    /** @return array{ok: bool, duration_ms: ?int, width: ?int, height: ?int, error: ?string,
     *               video: ?array<string, mixed>, audio: ?array<string, mixed>,
     *               video_count: int, audio_count: int, other_count: int,
     *               streams: list<array{index: int, codec_type: string}>} */
    private function fail(string $message): array
    {
        return [
            'ok' => false, 'duration_ms' => null, 'width' => null, 'height' => null,
            'error' => $message,
            'video' => null, 'audio' => null,
            'video_count' => 0, 'audio_count' => 0, 'other_count' => 0,
            'streams' => [],
        ];
    }
}
