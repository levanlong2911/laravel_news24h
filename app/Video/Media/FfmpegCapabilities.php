<?php

namespace App\Video\Media;

/**
 * ffmpeg co san bo loc va encoder can dung khong.
 *
 * Dung sau `FfmpegRunner` chu khong goi thang `FfmpegBinary`: noi dung no la
 * `CompositionExecutor`, va executor phai tiem duoc ban gia de kiem duong "thieu bo
 * loc thi tu choi TRUOC khi cham vao file".
 *
 * Thieu mot bo loc khong ton tien — ffmpeg chay cuc bo — nhung ton ca mot luot
 * encode roi moi bao, va luot do co the dai hang chuc giay.
 */
final class FfmpegCapabilities
{
    /** @var array<string, list<string>> */
    private array $lists = [];

    /** @var string the option that reads a filter graph from a file, before FFmpeg 7 */
    public const GRAPH_SCRIPT = '-filter_complex_script';

    /** @var string the same from FFmpeg 7, which deprecated the old one; FFmpeg 9 no longer has the old one */
    public const GRAPH_FILE = '-/filter_complex';

    private ?string $graphOption = null;

    /**
     * The option this ffmpeg reads a graph file with: -/filter_complex from 7.0 on, and for a build from git master, which carries
     * no release number; -filter_complex_script before 7.0, and whenever the version cannot be read, as it always was.
     *
     * @param  (callable(): ?int)|null  $budget  seconds left
     */
    public function graphFileOption(?callable $budget = null): string
    {
        return $this->graphOption ??= self::graphOptionFor($this->ffmpeg->run(['-hide_banner', '-version'], $budget === null ? 30 : $budget())->stdout);
    }

    /** @param string $version the output of ffmpeg -version */
    public static function graphOptionFor(string $version): string
    {
        if (preg_match('/^ffmpeg version (?:n)?(\d+)\./m', $version, $match) === 1) {
            return (int) $match[1] >= 7 ? self::GRAPH_FILE : self::GRAPH_SCRIPT;
        }

        return preg_match('/^ffmpeg version N-\d+/m', $version) === 1 ? self::GRAPH_FILE : self::GRAPH_SCRIPT;
    }

    public function __construct(private readonly FfmpegRunner $ffmpeg) {}

    /**
     * Ten dau tien THIEU, hoac `null` neu co du.
     *
     * @param  list<string>  $filters
     * @param  list<string>  $encoders
     */
    /**
     * @param  list<string>  $filters
     * @param  list<string>  $encoders
     * @param  (callable(): ?int)|null  $budget  giay con lai, TINH LAI truoc tung lenh
     *                                           con — mot con so truyen vao mot lan
     *                                           se cap lai nguyen ngan sach cho lenh
     *                                           thu hai du lenh dau da tieu gan het
     */
    public function missing(array $filters, array $encoders, ?callable $budget = null): ?string
    {
        foreach ([['-filters', $filters], ['-encoders', $encoders]] as [$flag, $wanted]) {
            // Doc moi ban liet ke DUNG MOT lan: kiem sau bo loc bang sau lan goi
            // ffmpeg la sau lan khoi tao tien trinh cho mot cau hoi khong doi.
            $have = $this->lists[$flag] ??= $this->read($flag, $budget === null ? null : $budget());

            foreach ($wanted as $name) {
                if (! in_array($name, $have, true)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function read(string $flag, ?int $timeoutSeconds): array
    {
        // Preflight cung an vao ngan sach cua luot chay: mot han muc co dinh 30 giay
        // o day nghia la tong thoi gian vuot ngan sach ma tung lenh van "trong han".
        $result = $this->ffmpeg->run(['-hide_banner', $flag], $timeoutSeconds ?? 30);

        if (! $result->successful) {
            return [];
        }

        // Cot thu hai cua moi dong la TEN. `str_contains` tren ca ban liet ke se noi
        // "co xfade" chi vi mot dong mo ta nao do nhac toi no.
        preg_match_all('/^\s*[A-Z.]+\s+(\S+)/m', $result->stdout, $matches);

        return $matches[1];
    }
}
