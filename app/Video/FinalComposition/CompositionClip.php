<?php

namespace App\Video\FinalComposition;

/**
 * Mot clip da duoc xac thuc, san sang vao graph.
 *
 * `frames` la so khung DAU RA, quy doi mot lan duy nhat o `CompositionPlanBuilder`.
 * Moi phep tinh timeline phia sau doc con so nay, nen khong the ton tai hai cach
 * lam tron khac nhau cho cung mot clip.
 */
final class CompositionClip
{
    public function __construct(
        public readonly string $path,
        public readonly int $trimStartMs,
        public readonly int $durationMs,
        public readonly int $frames,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $hasAudio,
        /**
         * Tieng bat dau sau hinh bao nhieu mili giay; am la bat dau truoc.
         *
         * Giu chu khong xoa: rebase hai luong doc lap ve 0 la lam phang mot do lech
         * co the la chu y. Do that tren `fc_offset.mp4` — hinh 1.500000, tieng
         * 1.978000 — nen day khong phai truong hop gia dinh.
         */
        public readonly int $audioOffsetMs = 0,
        /**
         * Kich thuoc noi dung SAU KHI da vua vao khung dau ra, tinh san o builder.
         *
         * Khong phai kich thuoc trung gian: di thang tu nguon toi day la MOT lan noi
         * suy, va con so luon nam trong khung dau ra nen mot SAR lon khong the doi
         * mot khung khong lo.
         *
         * Tinh o builder chu khong viet thanh bieu thuc `iw*sar` hay
         * `force_original_aspect_ratio` trong graph: o day phep lam tron nhin thay
         * duoc va kiem duoc, con trong graph thi no la hanh vi cua ffmpeg.
         */
        public readonly int $contentWidth = 0,
        public readonly int $contentHeight = 0,
    ) {}
}
