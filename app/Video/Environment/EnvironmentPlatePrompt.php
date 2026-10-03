<?php

declare(strict_types=1);

namespace App\Video\Environment;

final class EnvironmentPlatePrompt
{
    public const VERSION = 'environment-plate-v1';

    public const TASK = 'environment_plate';

    private const FRAME = 'An empty location plate. The place itself is the subject of this image. '
        .'Nothing is being built here and nothing is parked here. Eye-level camera, wide framing, '
        .'natural perspective, the full depth of the space visible.';

    private const NO_VESSEL = 'There is no ship, yacht, boat, hull, vessel, superstructure, keel, '
        .'frame set or shell plating anywhere in this image — neither finished nor under construction, '
        .'neither whole nor in part, neither near nor far.';

    private const NO_OBJECT = 'There is no vehicle, no crane load, no covered shape and no large '
        .'object standing in the space.';

    private const NO_SUBJECT = 'No person is the subject of this frame.';

    public const LOCATION_VERSION = 'environment-plate-v2';

    private const LOCATION_FRAME = 'A location plate. The place itself is the subject of this image. '
        .'Eye-level camera, wide framing, natural perspective, the full depth of the space visible.';

    private const LOCATION_LIGHT = 'Light the space evenly and neutrally. This plate does not choose a time of day '
        .'or a weather; show neither sunrise, sunset, night, rain, fog nor snow.';

    private const LOCATION_LOOSE = 'Only the fixed features named above stand in the space: no vehicle, no crane load, '
        .'no covered shape, no loose props and no other large object.';

    /**
     * @param  array{name: string, description: string, spatial_relation: string, layout: string,
     *               connections: list<array{to: string, via: string, into_subject: bool}>,
     *               fixed_features: list<string>, light_sources: list<string>}  $place
     */
    public static function forLocation(array $place, string $subjectName, ?string $buildState): string
    {
        if ($place['spatial_relation'] !== 'external') {
            throw new \InvalidArgumentException('EnvironmentPlatePrompt: only an external location gets a plate');
        }

        $subject = trim($subjectName) === '' ? 'The main subject' : trim($subjectName);
        $state = trim((string) $buildState);

        return implode("\n\n", [
            self::LOCATION_FRAME,
            self::placeBlock($place),
            "{$subject} is not in this image — neither finished nor under construction, neither whole nor "
                .'in part, neither near nor far.'
                .($state === '' ? '' : " Production context: {$state}. Show only the surrounding workspace, access, supports and tools appropriate to this state."),
            implode(' ', [self::LOCATION_LOOSE, self::LOCATION_LIGHT, self::NO_SUBJECT]),
        ]);
    }

    /**
     * @param  array{name: string, description: string, layout: string,
     *               connections: list<array{to: string, via: string, into_subject: bool}>,
     *               fixed_features: list<string>, light_sources: list<string>}  $place
     */
    public static function placeBlock(array $place): string
    {
        $lines = [
            'PLACE: '.$place['name'].' — '.$place['description'],
            'LAYOUT: '.$place['layout'],
        ];

        if ($place['fixed_features'] !== []) {
            $lines[] = 'FIXED FEATURES: '.implode('; ', $place['fixed_features']);
        }

        if ($place['light_sources'] !== []) {
            $lines[] = 'FIXED LIGHT SOURCES: '.implode('; ', $place['light_sources']);
        }

        foreach ($place['connections'] as $connection) {
            if (! $connection['into_subject']) {
                $lines[] = 'OPENS TO '.$connection['to'].': '.$connection['via'];
            }
        }

        return implode("\n", $lines);
    }

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
