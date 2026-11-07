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

    /** @var string the version of the way cells are found from the lines an image draws and cut inside them */
    public const LINE_DETECTOR = 'white-line-v1';

    /** @var string what a person reads when the lines of an image do not give its cells for certain */
    public const LINES_UNCERTAIN = 'Không xác định chắc chắn đường viền — cần kiểm.';

    /** @var float the share of its length a line must stand out over, so a bright edge on a part is not taken for one */
    private const LINE_SHARE = 0.95;

    /** @var int how much brighter than the pixels to each side a line must be */
    private const LINE_CONTRAST = 6;

    /** @var int how far to each side of a line those pixels are read */
    private const LINE_SIDE = 5;

    /** @var int the pixels between two samples along a line */
    private const LINE_STEP = 8;

    /** @var int the widest run of pixels still taken for one thin line */
    private const LINE_MAX_WIDTH = 12;

    /** @var int the pixels left out beyond the bright pixels of a line, where its soft edge fades */
    private const LINE_INSET = 2;

    /** @var float the smallest share of its even size a found cell may have */
    private const MIN_CELL_SHARE = 0.4;

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

        if (isset($this->config['cell']) && ($width !== $columns * (int) $this->config['cell']['width'] || $height !== $rows * (int) $this->config['cell']['height'])) {
            throw new RuntimeException("Cấu hình master sai: {$width}x{$height} không chia đúng {$rows}x{$columns} ô {$this->config['cell']['width']}x{$this->config['cell']['height']}.");
        }

        return [$width, $height];
    }

    /**
     * @return array{0: int, 1: int} where a cell starts along one side of the canvas and how long it is: the configured cell size when the layout fixes one, otherwise floor(index × side / count) up to floor((index + 1) × side / count), so the cells cover the whole side with no overlap and no gap
     */
    private function span(int $index, int $count, int $side, string $dimension): array
    {
        if (isset($this->config['cell'])) {
            $length = (int) $this->config['cell'][$dimension];

            return [$index * $length, $length];
        }

        $start = intdiv($index * $side, $count);

        return [$start, intdiv(($index + 1) * $side, $count) - $start];
    }

    /**
     * Toa do tung o, theo thu tu `order` (theo hang, trai sang phai).
     *
     * @return list<array{id: string, index: int, row: int, column: int, x: int, y: int, width: int, height: int}>
     */
    public function cells(): array
    {
        [$canvasWidth, $canvasHeight] = $this->canvas();
        $order = array_values((array) $this->config['order']);
        $columns = (int) $this->config['columns'];
        $rows = (int) $this->config['rows'];

        if (count($order) !== $columns * $rows || count(array_unique($order)) !== count($order)) {
            throw new RuntimeException('Cấu hình master sai: thứ tự ô phải gồm đủ '.($columns * $rows).' góc, không trùng.');
        }

        $cells = [];

        foreach ($order as $index => $id) {
            $row = intdiv($index, $columns);
            $column = $index % $columns;
            $box = $this->config['boxes'][$index] ?? null;
            [$x, $width] = is_array($box) ? [(int) $box['x'], (int) $box['width']] : $this->span($column, $columns, $canvasWidth, 'width');
            [$y, $height] = is_array($box) ? [(int) $box['y'], (int) $box['height']] : $this->span($row, $rows, $canvasHeight, 'height');
            $cells[] = [
                'id' => (string) $id,
                'index' => $index,
                'row' => $row + 1,
                'column' => $column + 1,
                'x' => $x,
                'y' => $y,
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
     * @return list<array{x: int, y: int, width: int, height: int}> the cells the image itself draws, in cell order and inside its lines: the bright lines across it give the rows, the bright lines down each row give the columns of that row
     *
     * @throws RuntimeException when the number of lines is not the number the grid needs, or a cell is too small
     */
    public function boxesFromLines(GdImage $master): array
    {
        [$width, $height] = $this->canvas();
        $rows = (int) $this->config['rows'];
        $columns = (int) $this->config['columns'];
        imagepalettetotruecolor($master);
        $across = $this->lineRuns($master, false, $height, self::LINE_STEP, $width - self::LINE_STEP);

        if (count($across) !== $rows - 1) {
            throw new RuntimeException(self::LINES_UNCERTAIN.' Thấy '.count($across).' đường ngang, lưới cần '.($rows - 1).'.');
        }

        $boxes = [];

        foreach ($this->insideLines($across, $height) as $row => [$top, $bottom]) {
            $down = $this->lineRuns($master, true, $width, $top + self::LINE_STEP, $bottom - self::LINE_STEP);

            if (count($down) !== $columns - 1) {
                throw new RuntimeException(self::LINES_UNCERTAIN.' Hàng '.($row + 1).' thấy '.count($down).' đường dọc, lưới cần '.($columns - 1).'.');
            }

            foreach ($this->insideLines($down, $width) as [$left, $right]) {
                if ($right - $left < $width / $columns * self::MIN_CELL_SHARE || $bottom - $top < $height / $rows * self::MIN_CELL_SHARE) {
                    throw new RuntimeException(self::LINES_UNCERTAIN.' Hàng '.($row + 1).' có ô chỉ '.($right - $left).'×'.($bottom - $top).' px.');
                }

                $boxes[] = ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top];
            }
        }

        return $boxes;
    }

    /**
     * @return list<array{0: int, 1: int}> the first and last position of every thin run of lines that stand out over nearly all of their length between two positions
     */
    private function lineRuns(GdImage $image, bool $vertical, int $limit, int $from, int $to): array
    {
        $samples = (int) ceil(max(0, $to - $from) / self::LINE_STEP);
        $allowed = (int) floor($samples * (1 - self::LINE_SHARE));
        $runs = [];
        $start = null;

        for ($at = self::LINE_SIDE; $at <= $limit; $at++) {
            if ($at < $limit - self::LINE_SIDE && $samples > 0 && $this->standsOut($image, $vertical, $at, $from, $to, $allowed)) {
                $start ??= $at;

                continue;
            }

            if ($start !== null && $at - $start <= self::LINE_MAX_WIDTH) {
                $runs[] = [$start, $at - 1];
            }

            $start = null;
        }

        return $runs;
    }

    /** @return bool whether the line at a position is brighter than the pixels to each side, with no more misses than allowed */
    private function standsOut(GdImage $image, bool $vertical, int $at, int $from, int $to, int $allowed): bool
    {
        $misses = 0;

        for ($along = $from; $along < $to; $along += self::LINE_STEP) {
            $here = $vertical ? $this->luma($image, $at, $along) : $this->luma($image, $along, $at);
            $before = $vertical ? $this->luma($image, $at - self::LINE_SIDE, $along) : $this->luma($image, $along, $at - self::LINE_SIDE);
            $after = $vertical ? $this->luma($image, $at + self::LINE_SIDE, $along) : $this->luma($image, $along, $at + self::LINE_SIDE);

            if (($here - $before < self::LINE_CONTRAST || $here - $after < self::LINE_CONTRAST) && ++$misses > $allowed) {
                return false;
            }
        }

        return true;
    }

    private function luma(GdImage $image, int $x, int $y): int
    {
        $colour = imagecolorat($image, $x, $y);

        return (int) (0.3 * (($colour >> 16) & 255) + 0.59 * (($colour >> 8) & 255) + 0.11 * ($colour & 255));
    }

    /**
     * @param  list<array{0: int, 1: int}>  $lines
     * @return list<array{0: int, 1: int}> the start and the end, not included, of every stretch of a side between its lines, leaving out the lines and their soft edges
     */
    private function insideLines(array $lines, int $side): array
    {
        $stretches = [];
        $start = 0;

        foreach ($lines as [$first, $last]) {
            $stretches[] = [$start, $first - self::LINE_INSET];
            $start = $last + 1 + self::LINE_INSET;
        }

        $stretches[] = [$start, $side];

        return $stretches;
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
