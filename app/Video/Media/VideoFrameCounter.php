<?php

namespace App\Video\Media;

use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Dem khung hinh that su co trong luong video.
 *
 * Tach HAN khoi `Mp4Probe` vi hai ly do:
 *
 *   - `-count_frames` bat ffprobe GIAI MA toan bo luong. No cham hon han mot lan doc
 *     metadata, va `Mp4Probe` nam tren duong checkpoint cua clip da tra tien — nhet
 *     vao do la bat moi lan checkpoint tra gia cho mot nhu cau cua viec ghep.
 *   - `format.duration` KHONG noi duoc gi ve khung hinh. Do that tren file da ghep:
 *     video 20.000000s / 480 khung, audio 20.021333s, va format lay theo AUDIO. Bo
 *     mot khung video thi duration khong he doi. Chi dem khung moi bat duoc.
 */
final class VideoFrameCounter implements FrameCounter
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeoutSeconds = 300,
    ) {}

    public function count(string $file, ?int $timeoutSeconds = null): VideoFrameCountResult
    {
        $process = new Process([
            $this->binary, '-v', 'error', '-count_frames',
            '-select_streams', 'v:0', '-print_format', 'json',
            '-show_entries', 'stream=nb_read_frames', $file,
        ]);
        $process->setTimeout($timeoutSeconds ?? $this->timeoutSeconds);

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            return VideoFrameCountResult::failed('khong chay duoc ffprobe: '.Str::limit($e->getMessage(), 200));
        }

        if (! $process->isSuccessful()) {
            return VideoFrameCountResult::failed('ffprobe that bai: '.Str::limit($process->getErrorOutput(), 200));
        }

        // `-v error` nghia la stderr o day chi chua muc LOI, khong phai moi canh
        // bao — nen khac rong la mot tin hieu manh, khong phai nhieu.
        $diagnostics = Str::limit(trim($process->getErrorOutput()), 2000, '');

        $json = json_decode($process->getOutput(), true);

        if (! is_array($json)) {
            return VideoFrameCountResult::failed('ffprobe tra ve khong phai JSON', $diagnostics);
        }

        $streams = (array) ($json['streams'] ?? []);

        if ($streams === []) {
            return VideoFrameCountResult::failed('file khong co luong video nao', $diagnostics);
        }

        return FrameCountParser::parse($streams[0]['nb_read_frames'] ?? null, $diagnostics);
    }
}
