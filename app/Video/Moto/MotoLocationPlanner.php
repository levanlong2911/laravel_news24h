<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoLocationPlanner
{
    public const VERSION = 'moto-locations-plan-v1';

    /** @var string the plan of a location dossier (moto-locations-v3): plates composed from the layout and the conditions of their use, each with the fit code read from the numbers (MotoLocationsCheck::fit) — fits only when at least one comparison was made and none failed or went unverified, with what was verified and what was not; a plan of VERSION is built exactly as before */
    public const DOSSIER_VERSION = 'moto-locations-plan-v2';

    /** @var string the plan of a set design dossier (moto-locations-v4): the plan of a dossier whose plate prompts are joined by code from the one design of the place, the state its set stands in for the plate, the camera and the lighting plan — no description the answer wrote a second time */
    public const SET_DESIGN_VERSION = 'moto-locations-plan-v3';

    /** @var list<string> the kinds of place rendered in the style of open ground */
    private const OPEN_KINDS = ['yard', 'road'];

    /**
     * @param  array<string, mixed>  $reference  video.moto.locations.reference
     * @param  string  $version  the planner version whose look is asked for
     * @return array<string, string> the look code gives every plate of a dossier of that planner, by plate style
     */
    public static function style(array $reference, string $version = self::DOSSIER_VERSION): array
    {
        return array_map('strval', (array) ($reference['variants'][$version]['style'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $document  a moto Phase 5 document that passed its schema
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return array{version: string, locations: list<array<string, mixed>>, images: list<array<string, mixed>>, unplated: list<array<string, mixed>>, unresolved: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, continuity: list<array<string, mixed>>, script_continuity: list<array<string, mixed>>} the places of the answer, then every plate in the order the script first uses it, each with the parts its prompt is built from; only a blocking conflict holds a plate back, in the shape the Locations screen and the shot hand-off read
     */
    public static function plan(array $document, array $context): array
    {
        $setDesign = ($context['format'] ?? null) === MotoContract::LOCATIONS_FORMAT_V4;
        $data = $setDesign ? MotoSetDesign::normalize((array) $document['data']) : (array) $document['data'];
        $places = array_column(array_values((array) $data['locations']), null, 'id');
        $position = array_flip(array_column((array) $context['beats'], 'id'));
        $blocking = array_values(array_map(self::conflict(...), array_filter((array) $data['conflicts'], static fn (array $conflict): bool => (bool) $conflict['blocking'])));
        $continuity = array_values((array) $data['continuity']);
        $backgrounds = array_values((array) $data['backgrounds']);
        $first = static fn (array $background): int => min(array_map(static fn (array $use): int => $position[$use['beat']] ?? PHP_INT_MAX, (array) $background['uses']));
        usort($backgrounds, static fn (array $a, array $b): int => $first($a) <=> $first($b));
        $rows = [];
        $dossier = $setDesign || ($context['format'] ?? null) === MotoContract::LOCATIONS_FORMAT_V3;
        $fit = $dossier ? MotoLocationsCheck::fit($data, $context) : ['findings' => [], 'verified' => [], 'skipped' => []];
        $usability = $fit['findings'];
        $held = array_values(array_map(static fn (array $finding): array => array_intersect_key($finding, array_flip(['kind', 'beats', 'backgrounds', 'evidence', 'blocking', 'note'])) + ['origin' => 'code'], array_filter($usability, static fn (array $finding): bool => $finding['blocking'])));

        foreach ($backgrounds as $background) {
            $id = (string) $background['id'];
            $beats = array_values(array_unique(array_column((array) $background['uses'], 'beat')));
            $mine = [
                ...array_values(array_filter($blocking, static fn (array $conflict): bool => in_array($id, $conflict['backgrounds'], true) || array_intersect($beats, $conflict['beats']) !== [])),
                ...array_map(self::conflict(...), array_values(array_filter($held, static fn (array $conflict): bool => in_array($id, $conflict['backgrounds'], true)))),
            ];
            $place = $places[$background['location']] ?? null;
            $read = array_values(array_filter($usability, static fn (array $finding): bool => in_array($id, $finding['backgrounds'], true)));
            $zone = $dossier && $background['subject_zone'] !== null ? (array_column((array) ($place['zones'] ?? []), null, 'id')[$background['subject_zone']] ?? null) : null;
            $passed = array_values((array) ($fit['verified'][$id] ?? []));
            $rows[] = [
                'id' => $id,
                'name' => (string) $background['name'],
                'kind' => 'location',
                'style' => ($dossier ? ($place['setting'] ?? null) === 'exterior' : in_array($place['kind'] ?? null, self::OPEN_KINDS, true)) ? 'open_ground' : 'location',
                'source' => ['type' => 'location', 'id' => (string) $background['location'], 'state' => null, 'evidence' => [], 'reason' => null],
                'state' => null,
                'location' => $place,
                'uses' => $beats,
                'parts' => array_map(static fn (array $use): array => $use + ['tour_stop' => null], (array) $background['uses']),
                'sharing' => (string) $background['sharing'],
                'light' => [
                    'description' => $background['light']['description'],
                    'evidence' => $background['light']['evidence'] === null ? null : $background['light']['evidence'] + ['tour_stop' => null],
                    'reason' => $background['light']['reason'],
                ],
                'condition' => [],
                'details' => array_map(static fn (array $detail): array => ['evidence' => $detail['evidence'] === null ? null : $detail['evidence'] + ['tour_stop' => null]] + $detail, (array) $background['details']),
                'support' => array_values((array) $background['support']),
                'ground' => $background['ground'],
                'design_refs' => [],
                'lighting_signature' => false,
                'must_show' => array_values((array) $background['must_show']),
                'avoid' => array_values((array) $background['avoid']),
                'shot_changes' => array_values((array) $background['shot_changes']),
                'assumptions' => array_values((array) $background['assumptions']),
                'conflicts' => $mine,
                'blocked' => $mine !== [],
                'continuity' => array_values(array_filter($continuity, static fn (array $entry): bool => in_array($id, (array) $entry['backgrounds'], true))),
                'script_continuity' => [],
                'prompt_parts' => match (true) {
                    $setDesign => self::setDesignParts($background, $place, $zone),
                    $dossier => self::dossierParts($background, $place, $zone),
                    default => self::promptParts($background, $place),
                },
                'order' => count($rows),
            ] + ($setDesign ? [
                'camera' => $background['camera'],
                'lighting_plan' => $background['lighting_plan'],
                'set' => $background['set'],
                'visible' => $place === null ? [] : MotoSetDesign::shown($place, $background),
            ] : []) + ($dossier ? [
                'subject_zone' => $zone,
                'landmarks' => array_values((array) $background['landmarks']),
                'conditions' => [
                    'time_of_day' => $background['time_of_day'],
                    'light_direction' => $background['light']['direction'],
                    'light_quality' => $background['light']['quality'],
                    'weather' => $background['weather'],
                    'atmosphere' => $background['atmosphere'],
                    'ambient_sound' => $background['ambient_sound'],
                ],
                'usability' => [
                    'verdict' => match (true) {
                        array_filter($read, static fn (array $finding): bool => $finding['verdict'] === MotoLocationsCheck::CONTRADICTS) !== [] => MotoLocationsCheck::CONTRADICTS,
                        $read !== [] => MotoLocationsCheck::UNVERIFIED,
                        $passed !== [] => MotoLocationsCheck::FITS,
                        default => null,
                    },
                    'lines' => array_values(array_column($read, 'message')),
                    'verified' => $passed,
                    'not_compared' => array_values((array) ($fit['skipped'][$id] ?? [])),
                ],
            ] : []);
        }

        $plan = [
            'version' => match (true) {
                $setDesign => self::SET_DESIGN_VERSION,
                $dossier => self::DOSSIER_VERSION,
                default => self::VERSION,
            },
            'locations' => array_values($places),
            'images' => $rows,
            'unplated' => array_map(static fn (array $entry): array => $entry + [
                'tour_stop' => null,
                'space' => is_string($entry['location']) ? ['kind' => 'location', 'id' => $entry['location'], 'state' => null] : null,
            ], array_values((array) $data['unplated'])),
            'unresolved' => [],
            'conflicts' => [...$blocking, ...array_map(self::conflict(...), $held)],
            'continuity' => $continuity,
            'script_continuity' => [],
        ];

        return $dossier ? $plan + ['connections' => array_values((array) $data['connections']), 'held' => $held, 'usability' => $usability] : $plan;
    }

    /**
     * @param  array<string, mixed>  $background  a plate of a set design dossier, as MotoSetDesign::normalize() gives it
     * @param  ?array<string, mixed>  $place  its place
     * @param  ?array<string, mixed>  $zone  the subject zone the frame leaves empty
     * @return list<array{key: string, text: string, source: string}> what the still image must show, each part joined by code from the structured source and told once: the place and its size, its surfaces and objects as they stand in the state of this plate, what marks the place, what the frame shows, the space left for the subject, what holds the subject, what differs from the base state, the purpose of the frame, its camera and framing, the conditions of the use and its light — the sources, where the sources stand that the frame must not show, the bright and dark areas and how the subject stands against the frame. The ambient sound never enters a still image
     */
    private static function setDesignParts(array $background, ?array $place, ?array $zone): array
    {
        $parts = [];
        $add = static function (string $key, mixed $text, string $source) use (&$parts): void {
            if (is_string($text) && trim($text) !== '') {
                $parts[] = ['key' => $key, 'text' => $text, 'source' => $source];
            }
        };
        $plate = (string) $background['id'];
        $state = $background['set'];
        $light = MotoSetDesign::light((array) $background['lighting_plan']);

        if ($place !== null && is_array($state)) {
            $id = (string) $place['id'];
            $add('space', $place['space'], $id);
            $add('setting', $place['setting'], $id);
            $add('dimensions', self::size((array) $place['dimensions']), $id);
            $add('surfaces', MotoSetDesign::surfaces($state), $id);
            $add('equipment', MotoSetDesign::objects($state, false), $id);
            $add('identity', implode('; ', (array) $place['set_design']['identity_rules']), $id);
            $add('in_frame', implode('; ', MotoSetDesign::shown($place, $background)), $plate);
            $add('subject_space', $zone === null ? '' : (string) $zone['position'], $zone === null ? $plate : "{$id}.{$zone['id']}");
            $add('support', MotoSetDesign::objects($state, true), $plate);
            $add('set_state', MotoSetDesign::changes($state), $plate);
        }

        $add('ground', $background['ground'], $plate);
        $add('composition', $background['composition'], $plate);
        $add('camera', MotoSetDesign::camera((array) $background['camera']), $plate);
        $add('framing', $background['camera']['framing'], $plate);
        $add('time', $background['time_of_day'], $plate);
        $add('light', $light['sources'], $plate);
        $add('light_hidden', $light['hidden'], $plate);
        $add('bright', $light['bright'], $plate);
        $add('dark', $light['dark'], $plate);
        $add('exposure', $light['exposure'], $plate);
        $add('weather', $background['weather'], $plate);
        $add('atmosphere', $background['atmosphere'], $plate);
        $add('avoid', implode(', ', (array) $background['avoid']), $plate);

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $background  a plate of a location dossier
     * @param  ?array<string, mixed>  $place  its place
     * @param  ?array<string, mixed>  $zone  the subject zone the frame leaves empty
     * @return list<array{key: string, text: string, source: string}> what the still image must show, each part with the record it comes from: the fixed description of the place with its setting and layers, then what this plate adds — the landmarks in frame, the space left for the subject, the temporary equipment, the composition and the conditions of the use. Limits of access and movement stay in the hand-off to the shot step, and the ambient sound never enters a still image
     */
    private static function dossierParts(array $background, ?array $place, ?array $zone): array
    {
        $parts = [];
        $add = static function (string $key, mixed $text, string $source) use (&$parts): void {
            if (is_string($text) && trim($text) !== '') {
                $parts[] = ['key' => $key, 'text' => $text, 'source' => $source];
            }
        };
        $plate = (string) $background['id'];

        if ($place !== null) {
            $id = (string) $place['id'];
            $add('space', $place['space'], $id);
            $add('setting', $place['setting'], $id);
            $add('dimensions', self::size((array) $place['dimensions']), $id);
            $add('surfaces', implode(', ', (array) $place['surfaces']), $id);
            $add('equipment', implode(', ', (array) $place['fixed_equipment']), $id);
            $add('palette', implode(', ', (array) $place['palette']), $id);
            $add('foreground', $place['spatial_layers']['foreground'], $id);
            $add('midground', $place['spatial_layers']['midground'], $id);
            $add('background', $place['spatial_layers']['background'], $id);
        }

        $add('landmarks', implode('; ', (array) $background['landmarks']), $plate);
        $add('subject_space', $zone === null ? '' : (string) $zone['position'], $zone === null ? $plate : (string) ($place['id'] ?? $plate).'.'.$zone['id']);
        $add('support', implode(', ', array_map(static fn (string $item): string => str_replace('_', ' ', $item), (array) $background['support'])), $plate);
        $add('ground', $background['ground'], $plate);
        $add('props', implode('; ', array_column((array) $background['details'], 'text')), $plate);
        $add('composition', $background['composition'], $plate);
        $add('time', $background['time_of_day'], $plate);
        $add('light', $background['light']['description'], $plate);
        $add('light_direction', $background['light']['direction'], $plate);
        $add('light_quality', $background['light']['quality'], $plate);
        $add('weather', $background['weather'], $plate);
        $add('atmosphere', $background['atmosphere'], $plate);
        $add('must_show', implode('; ', (array) $background['must_show']), $plate);
        $add('avoid', implode(', ', (array) $background['avoid']), $plate);

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $background
     * @param  ?array<string, mixed>  $place
     * @return list<array{key: string, text: string, source: string}> the place's fixed description once, then what this plate adds to it, each part with the record it comes from
     */
    private static function promptParts(array $background, ?array $place): array
    {
        $parts = [];
        $add = static function (string $key, string $text, string $source) use (&$parts): void {
            if (trim($text) !== '') {
                $parts[] = ['key' => $key, 'text' => $text, 'source' => $source];
            }
        };
        $plate = (string) $background['id'];

        if ($place !== null) {
            $add('space', (string) $place['space'], (string) $place['id']);
            $add('dimensions', self::size((array) $place['dimensions']), (string) $place['id']);
            $add('surfaces', implode(', ', (array) $place['surfaces']), (string) $place['id']);
            $add('equipment', implode(', ', (array) $place['fixed_equipment']), (string) $place['id']);
            $add('palette', implode(', ', (array) $place['palette']), (string) $place['id']);
        }

        $add('support', implode(', ', array_map(static fn (string $item): string => str_replace('_', ' ', $item), (array) $background['support'])), $plate);
        $add('ground', (string) ($background['ground'] ?? ''), $plate);
        $add('props', implode('; ', array_column((array) $background['details'], 'text')), $plate);
        $add('composition', (string) $background['composition'], $plate);
        $add('light', (string) $background['light']['description'], $plate);
        $add('must_show', implode('; ', (array) $background['must_show']), $plate);
        $add('avoid', implode('; ', (array) $background['avoid']), $plate);

        return $parts;
    }

    /** @param array<string, array{value: int|float|null, basis: string}> $dimensions */
    private static function size(array $dimensions): string
    {
        $words = ['length_m' => 'long', 'width_m' => 'wide', 'height_m' => 'high'];
        $known = [];

        foreach ($words as $axis => $word) {
            $value = $dimensions[$axis]['value'] ?? null;

            if (is_numeric($value)) {
                $known[] = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.')." m {$word}";
            }
        }

        return implode(', ', $known);
    }

    /**
     * @param  array<string, mixed>  $conflict
     * @return array<string, mixed> a blocking conflict with the beat, plate, field and the design and script evidence the Locations screen shows: the beat's own text and situation are the script's, every other path the design's
     */
    private static function conflict(array $conflict): array
    {
        $script = static fn (array $evidence): bool => preg_match('/^beats\.B\d+\.(location|time_light|visual|purpose|link|situation\.)/', $evidence['path']) === 1;
        $shown = static fn (bool $fromScript): string => implode(' / ', array_map(
            static fn (array $evidence): string => array_key_exists('quote', $evidence) ? "{$evidence['path']}: {$evidence['quote']}" : "{$evidence['path']} = ".json_encode($evidence['value'], JSON_UNESCAPED_UNICODE),
            array_values(array_filter((array) $conflict['evidence'], static fn (array $evidence): bool => $script($evidence) === $fromScript)),
        ));

        return $conflict + [
            'beat' => $conflict['beats'] === [] ? null : implode(', ', $conflict['beats']),
            'background' => $conflict['backgrounds'] === [] ? null : implode(', ', $conflict['backgrounds']),
            'field' => (string) $conflict['kind'],
            'design' => $shown(false) !== '' ? $shown(false) : '—',
            'script' => $shown(true) !== '' ? $shown(true) : '—',
        ];
    }
}
