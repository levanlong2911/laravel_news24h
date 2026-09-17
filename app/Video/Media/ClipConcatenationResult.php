<?php

namespace App\Video\Media;

/**
 * Ket qua mot lan ghep.
 *
 * `FfmpegResult::successful` chi noi ve TIEN TRINH. Cai nay moi noi output co luong
 * video, giu duoc bo cuc va van tay codec cua input, khong mat khung hinh, va co do
 * dai hop le.
 *
 * Giu ca so KY VONG lan so DO DUOC de nguoi chay doc duoc khoang cach, khong phai
 * tu tru trong dau.
 */
final class ClipConcatenationResult
{
    /** @param list<string> $reasons */
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $code,
        public readonly array $reasons,
        public readonly ?int $expectedDurationMs = null,
        public readonly ?int $durationMs = null,
        public readonly ?int $expectedVideoFrames = null,
        public readonly ?int $videoFrames = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $bytes = null,
        public readonly ?string $sha256 = null,
    ) {}

    public static function composed(
        int $expectedDurationMs,
        int $durationMs,
        int $expectedVideoFrames,
        int $videoFrames,
        int $width,
        int $height,
        int $bytes,
        string $sha256,
    ): self {
        return new self(
            true, null, [],
            $expectedDurationMs, $durationMs,
            $expectedVideoFrames, $videoFrames,
            $width, $height, $bytes, $sha256,
        );
    }

    /** @param list<string> $reasons */
    public static function refused(string $code, array $reasons): self
    {
        return new self(false, $code, $reasons);
    }
}
