<?php

namespace App\Video\FinalComposition;

/**
 * Mot moi noi giua hai clip.
 *
 * Do bang SO KHUNG chu khong bang mili giay: chong lan phai roi dung vao bien khung
 * o FPS dau ra, va mot con so mili giay se phai lam tron o dau do — sai mot khung
 * la lech ca phan con lai cua timeline.
 */
final class CompositionTransition
{
    public const CUT = 'cut';

    public const CROSSFADE = 'crossfade';

    public function __construct(
        public readonly string $type,
        public readonly int $frames,
    ) {}

    public static function cut(): self
    {
        return new self(self::CUT, 0);
    }

    public static function crossfade(int $frames): self
    {
        return new self(self::CROSSFADE, $frames);
    }

    public function isCut(): bool
    {
        return $this->type === self::CUT;
    }
}
