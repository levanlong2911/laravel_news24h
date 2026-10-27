<?php

declare(strict_types=1);

namespace App\Video\ReferencePack;

use RuntimeException;

/**
 * Bang mau ngoai that cua Phase 3R, dung bang code tu HEX da khai (khong goi model, khong co chu):
 * moi vai tro mot o dung mau, cung HEX van giu du cac vai tro. Phep do lai lay trung binh vung
 * giua tung o (khong do tren toan anh) va so voi HEX.
 */
final class ReferenceColorSheet
{
    public const VERSION = 'reference-color-sheet-v1';

    public const WIDTH = 1536;

    public const HEIGHT = 1024;

    private const GAP = 32;

    private const BACKGROUND = [236, 238, 240];

    /**
     * @param  list<array{role: string, name: string, hex: string}>  $colors
     */
    public static function png(array $colors): string
    {
        if ($colors === []) {
            throw new RuntimeException('Không có màu nào để dựng bảng màu.');
        }

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($image, 0, 0, imagecolorallocate($image, ...self::BACKGROUND));

        foreach (self::cells(count($colors)) as $i => [$x, $y, $w, $h]) {
            [$r, $g, $b] = self::rgb($colors[$i]['hex']);
            imagefilledrectangle($image, $x, $y, $x + $w - 1, $y + $h - 1, imagecolorallocate($image, $r, $g, $b));
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /**
     * Do lai tung o: chenh lech lon nhat giua kenh RGB trung binh vung giua o va HEX khai bao.
     *
     * @param  list<array{role: string, name: string, hex: string}>  $colors
     * @return list<array{role: string, hex: string, measured: string, max_channel_delta: int}>
     */
    public static function measure(string $png, array $colors): array
    {
        $image = @imagecreatefromstring($png);

        if ($image === false || imagesx($image) !== self::WIDTH || imagesy($image) !== self::HEIGHT) {
            throw new RuntimeException('Bảng màu không đọc được hoặc sai kích thước.');
        }

        $out = [];

        foreach (self::cells(count($colors)) as $i => [$x, $y, $w, $h]) {
            $sum = [0, 0, 0];
            $n = 0;

            for ($px = $x + intdiv($w, 4); $px < $x + 3 * intdiv($w, 4); $px += 8) {
                for ($py = $y + intdiv($h, 4); $py < $y + 3 * intdiv($h, 4); $py += 8) {
                    $c = imagecolorat($image, $px, $py);
                    $sum = [$sum[0] + (($c >> 16) & 0xFF), $sum[1] + (($c >> 8) & 0xFF), $sum[2] + ($c & 0xFF)];
                    $n++;
                }
            }

            $avg = array_map(static fn (int $s): int => (int) round($s / max(1, $n)), $sum);
            $expected = self::rgb($colors[$i]['hex']);
            $out[] = [
                'role' => $colors[$i]['role'],
                'hex' => $colors[$i]['hex'],
                'measured' => sprintf('#%02X%02X%02X', ...$avg),
                'max_channel_delta' => max(array_map(static fn (int $a, int $e): int => abs($a - $e), $avg, $expected)),
            ];
        }

        imagedestroy($image);

        return $out;
    }

    /** @return list<array{0: int, 1: int, 2: int, 3: int}> [x, y, w, h] tung o, chia deu theo chieu ngang */
    private static function cells(int $count): array
    {
        $w = intdiv(self::WIDTH - self::GAP * ($count + 1), $count);
        $h = self::HEIGHT - 2 * self::GAP;

        return array_map(static fn (int $i): array => [self::GAP + $i * ($w + self::GAP), self::GAP, $w, $h], range(0, $count - 1));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        if (preg_match('/^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $hex, $m) !== 1) {
            throw new RuntimeException("HEX không hợp lệ: {$hex}");
        }

        return [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])];
    }
}
