<?php

declare(strict_types=1);

namespace App\Video\Moto\Film;

use RuntimeException;

/**
 * The font a card style names, opened only when its bytes are the ones the style pins.
 * It answers two questions before a film is cut: does the font draw every character of a card,
 * and how wide is a line of it at the size of the style. Nothing here changes a text.
 */
final class MotoFilmFont
{
    /** @var array<int, bool> */
    private array $known = [];

    /**
     * @param  string  $bytes  the whole file, read once
     * @param  list<array{format: int, offset: int}>  $maps  the Unicode character maps of the file, best first
     */
    private function __construct(
        public readonly string $path,
        public readonly string $sha256,
        private readonly string $bytes,
        private readonly array $maps,
    ) {}

    /**
     * @param  string  $path  an absolute path
     * @param  string  $sha256  the hash the style pins
     *
     * @throws RuntimeException when the file is missing, is another file than the one pinned, or has no Unicode map to read
     */
    public static function open(string $path, string $sha256): self
    {
        $bytes = is_file($path) ? @file_get_contents($path) : false;

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Không có file font '.$path.'.');
        }

        $actual = hash('sha256', $bytes);

        if (! hash_equals($sha256, $actual)) {
            throw new RuntimeException('File font '.$path.' không phải bản kiểu card ghim (ghim '.substr($sha256, 0, 12).', đọc được '.substr($actual, 0, 12).').');
        }

        if (! function_exists('imagettfbbox')) {
            throw new RuntimeException('PHP không có GD với FreeType nên không đo được dòng chữ của card.');
        }

        return new self($path, $actual, $bytes, self::maps($bytes, $path));
    }

    /** @return list<string> the characters of the text the font has no glyph for, each once, in the order they appear */
    public function missingGlyphs(string $text): array
    {
        $missing = [];

        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $code = mb_ord($char, 'UTF-8');

            if ($code === false || $code === 0x20) {
                continue;
            }

            if (! ($this->known[$code] ??= $this->glyph($code) !== 0)) {
                $missing[$char] = true;
            }
        }

        return array_keys($missing);
    }

    /** @return int the width of a line in pixels at this pixel size, as FreeType lays it out through GD */
    public function width(string $line, int $sizePx): int
    {
        $box = imagettfbbox($sizePx * 0.75, 0, $this->path, $line);

        if ($box === false) {
            throw new RuntimeException('GD không đo được dòng chữ bằng font '.$this->path.'.');
        }

        return (int) (max($box[2], $box[4]) - min($box[0], $box[6]));
    }

    /**
     * @return list<array{format: int, offset: int}>
     *
     * @throws RuntimeException
     */
    private static function maps(string $bytes, string $path): array
    {
        $tables = self::u16($bytes, 4);
        $cmap = null;

        for ($i = 0; $i < $tables; $i++) {
            $record = 12 + 16 * $i;

            if (substr($bytes, $record, 4) === 'cmap') {
                $cmap = self::u32($bytes, $record + 8);
            }
        }

        if ($cmap === null) {
            throw new RuntimeException('Font '.$path.' không có bảng cmap.');
        }

        $maps = [];

        for ($i = 0, $count = self::u16($bytes, $cmap + 2); $i < $count; $i++) {
            $record = $cmap + 4 + 8 * $i;
            $platform = self::u16($bytes, $record);
            $encoding = self::u16($bytes, $record + 2);
            $offset = $cmap + self::u32($bytes, $record + 4);
            $format = self::u16($bytes, $offset);
            $unicode = $platform === 0 || ($platform === 3 && in_array($encoding, [1, 10], true));

            if ($unicode && in_array($format, [4, 12], true)) {
                $maps[] = ['format' => $format, 'offset' => $offset];
            }
        }

        usort($maps, static fn (array $a, array $b): int => $b['format'] <=> $a['format']);

        if ($maps === []) {
            throw new RuntimeException('Font '.$path.' không có bảng ký tự Unicode đọc được (cmap 4 hoặc 12).');
        }

        return $maps;
    }

    /** @return int the glyph of a character in the first map that has it, 0 when none does */
    private function glyph(int $code): int
    {
        foreach ($this->maps as $map) {
            $glyph = $map['format'] === 12 ? $this->format12($map['offset'], $code) : $this->format4($map['offset'], $code);

            if ($glyph !== 0) {
                return $glyph;
            }
        }

        return 0;
    }

    private function format4(int $at, int $code): int
    {
        if ($code > 0xFFFF) {
            return 0;
        }

        $segments = intdiv(self::u16($this->bytes, $at + 6), 2);
        $ends = $at + 14;
        $starts = $ends + 2 * $segments + 2;
        $deltas = $starts + 2 * $segments;
        $ranges = $deltas + 2 * $segments;

        for ($i = 0; $i < $segments; $i++) {
            if (self::u16($this->bytes, $ends + 2 * $i) < $code) {
                continue;
            }

            $start = self::u16($this->bytes, $starts + 2 * $i);

            if ($start > $code) {
                return 0;
            }

            $delta = self::u16($this->bytes, $deltas + 2 * $i);
            $range = self::u16($this->bytes, $ranges + 2 * $i);

            if ($range === 0) {
                return ($code + $delta) & 0xFFFF;
            }

            $glyph = self::u16($this->bytes, $ranges + 2 * $i + $range + 2 * ($code - $start));

            return $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
        }

        return 0;
    }

    private function format12(int $at, int $code): int
    {
        for ($i = 0, $groups = self::u32($this->bytes, $at + 12); $i < $groups; $i++) {
            $group = $at + 16 + 12 * $i;
            $start = self::u32($this->bytes, $group);
            $end = self::u32($this->bytes, $group + 4);

            if ($code >= $start && $code <= $end) {
                return self::u32($this->bytes, $group + 8) + $code - $start;
            }
        }

        return 0;
    }

    private static function u16(string $bytes, int $at): int
    {
        if ($at < 0 || $at + 2 > strlen($bytes)) {
            throw new RuntimeException('File font bị cắt cụt hoặc hỏng.');
        }

        return unpack('n', $bytes, $at)[1];
    }

    private static function u32(string $bytes, int $at): int
    {
        if ($at < 0 || $at + 4 > strlen($bytes)) {
            throw new RuntimeException('File font bị cắt cụt hoặc hỏng.');
        }

        return unpack('N', $bytes, $at)[1];
    }
}
