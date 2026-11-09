<?php

declare(strict_types=1);

namespace App\Video\FinalComposition;

/**
 * What a film adds on top of the cut of its clips: a fade to black and back inside a clip for a dip, and the text cards.
 * A composition without it builds the same graph as before, byte for byte.
 *
 * Every span is in frames of the output, [start, end). The fades sit inside the frames a clip keeps, so the film keeps its length;
 * the cards are drawn on the cut film, over any fade, each line from its own text file with expansion off, so the text is drawn as it is.
 */
final class CompositionDecor
{
    /** @var string the version of the graph this class and CompositionCommand build for a film; a film plan names it, so a change shows as a page to reload */
    public const VERSION = 'laravel-ffmpeg-moto-film-v1';

    /**
     * @param  list<array{frames: int, fade_in: int, fade_out: int, has_audio: bool}>  $clips  one per clip, in the order of the plan
     * @param  list<array{start_frame: int, end_frame: int, lines: list<array{text: string, baseline_px: int}>}>  $cards
     * @param  ?array{source: string, sha256: string, size_px: int, colour: string, border_colour: string, border_px: int}  $font  null when there is no card
     * @param  ?string  $fontPath  the copy of the font in the workspace, once materialised
     * @param  list<list<string>>  $textPaths  the text file of every line of every card, once materialised
     */
    public function __construct(
        public readonly array $clips,
        public readonly array $cards,
        public readonly ?array $font,
        public readonly ?string $fontPath = null,
        public readonly array $textPaths = [],
    ) {}

    /** @return list<string> the filters this decor needs on top of a plain cut */
    public function filters(): array
    {
        $fades = array_filter($this->clips, static fn (array $clip): bool => $clip['fade_in'] > 0 || $clip['fade_out'] > 0);

        return [...($fades === [] ? [] : ['fade']), ...($this->cards === [] ? [] : ['drawtext'])];
    }

    /**
     * The plan the builder made from the copies must be the one the film plan counted: the same clips with the same frames, joined by cuts only.
     *
     * @throws CompositionRefused
     */
    public function check(CompositionPlan $plan): void
    {
        if (count($plan->clips) !== count($this->clips)) {
            throw new CompositionRefused(sprintf('ke hoach phim co %d clip, ban ghep dung %d', count($this->clips), count($plan->clips)));
        }

        foreach ($plan->transitions as $i => $transition) {
            if (! $transition->isCut()) {
                throw new CompositionRefused(sprintf('moi noi %d khong phai cut — phim khong chong hai clip', $i + 1));
            }
        }

        foreach ($plan->clips as $i => $clip) {
            $wanted = $this->clips[$i];

            if ($clip->frames !== $wanted['frames']) {
                throw new CompositionRefused(sprintf('clip %d: ke hoach phim dem %d khung, ban ghep dem %d', $i + 1, $wanted['frames'], $clip->frames));
            }

            if ($clip->hasAudio !== $wanted['has_audio']) {
                throw new CompositionRefused(sprintf('clip %d: ke hoach phim ghi %s, ban sao do duoc %s', $i + 1, $wanted['has_audio'] ? 'co tieng' : 'im lang', $clip->hasAudio ? 'co tieng' : 'im lang'));
            }

            if ($wanted['fade_in'] < 0 || $wanted['fade_out'] < 0 || ($wanted['fade_in'] + $wanted['fade_out'] > 0 && $wanted['fade_in'] + $wanted['fade_out'] >= $clip->frames)) {
                throw new CompositionRefused(sprintf('clip %d: dip %d+%d khung khong vua %d khung', $i + 1, $wanted['fade_in'], $wanted['fade_out'], $clip->frames));
            }
        }

        $total = $plan->expectedFrames();

        foreach ($this->cards as $i => $card) {
            if ($card['start_frame'] < 0 || $card['end_frame'] <= $card['start_frame'] || $card['end_frame'] > $total || $card['lines'] === []) {
                throw new CompositionRefused(sprintf('card %d: khung %d..%d ngoai phim %d khung', $i + 1, $card['start_frame'], $card['end_frame'], $total));
            }
        }

        if (($this->cards === []) !== ($this->font === null)) {
            throw new CompositionRefused('card va font phai di cung nhau');
        }
    }

    /**
     * Copies the font into the workspace and checks the copy, then writes each line of each card to its own file.
     *
     * @throws CompositionRefused
     */
    public function materialise(CompositionWorkspace $workspace, Deadline $deadline): self
    {
        if ($this->cards === []) {
            return $this;
        }

        $font = $workspace->path('card-font.ttf');

        if (! @copy((string) $this->font['source'], $font)) {
            throw new CompositionRefused('khong sao duoc font cua card vao thu muc luot chay');
        }

        $actual = ChunkedDigest::sha256($font, $deadline, 'font cua card');

        if ($actual === null || ! hash_equals((string) $this->font['sha256'], $actual)) {
            throw new CompositionRefused('ban sao font cua card khong khop hash kieu card da ghim');
        }

        $paths = [];

        foreach ($this->cards as $i => $card) {
            foreach ($card['lines'] as $j => $line) {
                $path = $workspace->path(sprintf('card-%02d-%d.txt', $i + 1, $j + 1));

                if (@file_put_contents($path, $line['text']) !== strlen($line['text'])) {
                    throw new CompositionRefused(sprintf('khong ghi duoc chu cua card %d', $i + 1));
                }

                $paths[$i][$j] = $path;
            }
        }

        return new self($this->clips, $this->cards, $this->font, $font, $paths);
    }

    /** @return string what follows the chain of a clip once it is cut to its frames: the fades of a dip, inside those frames */
    public function fades(int $i, int $frames): string
    {
        $clip = $this->clips[$i];
        $tail = '';

        if ($clip['fade_in'] > 0) {
            $tail .= sprintf(',fade=t=in:s=0:n=%d:c=black', $clip['fade_in']);
        }

        if ($clip['fade_out'] > 0) {
            $tail .= sprintf(',fade=t=out:s=%d:n=%d:c=black', $frames - $clip['fade_out'], $clip['fade_out']);
        }

        return $tail;
    }

    public function hasCards(): bool
    {
        return $this->cards !== [];
    }

    /**
     * @return string the cards drawn on the cut film, one drawtext a line, shown on frames start..end-1 of the output
     *
     * @throws CompositionRefused when the decor was not materialised
     */
    public function overlay(string $in, string $out): string
    {
        if ($this->fontPath === null) {
            throw new CompositionRefused('card chua duoc ghi ra thu muc luot chay');
        }

        $font = (array) $this->font;
        $draws = [];

        foreach ($this->cards as $i => $card) {
            foreach ($card['lines'] as $j => $line) {
                $draws[] = sprintf(
                    "drawtext=fontfile=%s:textfile=%s:expansion=none:fontsize=%d:fontcolor=%s:borderw=%d:bordercolor=%s:x=(w-text_w)/2:y=%d-max_glyph_a:enable='between(n,%d,%d)'",
                    self::quote($this->fontPath), self::quote($this->textPaths[$i][$j]),
                    $font['size_px'], $font['colour'], $font['border_px'], $font['border_colour'],
                    $line['baseline_px'], $card['start_frame'], $card['end_frame'] - 1,
                );
            }
        }

        return $in.implode(',', $draws).$out;
    }

    /**
     * A path as a filter option: forward slashes, quoted for the graph, its colons escaped for the option.
     *
     * @throws CompositionRefused for a path the quoting cannot carry
     */
    public static function quote(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (str_contains($path, "'") || preg_match('/[\x00-\x1f]/', $path) === 1) {
            throw new CompositionRefused('duong dan khong dua vao filter graph duoc: '.$path);
        }

        return "'".str_replace(':', '\\:', $path)."'";
    }
}
