<?php

namespace App\Video\Media;

/**
 * Dem khung hinh cua mot file video.
 *
 * Ton tai vi cung mot ly do voi `FfmpegRunner`: co luat chi kiem duoc khi tiem duoc
 * ban gia. Cu the la "dem DU khung ma ffprobe van bao loi giai ma thi van tu choi"
 * — mot fixture that khong co lap duoc dieu kien do, vi file hong thuong keo theo
 * thieu khung va phep so so khung se no truoc.
 */
interface FrameCounter
{
    public function count(string $file, ?int $timeoutSeconds = null): VideoFrameCountResult;
}
