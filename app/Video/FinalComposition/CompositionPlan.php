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

    /**
     * So khung dau ra ky vong.
     *
     * Tong khung TRU tong chong lan. Khong phai tong khung nguon: `xfade` lam mat
     * khung CO CHU DICH, va mot phep kiem lay tong nguon lam ky vong se do oan dung
     * luc ghep thanh cong.
     */
    public function expectedFrames(): int
    {
        $frames = 0;

        foreach ($this->clips as $clip) {
            $frames += $clip->frames;
        }

        foreach ($this->transitions as $transition) {
            $frames -= $transition->isCut() ? 0 : $transition->frames;
        }

        return $frames;
    }

    /**
     * So mau tieng ky vong, suy tu CHINH `expectedFrames()`.
     *
     * Khong tinh doc lap tu do dai cac clip: hai con so di ra tu hai duong khac nhau
     * roi tinh co bang nhau la mot phep kiem khong kiem gi ca.
     */
    public function expectedAudioSamples(): int
    {
        return (int) round(
            $this->expectedFrames() * $this->profile->sampleRate / $this->profile->fps,
        );
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
