<?php

namespace App\Video\Reference;

final class IdentityPreservationPrompt
{
    public const VERSION = 'identity-reference-v2';

    public static function text(): string
    {
        return 'The supplied image is the authoritative record of every part of this object it shows. '
            .'Each visible part keeps its silhouette, proportions, topology, structural relationships, '
            .'openings, overhangs and construction state exactly as shown, with the same shape, size and '
            .'place. Parts the image does not show follow VIEW GEOMETRY below; where VIEW GEOMETRY is '
            .'silent they continue the visible construction plainly, without new features. The render '
            .'changes only the camera and the surroundings stated below.';
    }

    public static function derivationStatement(string $sourceSha256): string
    {
        return 'horizontal_flip of artifact '.$sourceSha256.' · '.self::VERSION;
    }
}
