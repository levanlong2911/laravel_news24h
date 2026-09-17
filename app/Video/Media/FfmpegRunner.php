<?php

namespace App\Video\Media;

/**
 * Chay ffmpeg.
 *
 * Ton tai de test tiem duoc mot ban gia ma KHONG phai bo `final` cua lop that. Cai
 * duoc chung minh nho moi noi nay la "metadata lech thi KHONG he goi ffmpeg" — mot
 * khang dinh khong kiem duoc neu noi ghep tu dung `Process` ben trong.
 */
interface FfmpegRunner
{
    /**
     * @param  list<string>  $args  tham so, KHONG ke ten binary
     * @param  int|null  $timeoutSeconds  null = dung han muc cau hinh cua binary.
     *                                    Mot luot ghep co ngan sach cho CA LUOT, nen
     *                                    tung lan goi phai nhan duoc phan con lai.
     */
    public function run(array $args, ?int $timeoutSeconds = null): FfmpegResult;
}
