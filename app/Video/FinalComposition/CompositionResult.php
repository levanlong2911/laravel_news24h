<?php

namespace App\Video\FinalComposition;

/**
 * Ket qua mot luot ghep.
 *
 * `successful` khong suy ra tu "ffmpeg tra 0": ffmpeg tra 0 ma sinh file rong hoac
 * doi codec la chuyen co that. Chi `CompositionOutputVerifier` moi dat duoc co nay.
 */
final class CompositionResult
{
    /** @param list<string> $reasons */
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $path,
        public readonly ?int $frames,
        public readonly ?int $audioSamples,
        public readonly ?int $bytes,
        public readonly ?string $sha256,
        public readonly array $reasons,
        /** @var list<array{start_ms: int, duration_ms: int}> */
        public readonly array $timeline = [],
    ) {}

    /** @param list<string> $reasons */
    public static function refused(array $reasons): self
    {
        return new self(false, null, null, null, null, null, $reasons, []);
    }

    /** @param list<array{start_ms: int, duration_ms: int}> $timeline */
    public static function composed(
        string $path,
        int $frames,
        int $audioSamples,
        int $bytes,
        string $sha256,
        array $timeline,
    ): self {
        return new self(true, $path, $frames, $audioSamples, $bytes, $sha256, [], $timeline);
    }
}
