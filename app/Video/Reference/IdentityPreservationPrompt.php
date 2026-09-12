<?php

namespace App\Video\Reference;

final class IdentityPreservationPrompt
{
    public const VERSION = 'identity-reference-v1';

    public static function text(): string
    {
        return 'The supplied image is the authoritative record of this object. The render keeps '
            .'the same physical object: the same silhouette, proportions, topology, structural '
            .'relationships, openings, overhangs and construction state, each exactly as the image '
            .'shows. Every permanent feature keeps its existing shape, size and place. The single '
            .'thing this render changes is stated below. Where anything conflicts, the identity in '
            .'the supplied image wins.';
    }

    public static function derivationStatement(string $sourceSha256): string
    {
        return 'horizontal_flip of artifact '.$sourceSha256.' · '.self::VERSION;
    }
}
