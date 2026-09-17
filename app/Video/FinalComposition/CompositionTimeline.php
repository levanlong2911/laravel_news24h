<?php

namespace App\Video\FinalComposition;

/**
 * Phep tinh dong thoi gian: tu so khung tung clip va do chong lan, ra vi tri va do
 * dai cua moi cat canh.
 *
 * Song rieng vi co HAI duong can no. Duong chay that di tu `CompositionPlan` (clip
 * da probe xong); doi phuc hoi di tu manifest da chot, o mot request khac, co the
 * sau khi clip nguon da bi don. Hai duong chep lai cung mot cong thuc la hai ban co
 * the lech nhau, va cai lech do se di thang vao `video_final_renders`.
 *
 * Voi manifest cua duong Laravel, so khung KHONG can probe: `duration_ms` da duoc
 * chot trong manifest, va `frames = round(duration_ms * fps / 1000)` la dung mot
 * phep tinh — probe chi de kiem nguon co du dai hay khong.
 */
final class CompositionTimeline
{
    /**
     * @param  list<int>  $frames  so khung tung clip
     * @param  list<int>  $overlaps  chong lan giua cac clip, `count($frames) - 1` phan
     *                               tu, 0 la cat thang
     */
    public function __construct(
        private readonly array $frames,
        private readonly array $overlaps,
        private readonly int $fps,
        private readonly int $sampleRate,
    ) {}

    /**
     * Dung tu MANIFEST DA CHOT, khong cham vao file nao.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws CompositionRefused
     */
    public static function fromManifest(array $manifest, int $sampleRate = 48000): self
    {
        $fps = $manifest['output']['fps'] ?? null;
        $entries = $manifest['clips'] ?? null;

        if (! is_int($fps) || $fps < 1) {
            throw new CompositionRefused('manifest da chot khong co `output.fps` hop le');
        }

        if (! is_array($entries) || $entries === []) {
            throw new CompositionRefused('manifest da chot khong co clip nao');
        }

        $frames = [];
        $overlaps = [];
        $last = count($entries) - 1;

        foreach (array_values($entries) as $i => $entry) {
            $duration = is_array($entry) ? ($entry['duration_ms'] ?? null) : null;

            // Thieu `duration_ms` thi so khung phai suy tu nguon, ma nguon co the da
            // khong con. Tu choi thay vi doan — mot timeline doan ra la mot timeline
            // khong doi chieu duoc voi ban da ghep.
            if (! is_int($duration) || $duration < 1) {
                throw new CompositionRefused(sprintf(
                    'clip %d trong manifest da chot khong co `duration_ms` — khong suy duoc dong thoi gian', $i + 1,
                ));
            }

            $frames[] = (int) round($duration * $fps / 1000);

            if ($i < $last) {
                $overlaps[] = self::overlap($entry, $i);
            }
        }

        return new self($frames, $overlaps, $fps, $sampleRate);
    }

    /**
     * Tong khung TRU tong chong lan.
     *
     * Khong phai tong khung nguon: `xfade` lam mat khung CO CHU DICH, va mot phep
     * kiem lay tong nguon lam ky vong se do oan dung luc ghep thanh cong.
     */
    public function total(): int
    {
        return array_sum($this->frames) - array_sum($this->overlaps);
    }

    /**
     * So mau tieng, suy tu CHINH `total()`.
     *
     * Khong tinh doc lap tu do dai cac clip: hai con so di ra tu hai duong khac nhau
     * roi tinh co bang nhau la mot phep kiem khong kiem gi ca.
     */
    public function audioSamples(): int
    {
        return (int) round($this->total() * $this->sampleRate / $this->fps);
    }

    /**
     * Vi tri va do dai cua tung clip TREN DONG THOI GIAN dau ra.
     *
     * `start_ms` la VI TRI, khong phai offset cat nguon. Voi chuyen canh mo, clip ke
     * bat dau SOM hon: no leo len phan chong lan cua clip truoc.
     *
     * @return list<array{start_ms: int, duration_ms: int}>
     */
    public function rows(): array
    {
        $rows = [];
        $startFrames = 0;

        foreach ($this->frames as $i => $frames) {
            $rows[] = [
                'start_ms' => (int) round($startFrames * 1000 / $this->fps),
                'duration_ms' => (int) round($frames * 1000 / $this->fps),
            ];

            $startFrames += $frames - ($this->overlaps[$i] ?? 0);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $entry
     *
     * @throws CompositionRefused
     */
    private static function overlap(array $entry, int $i): int
    {
        $transition = $entry['transition_after'] ?? null;

        if ($transition === null) {
            return 0;
        }

        if (! is_array($transition)) {
            throw new CompositionRefused(sprintf('moi noi %d trong manifest da chot khong doc duoc', $i + 1));
        }

        if (($transition['type'] ?? null) === CompositionTransition::CUT) {
            return 0;
        }

        $frames = $transition['frames'] ?? null;

        if (! is_int($frames) || $frames < 1) {
            throw new CompositionRefused(sprintf('moi noi %d trong manifest da chot thieu so khung', $i + 1));
        }

        return $frames;
    }
}
