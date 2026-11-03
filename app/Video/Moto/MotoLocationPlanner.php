<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoLocationPlanner
{
    public const VERSION = 'moto-locations-plan-v1';

    /** @var list<string> the kinds of place rendered in the style of open ground */
    private const OPEN_KINDS = ['yard', 'road'];

    /**
     * @param  array<string, mixed>  $document  a moto Phase 5 document that passed its schema
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return array{version: string, locations: list<array<string, mixed>>, images: list<array<string, mixed>>, unplated: list<array<string, mixed>>, unresolved: list<array<string, mixed>>, conflicts: list<array<string, mixed>>, continuity: list<array<string, mixed>>, script_continuity: list<array<string, mixed>>} the places of the answer, then every plate in the order the script first uses it, each with the parts its prompt is built from; only a blocking conflict holds a plate back, in the shape the Locations screen and the shot hand-off read
     */
    public static function plan(array $document, array $context): array
    {
        $data = (array) $document['data'];
        $places = array_column(array_values((array) $data['locations']), null, 'id');
        $position = array_flip(array_column((array) $context['beats'], 'id'));
        $blocking = array_values(array_map(self::conflict(...), array_filter((array) $data['conflicts'], static fn (array $conflict): bool => (bool) $conflict['blocking'])));
        $continuity = array_values((array) $data['continuity']);
        $backgrounds = array_values((array) $data['backgrounds']);
        $first = static fn (array $background): int => min(array_map(static fn (array $use): int => $position[$use['beat']] ?? PHP_INT_MAX, (array) $background['uses']));
        usort($backgrounds, static fn (array $a, array $b): int => $first($a) <=> $first($b));
        $rows = [];

        foreach ($backgrounds as $background) {
            $id = (string) $background['id'];
            $beats = array_values(array_unique(array_column((array) $background['uses'], 'beat')));
            $mine = array_values(array_filter($blocking, static fn (array $conflict): bool => in_array($id, $conflict['backgrounds'], true) || array_intersect($beats, $conflict['beats']) !== []));
            $place = $places[$background['location']] ?? null;
            $rows[] = [
                'id' => $id,
                'name' => (string) $background['name'],
                'kind' => 'location',
                'style' => in_array($place['kind'] ?? null, self::OPEN_KINDS, true) ? 'open_ground' : 'location',
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
                'prompt_parts' => self::promptParts($background, $place),
                'order' => count($rows),
            ];
        }

        return [
            'version' => self::VERSION,
            'locations' => array_values($places),
            'images' => $rows,
            'unplated' => array_map(static fn (array $entry): array => $entry + [
                'tour_stop' => null,
                'space' => is_string($entry['location']) ? ['kind' => 'location', 'id' => $entry['location'], 'state' => null] : null,
            ], array_values((array) $data['unplated'])),
            'unresolved' => [],
            'conflicts' => $blocking,
            'continuity' => $continuity,
            'script_continuity' => [],
        ];
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
