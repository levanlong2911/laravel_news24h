<?php

declare(strict_types=1);

namespace App\Video\ModelSheet;

use GdImage;
use RuntimeException;

/**
 * Anh master cua model sheet: mot anh AI 3840x2160, luoi 2 hang x 4 cot, cat thanh tam
 * o 960x1080 theo toa do co dinh trong config `video.superyacht.model_sheet.master`.
 *
 * Logic thuan tren GD, khong doc DB. Loi chan: file hong, sai dinh dang, sai kich thuoc.
 */
final class ModelSheetMaster
{
    private const MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /**
     * @param  array<string, mixed>  $config  video.superyacht.model_sheet.master
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('video.superyacht.model_sheet.master'));
    }

    /** @return array{0: int, 1: int} */
    public function canvas(): array
    {
        [$width, $height] = array_map('intval', explode('x', (string) $this->config['size']));
        $columns = (int) $this->config['columns'];
        $rows = (int) $this->config['rows'];

        if ($width !== $columns * (int) $this->config['cell']['width'] || $height !== $rows * (int) $this->config['cell']['height']) {
            throw new RuntimeException("Cấu hình master sai: {$width}x{$height} không chia đúng {$rows}x{$columns} ô {$this->config['cell']['width']}x{$this->config['cell']['height']}.");
        }

        return [$width, $height];
    }

    /**
     * Toa do tung o, theo thu tu `order` (theo hang, trai sang phai).
     *
     * @return list<array{id: string, index: int, row: int, column: int, x: int, y: int, width: int, height: int}>
     */
    public function cells(): array
    {
        $this->canvas();
        $order = array_values((array) $this->config['order']);
        $columns = (int) $this->config['columns'];
        $width = (int) $this->config['cell']['width'];
        $height = (int) $this->config['cell']['height'];

        if (count($order) !== $columns * (int) $this->config['rows'] || count(array_unique($order)) !== count($order)) {
            throw new RuntimeException('Cấu hình master sai: thứ tự ô phải gồm đủ '.($columns * (int) $this->config['rows']).' góc, không trùng.');
        }

        $cells = [];

        foreach ($order as $index => $id) {
            $row = intdiv($index, $columns);
            $column = $index % $columns;
            $cells[] = [
                'id' => (string) $id,
                'index' => $index,
                'row' => $row + 1,
                'column' => $column + 1,
                'x' => $column * $width,
                'y' => $row * $height,
                'width' => $width,
                'height' => $height,
            ];
        }

        return $cells;
    }

    /** Hash cua bo cuc (kich thuoc, thu tu, toa do) — vao dau vao cua bo model sheet. */
    public function layoutSha256(): string
    {
        return hash('sha256', (string) json_encode(['size' => $this->config['size'], 'cells' => $this->cells()], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Anh mau luoi trong cho tuy chon thu nghiem `layout_guide`: nen studio, vien tam o.
     * Khong chua hinh hoc tau.
     */
    public function guidePng(): string
    {
        [$width, $height] = $this->canvas();
        $image = imagecreatetruecolor($width, $height);
        $background = $this->allocate($image, (string) $this->config['guide_background']);
        $line = $this->allocate($image, (string) $this->config['guide_line']);
        imagefill($image, 0, 0, $background);
        imagesetthickness($image, 4);

        foreach ($this->cells() as $cell) {
            imagerectangle($image, $cell['x'] + 2, $cell['y'] + 2, $cell['x'] + $cell['width'] - 3, $cell['y'] + $cell['height'] - 3, $line);
        }

        return $this->png($image);
    }

    /**
     * Doc anh master: giai ma duoc, dung dinh dang, dung kich thuoc. Sai la loi chan.
     */
    public function decode(string $bytes): GdImage
    {
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        [$width, $height] = $this->canvas();

        if ($info === false || ! in_array($info['mime'] ?? '', self::MIMES, true)) {
            throw new RuntimeException('File master hỏng hoặc không phải PNG/JPEG/WebP.');
        }

        if ((int) $info[0] !== $width || (int) $info[1] !== $height) {
            throw new RuntimeException("Master là {$info[0]}x{$info[1]}, không phải {$width}x{$height}.");
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage || imagesx($image) !== $width || imagesy($image) !== $height) {
            throw new RuntimeException('Không giải mã được ảnh master.');
        }

        return $image;
    }

    /**
     * @return array<string, array{cell: array<string, int|string>, png: string}>  view_id => o da cat
     */
    public function crop(GdImage $master): array
    {
        $crops = [];

        foreach ($this->cells() as $cell) {
            $part = imagecreatetruecolor($cell['width'], $cell['height']);
            imagecopy($part, $master, 0, 0, $cell['x'], $cell['y'], $cell['width'], $cell['height']);
            $crops[$cell['id']] = ['cell' => $cell, 'png' => $this->png($part)];
        }

        return $crops;
    }

    private function allocate(GdImage $image, string $hex): int
    {
        $hex = ltrim($hex, '#');

        return (int) imagecolorallocate($image, (int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2)));
    }

    private function png(GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
