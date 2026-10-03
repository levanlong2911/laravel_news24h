<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

final class LocationProfile
{
    public const EXTERNAL = 'external';

    public const SUBJECT_PART = 'subject_part';

    /** @var list<string> */
    public const RELATIONS = [self::EXTERNAL, self::SUBJECT_PART];

    public const ENCLOSURE_KEY = 'enclosure';

    public const INTERIOR = 'interior';

    public const OPEN_AIR = 'open_air';

    /** @var list<string> */
    public const ENCLOSURES = [self::INTERIOR, self::OPEN_AIR];

    /** @var list<string> */
    public const PROFILE_FIELDS = [
        'story_use',
        'spatial_relation',
        'subject_id',
        'layout',
        'connections',
        'fixed_features',
        'light_sources',
    ];

    /** @param  array<string, mixed>  $location */
    public static function isProfiled(array $location): bool
    {
        foreach (self::PROFILE_FIELDS as $field) {
            if (! array_key_exists($field, $location)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function allProfiled(array $rows): bool
    {
        foreach ($rows as $row) {
            if (! is_array($row) || ! self::isProfiled($row)) {
                return false;
            }
        }

        return $rows !== [];
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  list<array<string, mixed>>  $locations
     * @return array{id: string, name: string, description: string, spatial_relation: string, subject_id: ?string,
     *               layout: string, connections: list<array{to: string, via: string, into_subject: bool}>,
     *               fixed_features: list<string>, light_sources: list<string>}
     */
    public static function forPrompt(array $location, array $locations = []): array
    {
        $names = [];
        $insideSubject = [];

        foreach ($locations as $other) {
            if (is_array($other) && is_string($other['id'] ?? null)) {
                $names[$other['id']] = trim((string) ($other['name'] ?? $other['id']));
                $insideSubject[$other['id']] = ($other['spatial_relation'] ?? null) === self::SUBJECT_PART;
            }
        }

        $connections = [];

        foreach ((array) ($location['connections'] ?? []) as $connection) {
            if (! is_array($connection)) {
                continue;
            }

            $target = (string) ($connection['target_location_id'] ?? '');
            $connections[] = [
                'to' => $names[$target] ?? $target,
                'via' => trim((string) ($connection['via'] ?? '')),
                'into_subject' => $insideSubject[$target] ?? false,
            ];
        }

        $subject = $location['subject_id'] ?? null;

        return [
            'id' => (string) ($location['id'] ?? ''),
            'name' => trim((string) ($location['name'] ?? '')),
            'description' => trim((string) ($location['description'] ?? '')),
            'spatial_relation' => (string) ($location['spatial_relation'] ?? ''),
            'subject_id' => is_string($subject) && $subject !== '' ? $subject : null,
            'layout' => trim((string) ($location['layout'] ?? '')),
            'connections' => $connections,
            'fixed_features' => self::texts($location['fixed_features'] ?? []),
            'light_sources' => self::texts($location['light_sources'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  list<array<string, mixed>>  $locations
     * @return list<string>
     */
    public static function lines(array $location, array $locations = [], string $indent = ''): array
    {
        $lines = [$indent.trim((string) ($location['description'] ?? ''))];

        if (! self::isProfiled($location)) {
            return $lines;
        }

        $place = self::forPrompt($location, $locations);
        $lines[] = $indent.'Story use : '.trim((string) ($location['story_use'] ?? ''));
        $lines[] = $indent.'Relation  : '.$place['spatial_relation']
            .($place['subject_id'] === null ? '' : ' of '.$place['subject_id']);

        if (is_string($location[self::ENCLOSURE_KEY] ?? null)) {
            $lines[] = $indent.'Enclosure : '.str_replace('_', ' ', $location[self::ENCLOSURE_KEY]);
        }

        if (is_string($location[FilmBrief::COVERAGE_SPACE_KEY] ?? null)) {
            $lines[] = $indent.'Brief     : '.$location[FilmBrief::COVERAGE_SPACE_KEY];
        }

        $lines[] = $indent.'Layout    : '.$place['layout'];

        foreach ($place['connections'] as $connection) {
            $lines[] = $indent.'Leads to  : '.$connection['to'].' — '.$connection['via'];
        }

        foreach ($place['fixed_features'] as $feature) {
            $lines[] = $indent.'Fixed     : '.$feature;
        }

        foreach ($place['light_sources'] as $light) {
            $lines[] = $indent.'Light     : '.$light;
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  list<array<string, mixed>>  $locations
     * @return array{subject_id: ?string, name: string, layout: string, fixed_features: list<string>,
     *               connections: list<array{to: string, via: string}>, light_sources: list<string>}|null
     */
    public static function spaceOf(array $location, array $locations = []): ?array
    {
        if (! self::isProfiled($location) || ($location['spatial_relation'] ?? null) !== self::SUBJECT_PART) {
            return null;
        }

        $place = self::forPrompt($location, $locations);

        return [
            'subject_id' => $place['subject_id'],
            'name' => $place['name'],
            'layout' => $place['layout'],
            'fixed_features' => $place['fixed_features'],
            'connections' => array_map(
                static fn (array $connection): array => ['to' => $connection['to'], 'via' => $connection['via']],
                $place['connections'],
            ),
            'light_sources' => $place['light_sources'],
        ] + (in_array($location[self::ENCLOSURE_KEY] ?? null, self::ENCLOSURES, true)
            ? [self::ENCLOSURE_KEY => $location[self::ENCLOSURE_KEY]]
            : []);
    }

    /**
     * @param  array<string, mixed>  $space
     * @return list<string>
     */
    public static function spaceLines(array $space): array
    {
        $layout = trim((string) ($space['layout'] ?? ''));

        if ($layout === '') {
            return [];
        }

        $features = self::texts($space['fixed_features'] ?? []);
        $lights = self::texts($space['light_sources'] ?? []);
        $lines = [
            'SPACE: '.trim((string) ($space['name'] ?? '')).' — '.$layout,
            ...($features === [] ? [] : ['ITS PERMANENT PARTS: '.implode('; ', $features)]),
        ];

        foreach ((array) ($space['connections'] ?? []) as $connection) {
            $to = is_array($connection) ? trim((string) ($connection['to'] ?? '')) : '';
            $via = is_array($connection) ? trim((string) ($connection['via'] ?? '')) : '';

            if ($to !== '' && $via !== '') {
                $lines[] = 'OPENS TO '.$to.': '.$via;
            }
        }

        if ($lights !== []) {
            $lines[] = 'FIXED LIGHT SOURCES (openings and fittings that let light in; the light and weather of this scene are set separately): '
                .implode('; ', $lights);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $scene
     * @return array{light_and_weather: ?string, props: list<string>}
     */
    public static function sceneSetting(array $scene): array
    {
        $light = trim((string) ($scene['light_and_weather'] ?? ''));

        return [
            'light_and_weather' => $light === '' ? null : $light,
            'props' => self::texts($scene['props'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $setting
     * @return list<string>
     */
    public static function settingLines(array $setting): array
    {
        $lines = [];
        $light = trim((string) ($setting['light_and_weather'] ?? ''));
        $props = self::texts($setting['props'] ?? []);

        if ($light !== '') {
            $lines[] = 'LIGHT AND WEATHER: '.$light;
        }

        if ($props !== []) {
            $lines[] = 'PROPS IN THIS SCENE: '.implode('; ', $props);
        }

        return $lines;
    }

    /** @return list<string> */
    private static function texts(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $texts = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $texts[] = trim($value);
            }
        }

        return $texts;
    }
}
