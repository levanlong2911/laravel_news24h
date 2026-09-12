<?php

declare(strict_types=1);

namespace App\Video\Environment;

use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;

final class EnvironmentPlatePrompt
{
    public const VERSION = 'environment-plate-v1';

    public const DEFAULT_SIZE = ImageSize::VERTICAL_2K;

    public const DEFAULT_QUALITY = ImageQuality::LOW;

    public const DEFAULT_MODEL = ImageModel::GPT_IMAGE_2;

    public const DEFAULT_VARIATIONS = ImageVariations::ONE;

    private const FRAME = 'An empty location plate. The place itself is the subject of this image. '
        .'Nothing is being built here and nothing is parked here. Eye-level camera, wide framing, '
        .'natural perspective, the full depth of the space visible.';

    private const NO_VESSEL = 'There is no ship, yacht, boat, hull, vessel, superstructure, keel, '
        .'frame set or shell plating anywhere in this image — neither finished nor under construction, '
        .'neither whole nor in part, neither near nor far.';

    private const NO_OBJECT = 'There is no vehicle, no crane load, no covered shape and no large '
        .'object standing in the space.';

    private const NO_SUBJECT = 'No person is the subject of this frame.';

    public static function text(string $environmentPrompt): string
    {
        $place = trim($environmentPrompt);

        if ($place === '') {
            throw new \InvalidArgumentException('EnvironmentPlatePrompt: environment prompt is empty');
        }

        return implode("\n\n", [
            self::FRAME,
            $place,
            implode(' ', [self::NO_VESSEL, self::NO_OBJECT, self::NO_SUBJECT]),
        ]);
    }
}
