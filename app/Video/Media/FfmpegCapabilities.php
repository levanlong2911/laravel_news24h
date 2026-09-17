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
