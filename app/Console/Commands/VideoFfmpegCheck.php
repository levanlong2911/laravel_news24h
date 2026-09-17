<?php

namespace App\Console\Commands;

use App\Video\Media\FfmpegBinary;
use App\Video\Media\Mp4Probe;
use Illuminate\Console\Command;

/**
 * Noi ro Laravel dang nhin thay ffmpeg/ffprobe o dau va co chay duoc khong.
 *
 * Ly do ton tai: mot lan truoc ffprobe chay trong terminal ma tien trinh web thi
 * khong, vi PATH khac nhau. Loi do chi lo ra giua mot lan render da tra tien. Lenh
 * nay cho nhin thay truoc, va khong ton gi.
 */
class VideoFfmpegCheck extends Command
{
    protected $signature = 'video:ffmpeg-check';

    protected $description = 'Kiem Laravel co chay duoc ffmpeg va ffprobe khong, va dang lay tu dau';

    public function handle(FfmpegBinary $ffmpeg, Mp4Probe $probe): int
    {
        $this->newLine();
        $this->line('ffprobe');
        $this->line('  duong dan : '.config('video.veo.ffprobe_bin'));

        $probeProblem = $probe->problem();

        if ($probeProblem === null) {
            $this->info('  trang thai: chay duoc');
        } else {
            $this->error('  trang thai: '.$probeProblem);
        }

        $this->newLine();
        $this->line('ffmpeg');
        $this->line('  duong dan : '.$ffmpeg->path());
        $this->line('  nguon     : '.$ffmpeg->source());

        $problem = $ffmpeg->problem();

        if ($problem !== null) {
            $this->error('  trang thai: '.$problem);
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('  trang thai: chay duoc');
        $this->line('  version   : '.$ffmpeg->version());

        // THONG TIN, khong phai dieu kien dau: chang nay ghep bang `-c copy`, khong
        // encode, nen mot ban ffmpeg thieu libx264 van ghep duoc.
        $this->line('  libx264   : '.($ffmpeg->hasEncoder('libx264') ? 'co' : 'khong co')
            .'  (chi la thong tin — ghep bang -c copy khong can encode)');

        $this->newLine();

        return $probeProblem === null ? self::SUCCESS : self::FAILURE;
    }
}
