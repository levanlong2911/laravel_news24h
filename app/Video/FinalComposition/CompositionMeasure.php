<?php

declare(strict_types=1);

namespace App\Video\FinalComposition;

use App\Video\Media\FfmpegCapabilities;
use App\Video\Media\FfmpegRunner;
use Illuminate\Support\Str;

/**
 * What a finished film measures to: the stretches where the picture is black, where it stands still, where the sound is
 * silent, and how loud the sound is. One decode of the file by ffmpeg; nothing is written and nothing is judged here.
 *
 * A stretch found is only a place to look at: a dip to black, a held frame or a silence can be meant. Whether it is a
 * fault is for the report that knows what the film plan declared, and then for a person.
 */
final class CompositionMeasure
{
    /** Changing what is measured or how the log is read is a new version: a report names the version it was measured with. */
    public const VERSION = 'film-measure-v1';

    /** @var list<string> the filters one measurement needs; a machine without one measures nothing and says which */
    public const FILTERS = ['blackdetect', 'freezedetect', 'silencedetect', 'ebur128'];

    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly FfmpegCapabilities $capabilities,
    ) {}

    /**
     * @param  array{black: array{min_duration_ms: int, pixel_threshold: float, picture_ratio: float}, freeze: array{min_duration_ms: int, noise_db: int|float}, silence: array{min_duration_ms: int, noise_db: int|float}}  $settings  the thresholds of the QC policy
     * @param  int  $durationMs  the length of the film, to close a stretch that runs to the end of the file
     * @return array{ok: bool, error: ?string, black: list<array{start_ms: int, end_ms: int}>, freeze: list<array{start_ms: int, end_ms: int}>, silence: list<array{start_ms: int, end_ms: int}>, loudness: array{integrated_lufs: ?float, range_lu: ?float, true_peak_dbfs: ?float}}
     */
    public function measure(string $file, int $durationMs, array $settings, int $timeoutSeconds): array
    {
        $missing = $this->capabilities->missing(self::FILTERS, [], static fn (): int => $timeoutSeconds);

        if ($missing !== null) {
            return self::failed('ffmpeg thiếu bộ lọc '.$missing);
        }

        $graph = sprintf(
            '[0:v]blackdetect=d=%s:pic_th=%s:pix_th=%s,freezedetect=n=%sdB:d=%s[v];[0:a]silencedetect=n=%sdB:d=%s,ebur128=peak=true:framelog=verbose[a]',
            self::seconds((int) $settings['black']['min_duration_ms']),
            self::number((float) $settings['black']['picture_ratio']),
            self::number((float) $settings['black']['pixel_threshold']),
            self::number((float) $settings['freeze']['noise_db']),
            self::seconds((int) $settings['freeze']['min_duration_ms']),
            self::number((float) $settings['silence']['noise_db']),
            self::seconds((int) $settings['silence']['min_duration_ms']),
        );
        // -v info: the filters report what they find at that level, and ffmpeg writes its log to stderr.
        $run = $this->ffmpeg->run(['-hide_banner', '-nostdin', '-v', 'info', '-i', $file, '-filter_complex', $graph, '-map', '[v]', '-f', 'null', '-', '-map', '[a]', '-f', 'null', '-'], $timeoutSeconds);

        if (! $run->successful) {
            return self::failed('ffmpeg không đo được file: '.Str::limit($run->tail(300), 300));
        }

        $parsed = self::parse($run->stderr, $durationMs);

        // The loudness summary is printed once, at the end of a full decode: without it the file was not read to its end.
        if ($parsed['loudness']['integrated_lufs'] === null && $parsed['loudness']['true_peak_dbfs'] === null) {
            return self::failed('ffmpeg không in kết quả đo loudness — file chưa được đọc hết');
        }

        return ['ok' => true, 'error' => null] + $parsed;
    }

    /**
     * @param  string  $log  what ffmpeg wrote while it measured
     * @return array{black: list<array{start_ms: int, end_ms: int}>, freeze: list<array{start_ms: int, end_ms: int}>, silence: list<array{start_ms: int, end_ms: int}>, loudness: array{integrated_lufs: ?float, range_lu: ?float, true_peak_dbfs: ?float}} every stretch in milliseconds of the film, [start, end); one that was still open when the file ended is closed at the end of the film
     */
    public static function parse(string $log, int $durationMs): array
    {
        $black = [];

        if (preg_match_all('/black_start:([0-9.]+) black_end:([0-9.]+)/', $log, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as $match) {
                $black[] = self::stretch((float) $match[1], (float) $match[2], $durationMs);
            }
        }

        return [
            'black' => $black,
            'freeze' => self::paired($log, '/freezedetect\.freeze_start: ([0-9.]+)/', '/freezedetect\.freeze_end: ([0-9.]+)/', $durationMs),
            'silence' => self::paired($log, '/silence_start: (-?[0-9.]+)/', '/silence_end: ([0-9.]+)/', $durationMs),
            'loudness' => [
                'integrated_lufs' => self::value($log, '/^\s*I:\s+(-?[0-9.]+|-inf|nan) LUFS/m'),
                'range_lu' => self::value($log, '/^\s*LRA:\s+(-?[0-9.]+|-inf|nan) LU\b/m'),
                'true_peak_dbfs' => self::value($log, '/^\s*Peak:\s+(-?[0-9.]+|-inf|nan) dBFS/m'),
            ],
        ];
    }

    /**
     * Stretches a filter reports with one line when they start and another when they end, in the order of the film.
     *
     * @return list<array{start_ms: int, end_ms: int}>
     */
    private static function paired(string $log, string $startPattern, string $endPattern, int $durationMs): array
    {
        $events = [];

        foreach ([[$startPattern, 'start'], [$endPattern, 'end']] as [$pattern, $kind]) {
            if (preg_match_all($pattern, $log, $found, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($found[1] as [$value, $offset]) {
                    $events[] = [$offset, $kind, (float) $value];
                }
            }
        }

        usort($events, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $stretches = [];
        $open = null;

        foreach ($events as [, $kind, $at]) {
            if ($kind === 'start') {
                $open = $at;
            } elseif ($open !== null) {
                $stretches[] = self::stretch($open, $at, $durationMs);
                $open = null;
            }
        }

        if ($open !== null) {
            $stretches[] = self::stretch($open, $durationMs / 1000, $durationMs);
        }

        return array_values(array_filter($stretches, static fn (array $stretch): bool => $stretch['end_ms'] > $stretch['start_ms']));
    }

    /** @return array{start_ms: int, end_ms: int} */
    private static function stretch(float $start, float $end, int $durationMs): array
    {
        return ['start_ms' => max(0, (int) round($start * 1000)), 'end_ms' => min($durationMs, (int) round($end * 1000))];
    }

    /** @return ?float the number the pattern finds last in the log; null when it is not there or is not a number (silence measures to -inf) */
    private static function value(string $log, string $pattern): ?float
    {
        if (preg_match_all($pattern, $log, $found) < 1) {
            return null;
        }

        $last = end($found[1]);

        return is_numeric($last) ? (float) $last : null;
    }

    private static function seconds(int $ms): string
    {
        return self::number($ms / 1000);
    }

    /** @return string a number as ffmpeg reads it in a filter graph, with a dot whatever the locale */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    /** @return array{ok: false, error: string, black: list<never>, freeze: list<never>, silence: list<never>, loudness: array{integrated_lufs: null, range_lu: null, true_peak_dbfs: null}} */
    private static function failed(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'black' => [], 'freeze' => [], 'silence' => [], 'loudness' => ['integrated_lufs' => null, 'range_lu' => null, 'true_peak_dbfs' => null]];
    }
}
