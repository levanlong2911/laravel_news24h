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
    public const LINE_DETECTOR = 'white-line-v2';

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

    /** @var int the widest band the bright pixels of one line may take over its whole length, how thick it is and how far it leans together; its soft edge is not counted in it */
    private const LINE_MAX_WIDTH = 12;

    /** @var int the pixels left between the soft edge of a line and a cell */
    private const LINE_INSET = 2;

    /** @var int how much brighter than the background beyond it a pixel next to a line must be to belong to its soft edge */
    private const LINE_FADE = 3;

    /** @var int the most pixels the soft edge of a line adds to each side of its bright band, short of the background it is measured against; at most LINE_MAX_WIDTH + 2 × LINE_FADE_REACH pixels of a line are cut away, and a cell keeps LINE_INSET more off them */
    private const LINE_FADE_REACH = self::LINE_SIDE - 1;

    /** @var int the most a line may move from one sample to the next one found before it no longer reads as one line */
    private const LINE_SHIFT = 2;

    /** @var int the widest dip inside the bright pixels of one sample still read as one line */
    private const LINE_GAP = 2;

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
     * @return list<array{x: int, y: int, width: int, height: int}> the cells the image itself draws, in cell order and inside its lines: the bright lines across it give the rows, the bright lines down each row give the columns of that row; a cell stops LINE_INSET short of the soft edge of each line, so no white of a line is left in it
     *
     * @throws RuntimeException when the number of lines is not the number the grid needs, a line is too wide, splits or does not run on as one line, or a cell is too small
     */
    public function boxesFromLines(GdImage $master): array
    {
        [$width, $height] = $this->canvas();
        $rows = (int) $this->config['rows'];
        $columns = (int) $this->config['columns'];
        imagepalettetotruecolor($master);
        $across = $this->lines($master, false, $height, self::LINE_STEP, $width - self::LINE_STEP, 'ngang');

        if (count($across) !== $rows - 1) {
            throw new RuntimeException(self::LINES_UNCERTAIN.' Thấy '.count($across).' đường ngang, lưới cần '.($rows - 1).'.');
        }

        $boxes = [];

        foreach ($this->insideLines($across, $height) as $row => [$top, $bottom]) {
            $down = $this->lines($master, true, $width, $top + self::LINE_STEP, $bottom - self::LINE_STEP, 'dọc ở hàng '.($row + 1));

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
     * @param  bool  $vertical  lines down the image, read along rows of pixels; otherwise lines across it, read along columns
     * @param  int  $limit  the length of the side the lines cross
     * @param  int  $from  where the samples along the lines start
     * @param  int  $to  where they stop, not included
     * @param  string  $name  how a person reads these lines in a message
     * @return list<array{0: int, 1: int}> the first and last position of every line, its soft edge included: its bright pixels lie in a band no wider than LINE_MAX_WIDTH that holds one thin bright run in at least LINE_SHARE of all the samples, so a line drawn a little askew is still one line, and its soft edge adds at most LINE_FADE_REACH to each side of that band; a sample where nothing stands out, such as where another line crosses, counts against the share
     *
     * @throws RuntimeException when such a band is wider than one line, splits in two on a sample, or moves too far between two samples
     */
    private function lines(GdImage $image, bool $vertical, int $limit, int $from, int $to, string $name): array
    {
        $samples = [];

        for ($along = $from; $along < $to; $along += self::LINE_STEP) {
            $samples[] = $this->brightRuns($image, $vertical, $along, $limit);
        }

        if ($samples === []) {
            return [];
        }

        $needed = count($samples) - (int) floor(count($samples) * (1 - self::LINE_SHARE));
        $support = array_fill(0, $limit + 1, 0);

        foreach ($samples as $runs) {
            $counted = -1;

            foreach ($runs as [$first, $last]) {
                for ($start = max(0, $counted + 1, $last - self::LINE_MAX_WIDTH + 1); $start <= $first; $start++) {
                    $support[$start]++;
                }

                $counted = max($counted, $first);
            }
        }

        $lines = [];
        $start = null;

        foreach ($support as $at => $count) {
            if ($count >= $needed) {
                $start ??= $at;

                continue;
            }

            if ($start !== null) {
                $lines[] = $this->line($samples, $start, $at - 1, $needed, $vertical, $name);
            }

            $start = null;
        }

        return $lines;
    }

    /**
     * @param  list<list<array{0: int, 1: int, 2: int, 3: int}>>  $samples  the bright runs of every sample
     * @param  int  $low  the first start of the bands of LINE_MAX_WIDTH that hold the line on enough samples
     * @param  int  $high  the last start of those bands
     * @param  int  $needed  how many samples must hold the line
     * @return array{0: int, 1: int} the first and last position the line covers on any sample, its soft edge included
     *
     * @throws RuntimeException when no sample holds the line alone inside every band, the line splits in two or moves more than LINE_SHIFT from one sample to the next, it is followed on fewer samples than needed, or its bright pixels take more than LINE_MAX_WIDTH
     */
    private function line(array $samples, int $low, int $high, int $needed, bool $vertical, string $name): array
    {
        $core = [$high, $low + self::LINE_MAX_WIDTH - 1];
        $where = 'Đường '.$name.' quanh '.($vertical ? 'x' : 'y').' '.$core[0].'–'.$core[1];
        $alone = [];

        foreach ($samples as $index => $runs) {
            $inside = array_values(array_filter($runs, static fn (array $run): bool => $run[1] >= $core[0] && $run[0] <= $core[1]));

            if (count($inside) === 1) {
                $alone[$index] = $inside[0];
            }
        }

        if ($core[0] > $core[1] || $alone === []) {
            throw new RuntimeException(self::LINES_UNCERTAIN.' '.$where.' có nhiều cách hiểu.');
        }

        $middles = array_map(static fn (array $run): int => $run[0] + $run[1], $alone);
        $sorted = array_values($middles);
        sort($sorted);
        $median = $sorted[intdiv(count($sorted), 2)];
        $anchor = array_key_first($alone);

        foreach ($middles as $index => $middle) {
            if (abs($middle - $median) < abs($middles[$anchor] - $median)) {
                $anchor = $index;
            }
        }

        [$ahead, $splitAhead, $movedAhead] = $this->follow($samples, $anchor, 1, $alone[$anchor]);
        [$behind, $splitBehind, $movedBehind] = $this->follow($samples, $anchor, -1, $alone[$anchor]);
        $line = [$anchor => $alone[$anchor]] + $ahead + $behind;
        $first = min(array_column($line, 0));
        $last = max(array_column($line, 1));
        $where = 'Đường '.$name.' quanh '.($vertical ? 'x' : 'y').' '.$first.'–'.$last;

        if ($splitAhead + $splitBehind > 0) {
            throw new RuntimeException(self::LINES_UNCERTAIN.' '.$where.' tách làm hai ở '.($splitAhead + $splitBehind).' mẫu.');
        }

        if ($movedAhead + $movedBehind > 0) {
            throw new RuntimeException(self::LINES_UNCERTAIN.' '.$where.' không liền mạch: '.($movedAhead + $movedBehind).' chỗ lệch quá '.self::LINE_SHIFT.' px giữa hai mẫu.');
        }

        if (count($line) < $needed) {
            throw new RuntimeException(self::LINES_UNCERTAIN.' '.$where.' không liền mạch: chỉ theo được '.count($line).'/'.count($samples).' mẫu, cần '.$needed.'.');
        }

        if ($last - $first + 1 > self::LINE_MAX_WIDTH) {
            throw new RuntimeException(self::LINES_UNCERTAIN.' '.$where.' có phần sáng rộng '.($last - $first + 1).' px, quá '.self::LINE_MAX_WIDTH.' px của một đường (chưa tính mép mờ).');
        }

        return [min(array_column($line, 2)), max(array_column($line, 3))];
    }

    /**
     * @param  list<list<array{0: int, 1: int, 2: int, 3: int}>>  $samples  the bright runs of every sample
     * @param  int  $anchor  the sample the line is followed from
     * @param  int  $step  1 to follow it onwards, -1 back
     * @param  array{0: int, 1: int, 2: int, 3: int}  $run  the run of the line on that sample
     * @return array{0: array<int, array{0: int, 1: int, 2: int, 3: int}>, 1: int, 2: int} the run of the line on every sample it is found on, the samples where two runs lie within LINE_SHIFT of where it was last found, and the steps where it moves more than LINE_SHIFT
     */
    private function follow(array $samples, int $anchor, int $step, array $run): array
    {
        $found = [];
        $split = 0;
        $moved = 0;

        for ($index = $anchor + $step; $index >= 0 && $index < count($samples); $index += $step) {
            $near = array_values(array_filter($samples[$index], static fn (array $candidate): bool => $candidate[1] >= $run[0] - self::LINE_SHIFT && $candidate[0] <= $run[1] + self::LINE_SHIFT));

            if (count($near) > 1) {
                $split++;

                continue;
            }

            if ($near === []) {
                continue;
            }

            if (abs($near[0][0] + $near[0][1] - $run[0] - $run[1]) > 2 * self::LINE_SHIFT) {
                $moved++;
            }

            $found[$index] = $near[0];
            $run = $near[0];
        }

        return [$found, $split, $moved];
    }

    /**
     * @param  int  $along  where the sample crosses the lines
     * @return list<array{0: int, 1: int, 2: int, 3: int}> every run of pixels across the sample brighter by LINE_CONTRAST than the pixels LINE_SIDE away to each side, with dips of up to LINE_GAP closed: its first and last pixel, then the first and last pixel of its soft edge
     */
    private function brightRuns(GdImage $image, bool $vertical, int $along, int $limit): array
    {
        $luma = [];

        for ($at = 0; $at < $limit; $at++) {
            $luma[] = $vertical ? $this->luma($image, $at, $along) : $this->luma($image, $along, $at);
        }

        $runs = [];
        $start = null;
        $end = null;

        for ($at = self::LINE_SIDE; $at < $limit - self::LINE_SIDE; $at++) {
            if ($luma[$at] - $luma[$at - self::LINE_SIDE] < self::LINE_CONTRAST || $luma[$at] - $luma[$at + self::LINE_SIDE] < self::LINE_CONTRAST) {
                continue;
            }

            if ($end !== null && $at - $end - 1 <= self::LINE_GAP) {
                $end = $at;

                continue;
            }

            if ($start !== null) {
                $runs[] = $this->softEdge($luma, $start, $end);
            }

            $start = $at;
            $end = $at;
        }

        if ($start !== null) {
            $runs[] = $this->softEdge($luma, $start, $end);
        }

        return $runs;
    }

    /**
     * @param  list<int>  $luma  the brightness of every pixel across the sample
     * @return array{0: int, 1: int, 2: int, 3: int} the run, then how far its soft edge reaches: the pixels next to it still LINE_FADE brighter than the background LINE_SIDE away, no more than LINE_FADE_REACH to each side
     */
    private function softEdge(array $luma, int $first, int $last): array
    {
        $edgeFirst = $first;
        $edgeLast = $last;

        while ($edgeFirst > $first - self::LINE_FADE_REACH && $luma[$edgeFirst - 1] - $luma[$first - self::LINE_SIDE] >= self::LINE_FADE) {
            $edgeFirst--;
        }

        while ($edgeLast < $last + self::LINE_FADE_REACH && $luma[$edgeLast + 1] - $luma[$last + self::LINE_SIDE] >= self::LINE_FADE) {
            $edgeLast++;
        }

        return [$first, $last, $edgeFirst, $edgeLast];
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
