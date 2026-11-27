<?php

declare(strict_types=1);

namespace App\Video\Social;

use App\Video\FinalComposition\CompositionDecor;
use App\Video\Moto\Film\MotoFilmFont;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

/**
 * How a cover is made, as a recipe: the picture it starts from by its hash, the frame or the image, the crop, the
 * words and where they sit, the font by its hash, the size it comes out at and the tool. The same recipe on the same
 * source gives the same picture; the recipe is kept with the file, and its hash is part of the package.
 *
 * Code cuts, scales and letters. No model draws, repaints or recognises anything here.
 */
final class SocialArtwork
{
    /** Changing how a recipe is turned into a picture is a new version. */
    public const TOOL = 'social-artwork-v1';

    public const POSITIONS = ['top', 'middle', 'bottom'];

    /** @var array<string, float> the height of the letters as a share of the width of the cover */
    public const SIZES = ['small' => 0.06, 'medium' => 0.08, 'large' => 0.11];

    public const MAX_LINES = 4;

    public const MAX_TEXT = 120;

    /**
     * @param  array{kind: string, sha256: string, at_ms?: int, frame?: int, asset_id?: string, artifact_id?: string}  $source  what the picture is cut from
     * @param  array{width: int, height: int}  $picture  the size of that picture
     * @param  array{width: int, height: int, mime: string}  $cover  the size and the type the format's policy asks of a cover
     * @param  array<string, mixed>  $input  what a person chose: focus_x and focus_y (0–100, where the crop is centred), zoom (100–400), text, position, size
     * @param  ?MotoFilmFont  $font  the pinned font, needed only when there are words
     * @param  array{family: string, sha256: string}  $fontPin
     * @return array<string, mixed> the recipe
     *
     * @throws RuntimeException when a choice cannot be carried out as it is: nothing is shortened, moved or dropped to make it fit
     */
    public static function recipe(array $source, array $picture, array $cover, array $input, ?MotoFilmFont $font, array $fontPin, int $jpegQuality): array
    {
        $text = self::words($input['text'] ?? null);
        $position = (string) ($input['position'] ?? 'bottom');
        $size = (string) ($input['size'] ?? 'medium');

        if ($text !== null && (! in_array($position, self::POSITIONS, true) || ! isset(self::SIZES[$size]))) {
            throw new RuntimeException('Vị trí hoặc cỡ chữ của cover không hợp lệ.');
        }

        if ($text !== null && $font === null) {
            throw new RuntimeException('Không mở được font đã ghim để viết chữ lên cover.');
        }

        return [
            'tool' => self::TOOL,
            'source' => $source,
            'crop' => self::crop($picture, $cover, $input),
            'output' => ['width' => (int) $cover['width'], 'height' => (int) $cover['height'], 'mime' => (string) $cover['mime'], 'jpeg_quality' => $jpegQuality],
            'text' => $text === null ? null : self::lettering($text, $position, (int) round($cover['width'] * self::SIZES[$size]), $cover, $font),
            'font' => $text === null ? null : $fontPin,
        ];
    }

    /** @param array<string, mixed> $recipe */
    public static function hash(array $recipe): string
    {
        return CanonicalJson::hash($recipe);
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @param  string  $input  the film or the image the recipe names
     * @param  list<string>  $textFiles  one file a line of the words, written by the caller, in order
     * @return list<string> the arguments ffmpeg runs to make the cover at `$output`
     */
    public static function arguments(array $recipe, string $input, string $output, ?string $fontPath, array $textFiles, int $fps = 0): array
    {
        $crop = $recipe['crop'];
        $filters = [
            "crop={$crop['width']}:{$crop['height']}:{$crop['x']}:{$crop['y']}",
            "scale={$recipe['output']['width']}:{$recipe['output']['height']}:flags=lanczos:out_range=pc",
        ];

        foreach ((array) ($recipe['text']['lines'] ?? []) as $index => $line) {
            $filters[] = sprintf(
                'drawtext=fontfile=%s:textfile=%s:expansion=none:fontsize=%d:fontcolor=%s:borderw=%d:bordercolor=%s:x=(w-text_w)/2:y=%d-max_glyph_a',
                CompositionDecor::quote((string) $fontPath), CompositionDecor::quote($textFiles[$index]),
                $recipe['text']['size_px'], $recipe['text']['colour'], $recipe['text']['border_px'], $recipe['text']['border_colour'], $line['baseline_px'],
            );
        }

        $filters[] = 'format=yuvj420p';
        $seek = [];

        if ($recipe['source']['kind'] === 'film_frame') {
            // ffmpeg starts at the first frame whose time is not before the seek: half a frame before the wanted one lands on it and on no other.
            $frame = (int) $recipe['source']['frame'];
            $seek = ['-ss', number_format($frame === 0 || $fps < 1 ? 0 : ($frame - 0.5) / $fps, 6, '.', '')];
        }

        return ['-hide_banner', '-nostdin', '-v', 'error', ...$seek, '-i', $input, '-frames:v', '1', '-vf', implode(',', $filters), '-q:v', (string) self::qscale((int) $recipe['output']['jpeg_quality']), '-y', $output];
    }

    /** @return int the frame of a film a time falls on */
    public static function frameAt(int $atMs, float $fps): int
    {
        return (int) floor($atMs * $fps / 1000 + 1e-9);
    }

    /**
     * @param  array{width: int, height: int}  $picture
     * @param  array{width: int, height: int}  $cover
     * @param  array<string, mixed>  $input
     * @return array{x: int, y: int, width: int, height: int} the part of the picture the cover shows: the shape of the cover, as large as the zoom leaves it, centred on the focus and moved back inside the picture where it would stick out
     */
    private static function crop(array $picture, array $cover, array $input): array
    {
        $focusX = self::number($input['focus_x'] ?? 50, 0, 100, 'Tiêu điểm ngang');
        $focusY = self::number($input['focus_y'] ?? 50, 0, 100, 'Tiêu điểm dọc');
        $zoom = self::number($input['zoom'] ?? 100, 100, 400, 'Mức phóng');
        $ratio = $cover['width'] / $cover['height'];
        $width = min($picture['width'], (int) floor($picture['height'] * $ratio));
        $height = min($picture['height'], (int) floor($width / $ratio));
        $width = max(2, (int) floor($width * 100 / $zoom / 2) * 2);
        $height = max(2, (int) floor($height * 100 / $zoom / 2) * 2);

        return [
            'x' => (int) max(0, min($picture['width'] - $width, round($picture['width'] * $focusX / 100 - $width / 2))),
            'y' => (int) max(0, min($picture['height'] - $height, round($picture['height'] * $focusY / 100 - $height / 2))),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @param  array{width: int, height: int}  $cover
     * @return array<string, mixed> the words broken into lines between words, each with where its baseline sits
     *
     * @throws RuntimeException when the font lacks a letter, a word is wider than the cover, or the lines do not fit
     */
    private static function lettering(string $text, string $position, int $size, array $cover, MotoFilmFont $font): array
    {
        $missing = $font->missingGlyphs($text);

        if ($missing !== []) {
            throw new RuntimeException('Font của cover không có glyph cho: '.implode(' ', $missing).'.');
        }

        $border = max(2, (int) round($size / 14));
        $margin = (int) round($cover['width'] * 0.07);
        $box = $cover['width'] - 2 * $margin;
        $lines = [];

        foreach (explode(' ', $text) as $word) {
            $last = count($lines) - 1;
            $joined = $last < 0 ? $word : $lines[$last].' '.$word;

            if ($last >= 0 && $font->width($joined, $size) + 2 * $border <= $box) {
                $lines[$last] = $joined;

                continue;
            }

            if ($font->width($word, $size) + 2 * $border > $box) {
                throw new RuntimeException('Từ "'.$word.'" rộng hơn cover ở cỡ chữ này — chọn cỡ nhỏ hơn hoặc đổi chữ.');
            }

            $lines[] = $word;
        }

        if (count($lines) > self::MAX_LINES) {
            throw new RuntimeException('Chữ cần '.count($lines).' dòng, cover cho tối đa '.self::MAX_LINES.' — rút chữ hoặc chọn cỡ nhỏ hơn.');
        }

        $lineHeight = (int) round($size * 1.2);
        $block = (count($lines) - 1) * $lineHeight + $size;
        $edge = (int) round($cover['height'] * 0.1);
        $top = match ($position) {
            'top' => $edge,
            'middle' => intdiv($cover['height'] - $block, 2),
            default => $cover['height'] - $edge - $block,
        };

        if ($top < 0 || $top + $block > $cover['height']) {
            throw new RuntimeException('Chữ cao hơn cover ở cỡ này — rút chữ hoặc chọn cỡ nhỏ hơn.');
        }

        return [
            'value' => $text,
            'position' => $position,
            'size_px' => $size,
            'colour' => 'white',
            'border_colour' => 'black',
            'border_px' => $border,
            'lines' => array_map(static fn (string $line, int $index): array => ['text' => $line, 'baseline_px' => $top + $size + $index * $lineHeight], $lines, array_keys($lines)),
        ];
    }

    /** @return ?string the words of a cover on one line, single spaces between them; null for none */
    private static function words(mixed $text): ?string
    {
        $text = is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', $text)) : '';

        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new RuntimeException('Chữ trên cover dài quá '.self::MAX_TEXT.' ký tự.');
        }

        return $text === '' ? null : $text;
    }

    private static function number(mixed $value, int $min, int $max, string $name): int
    {
        if (! is_numeric($value) || (int) $value < $min || (int) $value > $max) {
            throw new RuntimeException("{$name} phải nằm trong {$min}–{$max}.");
        }

        return (int) $value;
    }

    /** @return int the quantiser ffmpeg writes a JPEG of that quality with: 2 is the finest, 31 the coarsest */
    private static function qscale(int $quality): int
    {
        return max(2, min(31, (int) round((100 - $quality) / 3)));
    }
}
