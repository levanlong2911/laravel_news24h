<?php

namespace App\Video\FinalComposition;

/**
 * Ban ke hoach ghep, bat bien.
 *
 * Chi duoc dung ra boi `CompositionPlanBuilder`, va chi sau khi moi luat da di qua:
 * mot `CompositionPlan` ton tai la mot ke hoach DA HOP LE.
 */
final class CompositionPlan
{
    /**
     * @param  list<CompositionClip>  $clips
     * @param  list<CompositionTransition>  $transitions  dung `count($clips) - 1` phan tu
     */
    public function __construct(
        public readonly array $clips,
        public readonly array $transitions,
        public readonly CompositionProfile $profile,
    ) {}

    /** Ky vong de do output, khong con phu thuoc vao nguon co con hay khong. */
    public function expectation(): CompositionExpectation
    {
        return new CompositionExpectation(
            $this->profile, $this->expectedFrames(), $this->expectedAudioSamples(),
        );
    }

    /**
     * Phep tinh dong thoi gian song o `CompositionTimeline`, khong o day.
     *
     * Doi phuc hoi phai tinh ra cung nhung con so nay tu manifest da chot, khi nguon
     * co the da bi don. Hai ban sao cua cung mot cong thuc la hai cach de lech nhau.
     */
    private function line(): CompositionTimeline
    {
        return new CompositionTimeline(
            array_map(static fn (CompositionClip $clip): int => $clip->frames, $this->clips),
            array_map(
                static fn (CompositionTransition $t): int => $t->isCut() ? 0 : $t->frames,
                $this->transitions,
            ),
            $this->profile->fps,
            $this->profile->sampleRate,
        );
    }

    /** So khung dau ra ky vong. */
    public function expectedFrames(): int
    {
        return $this->line()->total();
    }

    /** So mau tieng ky vong. */
    public function expectedAudioSamples(): int
    {
        return $this->line()->audioSamples();
    }

    /**
     * Vi tri va do dai cua tung clip TREN DONG THOI GIAN dau ra.
     *
     * @return list<array{start_ms: int, duration_ms: int}>
     */
    public function timeline(): array
    {
        return $this->line()->rows();
    }

    /** Khung tich luy sau khi da ghep toi clip thu `$index` (tinh tu 0). */
    public function framesThrough(int $index): int
    {
        $frames = $this->clips[0]->frames;

        for ($i = 1; $i <= $index; $i++) {
            $transition = $this->transitions[$i - 1];
            $frames += $this->clips[$i]->frames - ($transition->isCut() ? 0 : $transition->frames);
        }

        return $frames;
    }
}
