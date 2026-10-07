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

    public const LOCATION_VERSION = 'environment-plate-v3';

    private const LOCATION_FRAME = 'A location plate. The place itself is the subject of this image. '
        .'Eye-level camera, wide framing, natural perspective, the full depth of the space visible.';

    private const LOCATION_LIGHT = 'Light the space evenly and neutrally. This plate does not choose a time of day '
        .'or a weather; show neither sunrise, sunset, night, rain, fog nor snow.';

    private const LOCATION_LOOSE = 'Only the fixed features named above stand in the space: no vehicle, no crane load, '
        .'no covered shape, no loose props and no other large object.';

    public const ROOM_VERSION = 'environment-room-v3';

    public const ROOM_UNFITTED = 'unfitted';

    public const ROOM_FITTED = 'fitted';

    private const ROOM_FRAME = 'A room plate. One room aboard %s is the subject of this image: its walls, floor, ceiling, '
        .'openings and fixed features. Eye-level camera, wide framing, natural perspective, the full depth of the room visible, '
        .'the full width between the port and starboard openings visible, the room reads broad, not as a corridor.';

    private const ROOM_BEFORE_FIT_OUT = 'The room is before fit-out: bare structure, openings and fixed structural features only; '
        .'no wall panels, no floor finish, no furniture or fittings.';

    private const ROOM_THROUGH_OPENINGS = 'Spaces seen through openings and doorways are empty.';

    private const ROOM_LOOSE = 'Only the fixed features named above stand in the room: no loose props, no luggage, no tools '
        .'and no other object.';

    private const ROOM_FITTED_LOOSE = 'Only the fixed features and fitted furniture named above stand in the room: no loose props, '
        .'no luggage, no tools and no other object.';

    private const ROOM_EMPTY = 'No person is in the room.';

    /**
     * @param  array{name: string, description: string, spatial_relation: string, layout: string,
     *               connections: list<array{to: string, via: string, into_subject: bool}>,
     *               fixed_features: list<string>, light_sources: list<string>}  $place
     */
    public static function forLocation(array $place, string $subjectName): string
    {
        if ($place['spatial_relation'] !== 'external') {
            throw new \InvalidArgumentException('EnvironmentPlatePrompt: only an external location gets a plate');
        }

        $subject = trim($subjectName) === '' ? 'The main subject' : trim($subjectName);

        return implode("\n\n", [
            self::LOCATION_FRAME,
            self::placeBlock($place),
            "{$subject} is not in this image — neither finished nor under construction, neither whole nor "
                .'in part, neither near nor far.',
            implode(' ', [self::LOCATION_LOOSE, self::LOCATION_LIGHT, self::NO_SUBJECT]),
        ]);
    }

    /**
     * @param  array{name: string, description: string, spatial_relation: string, layout: string,
     *               connections: list<array{to: string, via: string, into_subject: bool}>,
     *               fixed_features: list<string>, light_sources: list<string>}  $place
     * @param  array{materials_and_light?: string, fixed_furniture?: string}|null  $fitted
     */
    public static function forRoom(array $place, string $subjectName, string $phase, ?array $fitted = null): string
    {
        if ($place['spatial_relation'] !== 'subject_part') {
            throw new \InvalidArgumentException('EnvironmentPlatePrompt: only a room of the subject gets a room plate');
        }

        $subject = trim($subjectName) === '' ? 'the main subject' : trim($subjectName);
        $design = [];

        if ($phase === self::ROOM_FITTED) {
            foreach (['MATERIALS AND LIGHT' => 'materials_and_light', 'FITTED FURNITURE' => 'fixed_furniture'] as $heading => $field) {
                $text = trim((string) ($fitted[$field] ?? ''));

                if ($text !== '') {
                    $design[] = $heading.': '.$text;
                }
            }
        }

        return implode("\n\n", array_values(array_filter([
            sprintf(self::ROOM_FRAME, $subject),
            self::placeBlock($place, true),
            $design === [] ? null : implode("\n", $design),
            $phase === self::ROOM_UNFITTED ? self::ROOM_BEFORE_FIT_OUT : null,
            implode(' ', [
                $design === [] ? self::ROOM_LOOSE : self::ROOM_FITTED_LOOSE,
                self::ROOM_THROUGH_OPENINGS,
                self::LOCATION_LIGHT,
                self::ROOM_EMPTY,
            ]),
        ])));
    }

    /**
     * @param  array{name: string, description: string, layout: string,
     *               connections: list<array{to: string, via: string, into_subject: bool}>,
     *               fixed_features: list<string>, light_sources: list<string>}  $place
     */
    public static function placeBlock(array $place, bool $insideSubject = false): string
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
            if ($insideSubject || ! $connection['into_subject']) {
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
