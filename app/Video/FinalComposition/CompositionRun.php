<?php

namespace App\Video\FinalComposition;

use App\Models\VideoFinal;

/**
 * Danh tinh cua mot luot ghep CO CHU, biet truoc khi encode.
 *
 * Thu muc luot chay mang ten final ID chu khong phai mot UUID ngau nhien: khi tien
 * trinh chet truoc luc ghi receipt, hang DB van chi duoc thang thu muc con lai tren
 * dia ma khong can luu them cot nao.
 *
 * `null` la duong CLI xem thu — no khong so huu hang nao, nen khong co receipt va
 * dung thu muc ngau nhien.
 */
final class CompositionRun
{
    public function __construct(
        public readonly string $finalId,
        public readonly string $manifestHash,
        public readonly string $engine = VideoFinal::ENGINE_LARAVEL,
    ) {}
}
